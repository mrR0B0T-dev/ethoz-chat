<?php

namespace App\Http\Controllers;

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
        ]);

        // Pastikan riwayat diawali giliran user
        $messages = collect($data['messages'])
            ->skipUntil(fn ($m) => $m['role'] === 'user')
            ->values()->all();

        // skipUntil() bisa menyisakan array kosong bila riwayat tidak memuat
        // giliran user sama sekali — Anthropic menolak messages kosong.
        if ($messages === []) {
            return response()->json(['reply' => 'Silakan tulis pertanyaan Anda terlebih dahulu.']);
        }

        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            Log::error('Chatbot: ANTHROPIC_API_KEY belum diisi di .env.');

            return $this->fallback('missing_api_key');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 1000,
                'system' => $service->buildSystemPrompt($request->user()),
                'messages' => $messages,
            ]);
        } catch (ConnectionException $e) {
            // Timeout / DNS / jaringan mati. Tanpa catch ini request berakhir 500.
            Log::error('Chatbot: gagal menghubungi Anthropic.', ['error' => $e->getMessage()]);

            return $this->fallback('connection_failed');
        }

        if ($response->failed()) {
            // Sebelumnya kegagalan di sini ditelan tanpa jejak, sehingga penyebab
            // sebenarnya (kunci salah, saldo habis, model keliru) tidak terlihat.
            Log::error('Chatbot: Anthropic membalas error.', [
                'status' => $response->status(),
                'type' => $response->json('error.type'),
                'message' => $response->json('error.message'),
                'request_id' => $response->header('request-id'),
            ]);

            return $this->fallback(
                $response->json('error.type') ?: 'upstream_error',
                $response->json('error.message'),
            );
        }

        $reply = collect($response->json('content'))
            ->where('type', 'text')->pluck('text')->implode("\n");

        return response()->json(['reply' => trim($reply) ?: 'Maaf, saya belum bisa memproses itu.']);
    }

    /**
     * Balasan cadangan. Detail teknis hanya disertakan saat APP_DEBUG=true agar
     * pesan galat upstream tidak bocor ke pengguna di produksi.
     */
    private function fallback(string $reason, ?string $detail = null)
    {
        $payload = ['reply' => self::FALLBACK_REPLY];

        if (config('app.debug')) {
            $payload['debug'] = array_filter(['reason' => $reason, 'detail' => $detail]);
        }

        return response()->json($payload);
    }
}
