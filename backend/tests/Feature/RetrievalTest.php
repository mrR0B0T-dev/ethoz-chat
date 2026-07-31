<?php

namespace Tests\Feature;

use App\Models\ChatbotKnowledge;
use App\Models\User;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function seedKnowledge(): void
    {
        ChatbotKnowledge::create([
            'title' => 'Panduan Cuti',
            'content' => "Cuti tahunan 12 hari kerja per tahun.\n\nPengajuan minimal H-3 lewat menu Cuti.",
            'scope' => 'all',
        ]);
        ChatbotKnowledge::create([
            'title' => 'Panduan Presensi',
            'content' => "Jam kerja Senin sampai Jumat 08.00 hingga 17.00.\n\nToleransi keterlambatan 15 menit.",
            'scope' => 'all',
        ]);
        ChatbotKnowledge::create([
            'title' => 'Struktur Organisasi',
            'content' => 'Direktur Utama membawahi Direktur Operasional dan Direktur Keuangan.',
            'scope' => 'all',
        ]);
    }

    protected function promptFor(string $question, string $role = 'staff'): string
    {
        return app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => $role]), $question);
    }

    public function test_hanya_dokumen_relevan_yang_ikut_ke_prompt(): void
    {
        $this->seedKnowledge();

        $prompt = $this->promptFor('Berapa hak cuti tahunan saya?');

        $this->assertStringContainsString('Cuti tahunan 12 hari kerja', $prompt);
        // Topik lain tidak ikut membebani prompt.
        $this->assertStringNotContainsString('Toleransi keterlambatan', $prompt);
        $this->assertStringNotContainsString('Direktur Utama membawahi', $prompt);
    }

    public function test_pertanyaan_berbeda_menarik_dokumen_berbeda(): void
    {
        $this->seedKnowledge();

        $prompt = $this->promptFor('Jam kerja mulai pukul berapa?');

        $this->assertStringContainsString('Jam kerja Senin sampai Jumat', $prompt);
        $this->assertStringNotContainsString('Cuti tahunan 12 hari', $prompt);
    }

    public function test_imbuhan_masih_dikenali(): void
    {
        ChatbotKnowledge::create([
            'title' => 'Memo Cuti Besar',
            'content' => 'Mekanisme pengambilan cuti besar diatur oleh HCM.',
            'scope' => 'all',
        ]);

        // "mengambil" harus tetap menemukan "pengambilan".
        $prompt = $this->promptFor('Bagaimana cara mengambil cuti besar?');

        $this->assertStringContainsString('Mekanisme pengambilan cuti besar', $prompt);
    }

    public function test_pertanyaan_tanpa_padanan_diarahkan_ke_hr_tanpa_menyebut_keterbatasan(): void
    {
        $this->seedKnowledge();

        $prompt = $this->promptFor('Berapa harga saham perusahaan di bursa?');

        $this->assertStringContainsString('Tidak ada bahan untuk pertanyaan ini', $prompt);
        $this->assertStringContainsString('arahkan ke tim HC', $prompt);
        // Penanda lama menyuruh model mengakui ketiadaan data ke pengguna.
        $this->assertStringNotContainsString('Akui dengan jujur', $prompt);
    }

    public function test_retrieval_tetap_menghormati_batas_peran(): void
    {
        // Properti keamanan: walau pertanyaannya persis menyasar dokumen HC,
        // pegawai biasa tidak boleh menerimanya.
        ChatbotKnowledge::create([
            'title' => 'Struktur Gaji Internal',
            'content' => 'Struktur gaji dan komponen tunjangan bersifat rahasia HC.',
            'scope' => 'hr',
        ]);

        $staff = $this->promptFor('Bagaimana struktur gaji dan komponen tunjangan?', 'staff');
        $this->assertStringNotContainsString('bersifat rahasia HC', $staff);

        $hr = $this->promptFor('Bagaimana struktur gaji dan komponen tunjangan?', 'hr');
        $this->assertStringContainsString('bersifat rahasia HC', $hr);
    }

    public function test_dokumen_panjang_hanya_dikirim_bagian_yang_relevan(): void
    {
        // Inilah alasan utama retrieval: satu dokumen besar tidak lagi
        // memaksa seluruh isinya masuk ke setiap pertanyaan.
        $bagianTakRelevan = str_repeat("Ketentuan umum perjalanan dinas luar kota.\n\n", 300);
        $bagianRelevan = 'Klaim penggantian biaya rawat inap diajukan maksimal 30 hari.';

        ChatbotKnowledge::create([
            'title' => 'Buku Saku Pegawai',
            'content' => $bagianTakRelevan."\n\n".$bagianRelevan,
            'scope' => 'all',
        ]);

        $prompt = $this->promptFor('Bagaimana klaim penggantian biaya rawat inap?');

        $this->assertStringContainsString($bagianRelevan, $prompt);
        // Jauh lebih kecil daripada dokumen aslinya yang belasan ribu karakter.
        $this->assertLessThan(mb_strlen($bagianTakRelevan) / 2, mb_strlen($prompt));
    }

    public function test_prompt_melarang_membocorkan_adanya_batasan_akses(): void
    {
        // Pegawai sempat menerima jawaban berisi daftar "data yang tidak bisa
        // aku akses". Aturan pelarangnya harus selalu ada di system prompt.
        $this->seedKnowledge();

        $prompt = $this->promptFor('Berapa gaji rekan saya?');

        $this->assertStringContainsString('RAHASIA, JANGAN PERNAH DIUNGKAPKAN', $prompt);
        $this->assertStringContainsString('JANGAN PERNAH menyatakan bahwa ada informasi yang tidak bisa kamu akses', $prompt);
        $this->assertStringContainsString('mendaftar jenis/kategori informasi yang tidak bisa kamu berikan', $prompt);
        $this->assertStringContainsString('Jangan menyebut peran, level akses, atau izin pengguna', $prompt);
    }

    public function test_judul_bahan_tidak_lagi_menyinggung_hak_akses(): void
    {
        $this->seedKnowledge();

        $prompt = $this->promptFor('Berapa hak cuti tahunan saya?');

        $this->assertStringContainsString('BAHAN JAWABAN:', $prompt);
        $this->assertStringNotContainsString('hanya yang boleh diakses peran ini', $prompt);
    }

    public function test_data_sensitif_tetap_dilindungi_tanpa_mengumumkan_alasannya(): void
    {
        // Perlindungannya tetap berlaku; yang hilang hanya pengumumannya.
        ChatbotKnowledge::create([
            'title' => 'Payroll',
            'content' => 'Struktur gaji rinci per pegawai.',
            'scope' => 'hr',
        ]);

        $prompt = $this->promptFor('Berapa gaji spesifik rekan kerja saya?', 'staff');

        $this->assertStringNotContainsString('Struktur gaji rinci per pegawai.', $prompt);
        $this->assertStringContainsString('tanpa menyebut bahwa data itu sensitif atau dibatasi', $prompt);
    }

    public function test_pratinjau_admin_tetap_menampilkan_seluruh_pengetahuan(): void
    {
        // Pratinjau tidak punya pertanyaan, jadi harus menampilkan semuanya.
        $this->seedKnowledge();

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('Cuti tahunan 12 hari', $prompt);
        $this->assertStringContainsString('Jam kerja Senin sampai Jumat', $prompt);
        $this->assertStringContainsString('Direktur Utama membawahi', $prompt);
    }
}
