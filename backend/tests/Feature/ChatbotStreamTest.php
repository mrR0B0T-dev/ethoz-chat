<?php

namespace Tests\Feature;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledge;
use App\Models\ChatbotMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotStreamTest extends TestCase
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

    /** Aliran SSE tiruan, persis bentuk yang dikirim Anthropic. */
    protected function fakeStream(array $pieces = ['Cuti tahunan ', '12 hari kerja.']): void
    {
        $lines = [
            'event: message_start',
            'data: '.json_encode([
                'type' => 'message_start',
                'message' => [
                    'model' => 'claude-haiku-4-5-20251001',
                    'usage' => ['input_tokens' => 1200],
                ],
            ]),
            '',
        ];

        foreach ($pieces as $piece) {
            $lines[] = 'event: content_block_delta';
            $lines[] = 'data: '.json_encode([
                'type' => 'content_block_delta',
                'delta' => ['type' => 'text_delta', 'text' => $piece],
            ]);
            $lines[] = '';
        }

        $lines[] = 'event: message_delta';
        $lines[] = 'data: '.json_encode(['type' => 'message_delta', 'usage' => ['output_tokens' => 80]]);
        $lines[] = '';
        $lines[] = 'event: message_stop';
        $lines[] = 'data: '.json_encode(['type' => 'message_stop']);
        $lines[] = '';

        Http::fake([
            'api.anthropic.com/*' => Http::response(
                implode("\n", $lines),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
    }

    protected function ask(User $user, string $question, ?int $conversationId = null)
    {
        return $this->actingAs($user)->post('/api/chatbot/stream', array_filter([
            'messages' => [['role' => 'user', 'content' => $question]],
            'conversation_id' => $conversationId,
        ]));
    }

    public function test_jawaban_dikirim_potong_demi_potong(): void
    {
        $this->fakeStream();
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari kerja.', 'scope' => 'all']);

        $response = $this->ask(User::factory()->create(), 'Berapa hak cuti tahunan?');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();

        // Klien menerima penanda awal, potongan teks, lalu penutup.
        $this->assertStringContainsString('event: meta', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('Cuti tahunan ', $body);
        $this->assertStringContainsString('12 hari kerja.', $body);
        $this->assertStringContainsString('event: done', $body);
    }

    public function test_id_pesan_dikirim_di_awal_agar_penilaian_langsung_aktif(): void
    {
        $this->fakeStream();

        $body = $this->ask(User::factory()->create(), 'Halo')->streamedContent();
        $answer = ChatbotMessage::assistant()->firstOrFail();

        // "meta" mendahului potongan teks pertama.
        $this->assertLessThan(strpos($body, 'event: delta'), strpos($body, 'event: meta'));
        $this->assertStringContainsString('"message_id":'.$answer->id, $body);
    }

    public function test_jawaban_utuh_dan_metriknya_tersimpan(): void
    {
        $this->fakeStream();
        ChatbotKnowledge::create(['title' => 'Panduan Cuti', 'content' => 'Cuti tahunan 12 hari kerja.', 'scope' => 'all']);

        $this->ask(User::factory()->create(), 'Berapa hak cuti tahunan?')->streamedContent();

        $answer = ChatbotMessage::assistant()->firstOrFail();
        $this->assertSame('Cuti tahunan 12 hari kerja.', $answer->content);
        $this->assertSame(ChatbotMessage::ANSWERED, $answer->outcome);
        $this->assertSame(1200, $answer->input_tokens);
        $this->assertSame(80, $answer->output_tokens);
        $this->assertNotNull($answer->latency_ms);
        $this->assertContains('Panduan Cuti', $answer->sources);
    }

    public function test_potongan_yang_terbelah_antar_paket_tetap_utuh(): void
    {
        // Pembacaan aliran memakai penyangga; baris yang terpotong di tengah
        // paket jaringan tidak boleh hilang atau menggandakan teks.
        $this->fakeStream(['Bagian satu. ', 'Bagian dua. ', 'Bagian tiga.']);

        $this->ask(User::factory()->create(), 'Halo')->streamedContent();

        $this->assertSame(
            'Bagian satu. Bagian dua. Bagian tiga.',
            ChatbotMessage::assistant()->firstOrFail()->content,
        );
    }

    public function test_percakapan_berlanjut_saat_dialirkan(): void
    {
        $this->fakeStream();
        $user = User::factory()->create();

        $this->ask($user, 'Pertanyaan pertama')->streamedContent();
        $id = ChatbotConversation::firstOrFail()->id;

        $this->ask($user, 'Pertanyaan kedua', $id)->streamedContent();

        $this->assertSame(1, ChatbotConversation::count());
        $this->assertSame(4, ChatbotMessage::count());
    }

    public function test_kegagalan_upstream_dibalas_pesan_cadangan(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

        $body = $this->ask(User::factory()->create(), 'Halo')->streamedContent();

        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('Maaf, asisten sedang sibuk', $body);

        $answer = ChatbotMessage::assistant()->firstOrFail();
        $this->assertSame(ChatbotMessage::FALLBACK, $answer->outcome);
        $this->assertSame('Maaf, asisten sedang sibuk. Coba lagi sebentar.', $answer->content);
    }

    public function test_tanpa_kunci_api_tidak_membuka_aliran(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        $this->ask(User::factory()->create(), 'Halo')->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_tamu_tidak_bisa_mengalirkan(): void
    {
        $this->postJson('/api/chatbot/stream', ['messages' => [['role' => 'user', 'content' => 'Halo']]])
            ->assertUnauthorized();
    }

    // ── Riwayat milik pegawai ────────────────────────────────────

    public function test_pegawai_melihat_daftar_percakapannya_sendiri(): void
    {
        $this->fakeStream();
        $user = User::factory()->create();
        $orangLain = User::factory()->create();

        $this->ask($user, 'Punya saya')->streamedContent();
        $this->ask($orangLain, 'Punya orang lain')->streamedContent();

        $rows = $this->actingAs($user)->getJson('/api/chatbot/conversations')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Punya saya', $rows[0]['title']);
    }

    public function test_percakapan_orang_lain_tidak_bisa_dibuka(): void
    {
        $this->fakeStream();
        $this->ask(User::factory()->create(), 'Rahasia')->streamedContent();
        $id = ChatbotConversation::firstOrFail()->id;

        $this->actingAs(User::factory()->create())
            ->getJson("/api/chatbot/conversations/$id")
            ->assertForbidden();
    }

    public function test_membuka_riwayat_mengembalikan_isi_percakapan(): void
    {
        $this->fakeStream();
        $user = User::factory()->create();
        $this->ask($user, 'Berapa hak cuti?')->streamedContent();
        $id = ChatbotConversation::firstOrFail()->id;

        $this->actingAs($user)->getJson("/api/chatbot/conversations/$id")
            ->assertOk()
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'Berapa hak cuti?')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.content', 'Cuti tahunan 12 hari kerja.');
    }
}
