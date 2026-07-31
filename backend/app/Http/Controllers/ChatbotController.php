<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\ChatbotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    /** Balasan cadangan saat asisten tidak bisa dihubungi. */
    private const FALLBACK_REPLY = 'Maaf, asisten sedang sibuk. Coba lagi sebentar.';

    public function send(Request $request, ChatbotService $service)
    {
        $data = $request->validate([
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer',
        ]);

        $messages = $this->normalize($data['messages']);

        if ($messages === []) {
            return response()->json(['reply' => 'Silakan tulis pertanyaan Anda terlebih dahulu.']);
        }

        $question = (string) end($messages)['content'];
        $conversation = $this->conversationFor($request, $question);

        $this->record($conversation, 'user', $question);

        $context = $service->build($request->user(), $question);
        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            Log::error('Chatbot: ANTHROPIC_API_KEY belum diisi di .env.');

            return $this->fallback($conversation, 'missing_api_key');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 1000,
                'system' => $context['prompt'],
                'messages' => $messages,
            ]);
        } catch (ConnectionException $e) {
            // Timeout / DNS / jaringan mati. Tanpa catch ini request berakhir 500.
            Log::error('Chatbot: gagal menghubungi Anthropic.', ['error' => $e->getMessage()]);

            return $this->fallback($conversation, 'connection_failed');
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            // Kegagalan di sini dulu ditelan tanpa jejak, sehingga penyebab
            // sebenarnya (kunci salah, saldo habis, model keliru) tidak terlihat.
            Log::error('Chatbot: Anthropic membalas error.', [
                'status' => $response->status(),
                'type' => $response->json('error.type'),
                'message' => $response->json('error.message'),
                'request_id' => $response->header('request-id'),
            ]);

            return $this->fallback(
                $conversation,
                $response->json('error.type') ?: 'upstream_error',
                $response->json('error.message'),
                $latency,
            );
        }

        $reply = collect($response->json('content'))
            ->where('type', 'text')->pluck('text')->implode("\n");
        $reply = trim($reply) ?: 'Maaf, saya belum bisa memproses itu.';

        $message = $this->record($conversation, 'assistant', $reply, [
            'outcome' => $context['matched'] ? ChatbotMessage::ANSWERED : ChatbotMessage::NO_CONTEXT,
            'latency_ms' => $latency,
            'input_tokens' => $response->json('usage.input_tokens'),
            'output_tokens' => $response->json('usage.output_tokens'),
            'model' => $response->json('model') ?: config('services.anthropic.model'),
            'sources' => $context['sources'],
        ]);

        return response()->json([
            'reply' => $reply,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'sources' => $context['sources'],
        ]);
    }

    /**
     * Jawaban mengalir (Server-Sent Events).
     *
     * Pegawai melihat teks tumbuh kata demi kata, bukan layar kosong hingga
     * 60 detik. Baris jawaban dibuat lebih dulu agar id-nya bisa dikirim di
     * awal — tombol penilaian sudah aktif sebelum jawaban selesai.
     */
    public function stream(Request $request, ChatbotService $service)
    {
        $data = $request->validate([
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer',
        ]);

        $messages = $this->normalize($data['messages']);

        if ($messages === []) {
            return response()->json(['reply' => 'Silakan tulis pertanyaan Anda terlebih dahulu.'], 422);
        }

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            Log::error('Chatbot: ANTHROPIC_API_KEY belum diisi di .env.');

            return response()->json(['reply' => self::FALLBACK_REPLY], 503);
        }

        $question = (string) end($messages)['content'];
        $conversation = $this->conversationFor($request, $question);
        $this->record($conversation, 'user', $question);

        $context = $service->build($request->user(), $question);
        $answer = $this->record($conversation, 'assistant', '', ['sources' => $context['sources']]);

        return response()->stream(
            fn () => $this->relay($apiKey, $messages, $context, $conversation, $answer),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                // Cegah Nginx menahan potongan di buffer — tanpa ini alirannya
                // sampai sekaligus di akhir dan efek mengalirnya hilang.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /** Teruskan aliran dari Anthropic ke klien, lalu simpan hasil + metrik. */
    protected function relay(string $apiKey, array $messages, array $context, ChatbotConversation $conversation, ChatbotMessage $answer): void
    {
        $this->emit('meta', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
            'sources' => $context['sources'],
        ]);

        $startedAt = microtime(true);
        $text = '';
        $inputTokens = null;
        $outputTokens = null;
        $model = config('services.anthropic.model');
        $failed = false;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->withOptions(['stream' => true])->timeout(120)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 1000,
                    'system' => $context['prompt'],
                    'messages' => $messages,
                    'stream' => true,
                ]);

            if ($response->failed()) {
                Log::error('Chatbot(stream): Anthropic membalas error.', [
                    'status' => $response->status(),
                ]);
                $failed = true;
            } else {
                $body = $response->toPsrResponse()->getBody();
                $buffer = '';

                while (! $body->eof()) {
                    $buffer .= $body->read(2048);

                    // Sisakan penggalan terakhir yang mungkin belum utuh.
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (! str_starts_with($line, 'data:')) {
                            continue;
                        }

                        $event = json_decode(trim(substr($line, 5)), true);
                        if (! is_array($event)) {
                            continue;
                        }

                        $type = $event['type'] ?? '';

                        if ($type === 'content_block_delta' && isset($event['delta']['text'])) {
                            $piece = (string) $event['delta']['text'];
                            $text .= $piece;
                            $this->emit('delta', ['text' => $piece]);
                        } elseif ($type === 'message_start') {
                            $inputTokens = $event['message']['usage']['input_tokens'] ?? null;
                            $model = $event['message']['model'] ?? $model;
                        } elseif ($type === 'message_delta') {
                            $outputTokens = $event['usage']['output_tokens'] ?? $outputTokens;
                        } elseif ($type === 'error') {
                            Log::error('Chatbot(stream): galat di tengah aliran.', ['event' => $event]);
                            $failed = true;
                        }
                    }
                }
            }
        } catch (ConnectionException $e) {
            Log::error('Chatbot(stream): gagal menghubungi Anthropic.', ['error' => $e->getMessage()]);
            $failed = true;
        } catch (\Throwable $e) {
            Log::error('Chatbot(stream): aliran terputus.', ['error' => $e->getMessage()]);
            $failed = true;
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $text = trim($text);

        // Aliran yang gagal SEBELUM ada teks dibalas pesan cadangan; bila teks
        // sudah sebagian tampil, biarkan apa adanya agar tidak menghilang.
        if ($text === '') {
            $text = self::FALLBACK_REPLY;
            $failed = true;
        }

        $answer->update([
            'content' => $text,
            'outcome' => $failed
                ? ChatbotMessage::FALLBACK
                : ($context['matched'] ? ChatbotMessage::ANSWERED : ChatbotMessage::NO_CONTEXT),
            'latency_ms' => $latency,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $model,
        ]);

        if ($failed) {
            $this->emit('error', ['reply' => $text]);
        }

        $this->emit('done', ['message_id' => $answer->id, 'latency_ms' => $latency]);
    }

    /** Kirim satu peristiwa SSE dan dorong keluar dari buffer. */
    protected function emit(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /** Riwayat percakapan milik pegawai sendiri — untuk daftar di aplikasi. */
    public function conversations(Request $request)
    {
        return response()->json(
            ChatbotConversation::where('user_id', $request->user()->id)
                ->latest('last_message_at')
                ->limit(50)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'message_count' => $c->message_count,
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                ])
        );
    }

    /** Isi satu percakapan milik sendiri — dipakai saat membuka riwayat. */
    public function conversation(Request $request, ChatbotConversation $conversation)
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $conversation->messages()->orderBy('id')->get()
                ->filter(fn ($m) => trim((string) $m->content) !== '')
                ->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'feedback' => $m->feedback,
                ]),
        ]);
    }

    /** Umpan balik pegawai atas satu jawaban — bahan utama pemantauan mutu. */
    public function feedback(Request $request, ChatbotMessage $message)
    {
        $data = $request->validate([
            'feedback' => 'required|in:up,down',
        ]);

        // Hanya pemilik percakapan yang boleh menilai jawabannya.
        abort_unless(
            $message->conversation && $message->conversation->user_id === $request->user()->id,
            403,
        );

        abort_unless($message->role === 'assistant', 422, 'Hanya jawaban asisten yang bisa dinilai.');

        $message->update(['feedback' => $data['feedback']]);

        return response()->json(['ok' => true, 'feedback' => $message->feedback]);
    }

    /**
     * Rapikan riwayat sebelum dikirim ke model.
     *
     * Anthropic menolak daftar kosong dan mensyaratkan giliran diawali user,
     * jadi keduanya dipastikan di satu tempat agar `send` dan `stream` sama.
     *
     * @return list<array{role:string, content:string}>
     */
    protected function normalize(array $messages): array
    {
        $messages = collect($messages)
            ->skipUntil(fn ($m) => $m['role'] === 'user')
            ->values()->all();

        $maxTurns = max(2, (int) config('chatbot.history.max_turns'));

        if (count($messages) > $maxTurns) {
            $messages = array_slice($messages, -$maxTurns);
            // Potongan bisa jatuh pada giliran assistant; rapikan lagi.
            $messages = collect($messages)->skipUntil(fn ($m) => $m['role'] === 'user')->values()->all();
        }

        return $messages;
    }

    /** Lanjutkan percakapan yang ada, atau mulai yang baru. */
    protected function conversationFor(Request $request, string $question): ChatbotConversation
    {
        $user = $request->user();
        $id = $request->integer('conversation_id');

        if ($id) {
            $existing = ChatbotConversation::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return ChatbotConversation::create([
            'user_id' => $user->id,
            'role' => $user->role ?? 'staff',
            'title' => ChatbotConversation::titleFrom($question),
        ]);
    }

    protected function record(ChatbotConversation $conversation, string $role, string $content, array $extra = []): ChatbotMessage
    {
        $message = $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
        ] + $extra);

        $conversation->forceFill([
            'message_count' => $conversation->messages()->count(),
            'last_message_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * Balasan cadangan. Detail teknis hanya disertakan saat APP_DEBUG=true agar
     * pesan galat upstream tidak bocor ke pengguna di produksi.
     */
    private function fallback(
        ChatbotConversation $conversation,
        string $reason,
        ?string $detail = null,
        ?int $latency = null,
    ) {
        $message = $this->record($conversation, 'assistant', self::FALLBACK_REPLY, [
            'outcome' => ChatbotMessage::FALLBACK,
            'latency_ms' => $latency,
            'sources' => [],
        ]);

        $payload = [
            'reply' => self::FALLBACK_REPLY,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ];

        if (config('app.debug')) {
            $payload['debug'] = array_filter(['reason' => $reason, 'detail' => $detail]);
        }

        return response()->json($payload);
    }
}
