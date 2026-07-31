<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pemantauan kinerja chatbot.
 *
 * Menjawab pertanyaan yang sebelumnya tidak bisa dijawab sama sekali:
 * seberapa sering dipakai, secepat apa, seberapa sering gagal, berapa biayanya,
 * dan pertanyaan apa yang belum terjawab karena pengetahuannya belum ada.
 */
class ChatbotMonitorController extends Controller
{
    public function metrics(Request $request)
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $since = Carbon::today()->subDays($days - 1);

        $answers = ChatbotMessage::assistant()->where('created_at', '>=', $since);

        $totals = (clone $answers)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(latency_ms) as avg_latency')
            ->selectRaw('SUM(input_tokens) as input_tokens')
            ->selectRaw('SUM(output_tokens) as output_tokens')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as answered', [ChatbotMessage::ANSWERED])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as no_context', [ChatbotMessage::NO_CONTEXT])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as fallback', [ChatbotMessage::FALLBACK])
            ->selectRaw("SUM(CASE WHEN feedback = 'up' THEN 1 ELSE 0 END) as thumbs_up")
            ->selectRaw("SUM(CASE WHEN feedback = 'down' THEN 1 ELSE 0 END) as thumbs_down")
            ->first();

        $total = (int) ($totals->total ?? 0);
        $inputTokens = (int) ($totals->input_tokens ?? 0);
        $outputTokens = (int) ($totals->output_tokens ?? 0);

        // Median lebih jujur daripada rata-rata saat ada beberapa jawaban lambat.
        $latencies = (clone $answers)->whereNotNull('latency_ms')
            ->orderBy('latency_ms')->pluck('latency_ms')->all();

        return response()->json([
            'range_days' => $days,
            'since' => $since->toDateString(),

            'conversations' => ChatbotConversation::where('created_at', '>=', $since)->count(),
            'questions' => ChatbotMessage::where('role', 'user')->where('created_at', '>=', $since)->count(),
            'answers' => $total,

            'latency' => [
                'avg_ms' => (int) round((float) ($totals->avg_latency ?? 0)),
                'p50_ms' => $this->percentile($latencies, 0.50),
                'p95_ms' => $this->percentile($latencies, 0.95),
            ],

            'outcomes' => [
                'answered' => (int) ($totals->answered ?? 0),
                'no_context' => (int) ($totals->no_context ?? 0),
                'fallback' => (int) ($totals->fallback ?? 0),
            ],

            'feedback' => [
                'up' => (int) ($totals->thumbs_up ?? 0),
                'down' => (int) ($totals->thumbs_down ?? 0),
            ],

            'tokens' => [
                'input' => $inputTokens,
                'output' => $outputTokens,
                'estimated_cost_usd' => round(
                    $inputTokens / 1_000_000 * (float) config('chatbot.pricing.input_per_mtok')
                    + $outputTokens / 1_000_000 * (float) config('chatbot.pricing.output_per_mtok'),
                    4,
                ),
            ],

            'daily' => $this->daily($since, $days),
            'gaps' => $this->gaps($since),
            'top_sources' => $this->topSources($since),
        ]);
    }

    /** Volume harian — dipakai grafik batang di halaman pemantauan. */
    protected function daily(Carbon $since, int $days): array
    {
        $rows = ChatbotMessage::where('role', 'user')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as jumlah')
            ->groupBy('day')
            ->pluck('jumlah', 'day');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $out[] = ['day' => $date, 'count' => (int) ($rows[$date] ?? 0)];
        }

        return $out;
    }

    /**
     * Pertanyaan yang tidak menemukan pengetahuan apa pun.
     * Ini daftar kerja paling berguna bagi admin: celah basis pengetahuan.
     */
    protected function gaps(Carbon $since): array
    {
        return ChatbotMessage::assistant()
            ->where('created_at', '>=', $since)
            ->where('outcome', ChatbotMessage::NO_CONTEXT)
            ->with('conversation:id,title')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn ($m) => [
                'question' => $m->conversation?->title ?? '(tanpa judul)',
                'at' => $m->created_at?->toDateTimeString(),
                'feedback' => $m->feedback,
            ])
            ->all();
    }

    /** Dokumen yang paling sering menjadi rujukan jawaban. */
    protected function topSources(Carbon $since): array
    {
        $counts = [];

        ChatbotMessage::assistant()
            ->where('created_at', '>=', $since)
            ->whereNotNull('sources')
            ->pluck('sources')
            ->each(function ($sources) use (&$counts) {
                foreach ((array) $sources as $title) {
                    $counts[$title] = ($counts[$title] ?? 0) + 1;
                }
            });

        arsort($counts);

        return collect($counts)->take(10)
            ->map(fn ($n, $title) => ['title' => $title, 'count' => $n])
            ->values()->all();
    }

    /** @param list<int> $sorted */
    protected function percentile(array $sorted, float $p): int
    {
        if ($sorted === []) {
            return 0;
        }

        $index = (int) floor($p * (count($sorted) - 1));

        return (int) $sorted[$index];
    }

    public function conversations(Request $request)
    {
        $query = ChatbotConversation::query()
            ->with('user:id,name')
            ->withCount([
                'messages as fallback_count' => fn ($q) => $q->where('outcome', ChatbotMessage::FALLBACK),
                'messages as gap_count' => fn ($q) => $q->where('outcome', ChatbotMessage::NO_CONTEXT),
                'messages as down_count' => fn ($q) => $q->where('feedback', 'down'),
            ]);

        // Saring ke percakapan bermasalah saja — tempat perbaikan dimulai.
        if ($request->boolean('problems')) {
            $query->where(fn ($q) => $q
                ->whereHas('messages', fn ($m) => $m->where('outcome', ChatbotMessage::NO_CONTEXT))
                ->orWhereHas('messages', fn ($m) => $m->where('feedback', 'down'))
                ->orWhereHas('messages', fn ($m) => $m->where('outcome', ChatbotMessage::FALLBACK))
            );
        }

        return response()->json(
            $query->latest('last_message_at')->limit(50)->get()->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'user' => $c->user?->name ?? '(pengguna terhapus)',
                'role' => $c->role,
                'message_count' => $c->message_count,
                'last_message_at' => $c->last_message_at?->toDateTimeString(),
                'fallback_count' => $c->fallback_count,
                'gap_count' => $c->gap_count,
                'down_count' => $c->down_count,
            ])
        );
    }

    /** Transkrip lengkap satu percakapan. */
    public function conversation(ChatbotConversation $conversation)
    {
        $conversation->load('user:id,name');

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'user' => $conversation->user?->name ?? '(pengguna terhapus)',
            'role' => $conversation->role,
            'messages' => $conversation->messages()->orderBy('id')->get()->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'outcome' => $m->outcome,
                'latency_ms' => $m->latency_ms,
                'sources' => $m->sources ?? [],
                'feedback' => $m->feedback,
                'at' => $m->created_at?->toDateTimeString(),
            ]),
        ]);
    }
}
