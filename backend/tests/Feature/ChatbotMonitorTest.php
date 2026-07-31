<?php

namespace Tests\Feature;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledge;
use App\Models\ChatbotMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.key' => 'sk-ant-test',
            'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    protected function fakeClaude(string $text = 'Cuti tahunan Anda 12 hari.'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-haiku-4-5-20251001',
                'content' => [['type' => 'text', 'text' => $text]],
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
            ]),
        ]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'hr']);
    }

    protected function ask(User $user, string $question, ?int $conversationId = null)
    {
        return $this->actingAs($user)->postJson('/api/chatbot/send', array_filter([
            'messages' => [['role' => 'user', 'content' => $question]],
            'conversation_id' => $conversationId,
        ]));
    }

    // ── Perekaman percakapan ─────────────────────────────────────

    public function test_percakapan_dan_metrik_tersimpan(): void
    {
        $this->fakeClaude();
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari kerja.', 'scope' => 'all']);

        $user = User::factory()->create(['role' => 'staff']);
        $response = $this->ask($user, 'Berapa hak cuti tahunan?')->assertOk();

        $conversation = ChatbotConversation::firstOrFail();
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertSame('Berapa hak cuti tahunan?', $conversation->title);
        $this->assertSame(2, $conversation->message_count);
        $response->assertJsonPath('conversation_id', $conversation->id);

        $answer = ChatbotMessage::assistant()->firstOrFail();
        $this->assertSame(ChatbotMessage::ANSWERED, $answer->outcome);
        $this->assertSame(1200, $answer->input_tokens);
        $this->assertSame(80, $answer->output_tokens);
        $this->assertNotNull($answer->latency_ms);
        $this->assertContains('Panduan Cuti', $answer->sources);
    }

    public function test_percakapan_berlanjut_pada_id_yang_sama(): void
    {
        $this->fakeClaude();
        $user = User::factory()->create();

        $first = $this->ask($user, 'Pertanyaan pertama')->json('conversation_id');
        $this->ask($user, 'Pertanyaan kedua', $first)->assertJsonPath('conversation_id', $first);

        $this->assertSame(1, ChatbotConversation::count());
        $this->assertSame(4, ChatbotMessage::count());
    }

    public function test_tidak_bisa_menyambung_percakapan_milik_orang_lain(): void
    {
        $this->fakeClaude();
        $orang = User::factory()->create();
        $milikOrangLain = $this->ask($orang, 'Rahasia saya')->json('conversation_id');

        $penyusup = User::factory()->create();
        $baru = $this->ask($penyusup, 'Halo', $milikOrangLain)->json('conversation_id');

        // Diberi percakapan baru, bukan menempel ke milik orang lain.
        $this->assertNotSame($milikOrangLain, $baru);
    }

    public function test_pertanyaan_tanpa_pengetahuan_ditandai_sebagai_celah(): void
    {
        $this->fakeClaude('Maaf, informasinya belum tersedia.');
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari.', 'scope' => 'all']);

        $this->ask(User::factory()->create(), 'Berapa harga saham perusahaan?')->assertOk();

        $this->assertSame(ChatbotMessage::NO_CONTEXT, ChatbotMessage::assistant()->firstOrFail()->outcome);
    }

    public function test_kegagalan_api_terekam_sebagai_fallback(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

        $this->ask(User::factory()->create(), 'Halo')->assertOk();

        $this->assertSame(ChatbotMessage::FALLBACK, ChatbotMessage::assistant()->firstOrFail()->outcome);
    }

    // ── Umpan balik ──────────────────────────────────────────────

    public function test_pegawai_bisa_menilai_jawaban(): void
    {
        $this->fakeClaude();
        $user = User::factory()->create();
        $messageId = $this->ask($user, 'Berapa hak cuti?')->json('message_id');

        $this->actingAs($user)
            ->postJson("/api/chatbot/messages/$messageId/feedback", ['feedback' => 'down'])
            ->assertOk()
            ->assertJsonPath('feedback', 'down');

        $this->assertSame('down', ChatbotMessage::find($messageId)->feedback);
    }

    public function test_tidak_bisa_menilai_jawaban_milik_orang_lain(): void
    {
        $this->fakeClaude();
        $messageId = $this->ask(User::factory()->create(), 'Halo')->json('message_id');

        $this->actingAs(User::factory()->create())
            ->postJson("/api/chatbot/messages/$messageId/feedback", ['feedback' => 'up'])
            ->assertForbidden();
    }

    public function test_nilai_umpan_balik_divalidasi(): void
    {
        $this->fakeClaude();
        $user = User::factory()->create();
        $messageId = $this->ask($user, 'Halo')->json('message_id');

        $this->actingAs($user)
            ->postJson("/api/chatbot/messages/$messageId/feedback", ['feedback' => 'mantap'])
            ->assertJsonValidationErrors('feedback');
    }

    // ── Halaman pemantauan ───────────────────────────────────────

    public function test_metrik_merangkum_pemakaian(): void
    {
        $this->fakeClaude();
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari kerja.', 'scope' => 'all']);

        $user = User::factory()->create(['role' => 'staff']);
        $id = $this->ask($user, 'Berapa hak cuti tahunan?')->json('message_id');
        $this->actingAs($user)->postJson("/api/chatbot/messages/$id/feedback", ['feedback' => 'up']);
        $this->ask($user, 'Berapa harga saham perusahaan?');

        $metrics = $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/metrics')
            ->assertOk()
            ->json();

        $this->assertSame(2, $metrics['conversations']);
        $this->assertSame(2, $metrics['questions']);
        $this->assertSame(1, $metrics['outcomes']['answered']);
        $this->assertSame(1, $metrics['outcomes']['no_context']);
        $this->assertSame(1, $metrics['feedback']['up']);
        $this->assertSame(2400, $metrics['tokens']['input']);
        $this->assertGreaterThan(0, $metrics['tokens']['estimated_cost_usd']);
        $this->assertSame('Panduan Cuti', $metrics['top_sources'][0]['title']);
        $this->assertCount(1, $metrics['gaps']);
        $this->assertSame('Berapa harga saham perusahaan?', $metrics['gaps'][0]['question']);
    }

    public function test_deret_harian_menutup_seluruh_rentang(): void
    {
        $metrics = $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/metrics?days=7')
            ->assertOk()
            ->json();

        // Hari tanpa aktivitas tetap muncul sebagai nol agar grafik tidak bolong.
        $this->assertCount(7, $metrics['daily']);
        $this->assertSame(0, $metrics['daily'][0]['count']);
    }

    public function test_daftar_percakapan_bisa_disaring_ke_yang_bermasalah(): void
    {
        $this->fakeClaude();
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari kerja.', 'scope' => 'all']);

        $user = User::factory()->create();
        $this->ask($user, 'Berapa hak cuti tahunan?');       // lancar
        $this->ask($user, 'Berapa harga saham perusahaan?');  // celah

        $semua = $this->actingAs($this->admin())->getJson('/api/admin/chatbot/conversations')->json();
        $this->assertCount(2, $semua);

        $bermasalah = $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/conversations?problems=1')->json();
        $this->assertCount(1, $bermasalah);
        $this->assertSame('Berapa harga saham perusahaan?', $bermasalah[0]['title']);
    }

    public function test_transkrip_percakapan_bisa_dibuka_admin(): void
    {
        $this->fakeClaude('Cuti tahunan Anda 12 hari.');
        $user = User::factory()->create();
        $id = $this->ask($user, 'Berapa hak cuti?')->json('conversation_id');

        $this->actingAs($this->admin())
            ->getJson("/api/admin/chatbot/conversations/$id")
            ->assertOk()
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'Berapa hak cuti?')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.content', 'Cuti tahunan Anda 12 hari.');
    }

    public function test_pemantauan_tertutup_untuk_pegawai_biasa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->getJson('/api/admin/chatbot/metrics')
            ->assertForbidden();
    }

    public function test_pemantauan_tertutup_untuk_tamu(): void
    {
        $this->getJson('/api/admin/chatbot/metrics')->assertUnauthorized();
    }
}
