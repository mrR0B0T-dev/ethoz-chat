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
                'bot_name' => 'Ethoz Chat',
                'company' => 'PT Bumi Daya Plaza',
                'tone' => 'ramah',
                'address' => 'Kamu',
                'max_length' => 'detail',
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

        // 202, bukan 201: ekstraksi berjalan di antrean (OCR bisa lama).
        // Pada pengujian antreannya 'sync', jadi hasilnya langsung ada.
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('kebijakan-cuti.txt', $isi),
                'scope' => 'all',
            ])
            ->assertAccepted()
            ->assertJson([
                'title' => 'kebijakan-cuti',
                'scope' => 'all',
                'source' => 'document',
                'file_name' => 'kebijakan-cuti.txt',
                'is_active' => true,
            ]);

        $this->assertDatabaseCount('chatbot_knowledge', 1);

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertSame(ChatbotKnowledge::STATUS_DONE, $entry->status);
        $this->assertSame($isi, $entry->content);
        $this->assertSame(mb_strlen($isi), $entry->char_count);
    }

    public function test_unggah_dokumen_kosong_ditandai_gagal_beserta_alasannya(): void
    {
        // Unggahannya tidak dibatalkan: entrinya tetap ada agar admin melihat
        // apa yang terjadi pada berkas itu — bukan gagal tanpa jejak.
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('kosong.txt', '   '),
                'scope' => 'all',
            ])
            ->assertAccepted();

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertSame(ChatbotKnowledge::STATUS_FAILED, $entry->status);
        $this->assertSame('Tidak ada teks yang bisa dibaca dari berkas ini.', $entry->status_message);
        $this->assertSame('', $entry->content);
    }

    public function test_entri_yang_belum_selesai_tidak_ikut_ke_system_prompt(): void
    {
        ChatbotKnowledge::create([
            'title' => 'Panduan Lembur',
            'content' => '',
            'scope' => 'all',
            'source' => 'document',
            'status' => ChatbotKnowledge::STATUS_QUEUED,
        ]);

        ChatbotKnowledge::create([
            'title' => 'Pindaian Rusak',
            'content' => '',
            'scope' => 'all',
            'source' => 'document',
            'status' => ChatbotKnowledge::STATUS_FAILED,
            'status_message' => 'Tidak ada teks yang terbaca di dalam gambar ini.',
        ]);

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringNotContainsString('Panduan Lembur', $prompt);
        // Alasan kegagalan untuk admin, bukan bahan jawaban bot.
        $this->assertStringNotContainsString('Tidak ada teks yang terbaca', $prompt);
    }

    public function test_unggah_menolak_tipe_berkas_lain(): void
    {
        // Gambar kini JUSTRU didukung (dibaca lewat OCR), jadi penolakan tipe
        // diuji dengan format yang memang tidak bisa diekstraksi.
        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->create('arsip.zip', 10, 'application/zip'),
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
            ->assertAccepted();

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('[sop-lembur]', $prompt);
        $this->assertStringContainsString('Lembur wajib disetujui manager.', $prompt);
    }

    public function test_pratinjau_menampilkan_system_prompt_sesuai_peran(): void
    {
        ChatbotKnowledge::create(['title' => 'Umum', 'content' => 'Cuti 12 hari.', 'scope' => 'all']);
        ChatbotKnowledge::create(['title' => 'Rahasia HC', 'content' => 'Struktur gaji internal.', 'scope' => 'hr']);

        // Peran bawaan (staff) tidak melihat pengetahuan khusus HC.
        $staffPrompt = $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/preview')
            ->assertOk()
            ->json('prompt');

        $this->assertStringContainsString('Staff/Pegawai', $staffPrompt);
        $this->assertStringContainsString('Cuti 12 hari.', $staffPrompt);
        $this->assertStringNotContainsString('Struktur gaji internal.', $staffPrompt);

        // Peran HC melihat keduanya.
        $hrPrompt = $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/preview?role=hr')
            ->assertOk()
            ->json('prompt');

        $this->assertStringContainsString('Struktur gaji internal.', $hrPrompt);
    }

    public function test_pratinjau_tidak_membuat_user_baru(): void
    {
        $admin = $this->admin();
        $jumlahUser = User::count();

        $this->actingAs($admin)->getJson('/api/admin/chatbot/preview?role=manager')->assertOk();

        $this->assertSame($jumlahUser, User::count());
    }

    public function test_pratinjau_menolak_peran_tak_dikenal(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/preview?role=direksi')
            ->assertJsonValidationErrors('role');
    }

    public function test_pratinjau_tertutup_untuk_pegawai_biasa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->getJson('/api/admin/chatbot/preview')
            ->assertForbidden();
    }

    public function test_pratinjau_tertutup_untuk_tamu(): void
    {
        // Dipisah dari test di atas: actingAs() bertahan sepanjang satu test,
        // jadi permintaan "tamu" di test yang sama tetap terautentikasi.
        $this->getJson('/api/admin/chatbot/preview')->assertUnauthorized();
    }

    public function test_halaman_konsol_admin_bisa_dibuka(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/chatbot')
            ->assertOk()
            ->assertSee('Ethoz Chat', false);
    }

    public function test_halaman_konsol_admin_tertutup_untuk_pegawai_biasa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))
            ->get('/admin/chatbot')
            ->assertForbidden();
    }

    public function test_pengetahuan_kosong_diberi_penanda(): void
    {
        ChatbotKnowledge::create(['title' => 'HC only', 'content' => 'rahasia', 'scope' => 'hr']);

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('Tidak ada bahan untuk pertanyaan ini', $prompt);
        // Penanda lama menyebut "peran ini", yang sempat ditiru model menjadi
        // keterangan hak akses di dalam jawaban ke pegawai.
        $this->assertStringNotContainsString('tersedia untuk peran ini', $prompt);
    }
}
