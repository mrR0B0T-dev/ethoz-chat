<?php

namespace Tests\Feature;

use App\Models\ChatbotKnowledge;
use App\Models\ChatbotSetting;
use App\Models\User;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ChatbotAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'hr']);
    }

    public function test_pegawai_biasa_tidak_boleh_mengelola_chatbot(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->getJson('/api/admin/chatbot/settings')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/admin/chatbot/knowledge')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/admin/chatbot/documents')->assertForbidden();
    }

    public function test_tamu_tidak_boleh_mengelola_chatbot(): void
    {
        $this->getJson('/api/admin/chatbot/settings')->assertUnauthorized();
    }

    public function test_pengaturan_dibuat_otomatis_dengan_nilai_bawaan(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/settings')
            ->assertOk()
            ->assertJson([
                'bot_name' => 'Asisten Ethoz',
                'company' => 'PT BDP',
                'tone' => 'ramah',
                'language' => 'id',
            ]);

        $this->assertSame(1, ChatbotSetting::count());
    }

    public function test_admin_bisa_mengubah_pengaturan(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/admin/chatbot/settings', [
                'bot_name' => 'Asisten BDP',
                'company' => 'PT BDP',
                'tone' => 'santai',
                'address' => 'Kamu',
                'emoji' => true,
                'allow_bullets' => false,
                'no_hallucination' => true,
                'protect_sensitive' => true,
                'max_length' => 'detail',
                'language' => 'follow',
                'blocked_topics' => 'politik',
            ])
            ->assertOk()
            ->assertJson(['bot_name' => 'Asisten BDP', 'tone' => 'santai']);

        $this->assertSame(1, ChatbotSetting::count());
        $this->assertSame('Kamu', ChatbotSetting::current()->address);
    }

    public function test_pengaturan_menolak_nilai_di_luar_pilihan(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/admin/chatbot/settings', [
                'bot_name' => 'Bot',
                'company' => 'PT BDP',
                'tone' => 'galak',
                'address' => 'Anda',
                'max_length' => 'sedang',
                'language' => 'id',
            ])
            ->assertJsonValidationErrors('tone');
    }

    public function test_crud_basis_pengetahuan(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin)
            ->postJson('/api/admin/chatbot/knowledge', [
                'title' => 'Jatah Cuti',
                'content' => 'Cuti tahunan 12 hari.',
                'scope' => 'all',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('manual', $created['source']);

        $this->actingAs($admin)->getJson('/api/admin/chatbot/knowledge')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['title' => 'Jatah Cuti']);

        $this->actingAs($admin)
            ->putJson("/api/admin/chatbot/knowledge/{$created['id']}", [
                'title' => 'Jatah Cuti 2026',
                'content' => 'Cuti tahunan 14 hari.',
                'scope' => 'hr_manager',
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('chatbot_knowledge', [
            'id' => $created['id'],
            'title' => 'Jatah Cuti 2026',
            'scope' => 'hr_manager',
            'is_active' => false,
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/chatbot/knowledge/{$created['id']}")->assertNoContent();
        $this->assertDatabaseCount('chatbot_knowledge', 0);
    }

    public function test_pengetahuan_menolak_scope_tak_dikenal(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/chatbot/knowledge', [
                'title' => 'Uji',
                'content' => 'Isi',
                'scope' => 'direksi',
            ])
            ->assertJsonValidationErrors('scope');
    }

    public function test_unggah_dokumen_teks_menjadi_basis_pengetahuan(): void
    {
        $isi = "Kebijakan Cuti\nPengajuan cuti minimal 3 hari sebelumnya.";

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('kebijakan-cuti.txt', $isi),
                'scope' => 'all',
            ])
            ->assertCreated()
            ->assertJson([
                'title' => 'kebijakan-cuti',
                'content' => $isi,
                'scope' => 'all',
                'source' => 'document',
                'file_name' => 'kebijakan-cuti.txt',
                'char_count' => mb_strlen($isi),
                'is_active' => true,
            ]);

        $this->assertDatabaseCount('chatbot_knowledge', 1);
    }

    public function test_unggah_dokumen_kosong_ditolak(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('kosong.txt', '   '),
                'scope' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Teks tidak terbaca dari dokumen (mungkin PDF hasil scan). Coba OCR atau input manual.');

        $this->assertDatabaseCount('chatbot_knowledge', 0);
    }

    public function test_unggah_menolak_tipe_berkas_lain(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->create('gambar.png', 10, 'image/png'),
                'scope' => 'all',
            ])
            ->assertJsonValidationErrors('file');
    }

    public function test_dokumen_terunggah_ikut_ke_system_prompt(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('sop-lembur.txt', 'Lembur wajib disetujui manager.'),
                'scope' => 'all',
            ])
            ->assertCreated();

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('[sop-lembur]', $prompt);
        $this->assertStringContainsString('Lembur wajib disetujui manager.', $prompt);
    }

    public function test_pengetahuan_kosong_diberi_penanda(): void
    {
        ChatbotKnowledge::create(['title' => 'HR only', 'content' => 'rahasia', 'scope' => 'hr']);

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('(Tidak ada informasi yang tersedia untuk peran ini.)', $prompt);
    }
}
