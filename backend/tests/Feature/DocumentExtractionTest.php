<?php

namespace Tests\Feature;

use App\Models\ChatbotKnowledge;
use App\Models\User;
use App\Services\ChatbotService;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'hr']);
    }

    protected function extractor(): DocumentTextExtractor
    {
        return app(DocumentTextExtractor::class);
    }

    /** Tulis DOCX sungguhan berisi memo dengan tabel, seperti dokumen HC nyata. */
    protected function makeMemoDocx(): string
    {
        // Word sungguhan menuliskan "&" sebagai "&amp;". Penulis PhpWord tidak
        // melakukannya secara bawaan, jadi nyalakan agar berkas uji ber-XML sah.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $section = $word->addSection();

        // Paragraf dengan beberapa potongan gaya huruf dalam satu baris.
        $run = $section->addTextRun();
        $run->addText('Nomor');
        $run->addText('   :  ');
        $run->addText('012/HCM/IX/2025', ['bold' => true]);

        $table = $section->addTable();
        $r1 = $table->addRow();
        $r1->addCell()->addText('Kepada');
        $r1->addCell()->addText('Seluruh Unit Kerja');
        $r2 = $table->addRow();
        $r2->addCell()->addText('Dari');
        $r2->addCell()->addText('Direktur SDM & Umum');
        $r3 = $table->addRow();
        $r3->addCell()->addText('Perihal');
        $r3->addCell()->addText('Mekanisme Pengambilan Cuti Besar');

        $section->addListItem('Cuti besar diberikan setelah 6 tahun masa kerja.');
        $section->addListItem('Pengajuan minimal 30 hari sebelumnya.');

        $path = tempnam(sys_get_temp_dir(), 'memo').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->temp[] = $path;

        return $path;
    }

    public function test_tabel_word_ikut_terbaca(): void
    {
        // Dulu Table dilewati diam-diam: walker hanya mengikuti getElements(),
        // sedangkan Table memakai getRows().
        $text = $this->extractor()->extract($this->makeMemoDocx(), 'docx');

        $this->assertStringContainsString('[Tabel]', $text);
        $this->assertStringContainsString('| Kepada | Seluruh Unit Kerja |', $text);
        $this->assertStringContainsString('| Dari | Direktur SDM & Umum |', $text);
        $this->assertStringContainsString('| Perihal | Mekanisme Pengambilan Cuti Besar |', $text);
    }

    public function test_paragraf_banyak_gaya_tidak_terpecah_jadi_beberapa_baris(): void
    {
        // Dulu tiap potongan gaya menjadi barisnya sendiri, sehingga
        // "Nomor : 012/HCM/IX/2025" tercerai-berai dan sulit dibaca model.
        $text = $this->extractor()->extract($this->makeMemoDocx(), 'docx');

        $this->assertStringContainsString('Nomor : 012/HCM/IX/2025', $text);
    }

    public function test_daftar_berpoin_dipertahankan(): void
    {
        $text = $this->extractor()->extract($this->makeMemoDocx(), 'docx');

        $this->assertStringContainsString('- Cuti besar diberikan setelah 6 tahun masa kerja.', $text);
    }

    public function test_unggah_docx_bertabel_lewat_konsol(): void
    {
        $path = $this->makeMemoDocx();
        $upload = new UploadedFile($path, 'memo-cuti-besar.docx', null, null, true);

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', ['file' => $upload, 'scope' => 'all'])
            ->assertCreated();

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertStringContainsString('| Kepada | Seluruh Unit Kerja |', $entry->content);
        $this->assertSame('document', $entry->source);
    }

    public function test_dokumen_besar_tersimpan_utuh_melewati_batas_text_lama(): void
    {
        // Kolom TEXT hanya menampung ±65.535 byte; dokumen di atas itu dulu
        // terpotong diam-diam. Sekarang kolomnya LONGTEXT.
        $besar = str_repeat("Kebijakan cuti tahunan berlaku bagi seluruh pegawai.\n", 4000);
        $this->assertGreaterThan(65535, strlen($besar));

        $upload = UploadedFile::fake()->createWithContent('kebijakan-besar.txt', $besar);

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', ['file' => $upload, 'scope' => 'all'])
            ->assertCreated();

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertSame(mb_strlen(trim($besar)), $entry->char_count);
        $this->assertGreaterThan(65535, strlen($entry->fresh()->content));
    }

    public function test_entri_panjang_masih_bisa_disunting(): void
    {
        // Aturan lama max:5000 membuat entri hasil unggahan tidak bisa disimpan ulang.
        $entry = ChatbotKnowledge::create([
            'title' => 'Panduan Pakaian Kerja',
            'content' => 'awal',
            'scope' => 'all',
        ]);

        // Tanpa titik-spasi di ujung: middleware TrimStrings memangkas spasi
        // tepi pada input permintaan, yang akan mengubah panjang harapan.
        $panjang = trim(str_repeat('Ketentuan seragam kerja harian. ', 2000)); // ±64.000 karakter

        $this->actingAs($this->admin())
            ->putJson("/api/admin/chatbot/knowledge/{$entry->id}", [
                'title' => 'Panduan Pakaian Kerja',
                'content' => $panjang,
                'scope' => 'all',
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame(mb_strlen($panjang), mb_strlen($entry->fresh()->content));
    }

    public function test_gambar_ditolak_dengan_alasan_yang_jelas_saat_ocr_mati(): void
    {
        config(['chatbot.ocr.enabled' => false]);

        $upload = UploadedFile::fake()->image('struktur-organisasi.png', 600, 400);

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', ['file' => $upload, 'scope' => 'all'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'OCR dimatikan lewat konfigurasi (OCR_ENABLED=false).');

        $this->assertDatabaseCount('chatbot_knowledge', 0);
    }

    public function test_berkas_non_utf8_dibaca_tanpa_karakter_rusak(): void
    {
        $latin = mb_convert_encoding("Tunjangan kesehatan pegawai — berlaku 2025\n", 'Windows-1252', 'UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'latin').'.txt';
        file_put_contents($path, $latin);
        $this->temp[] = $path;

        $text = $this->extractor()->extract($path, 'txt');

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringContainsString('Tunjangan kesehatan pegawai', $text);
    }

    public function test_prompt_dibatasi_saat_pengetahuan_melebihi_anggaran(): void
    {
        // Tanpa pembatas, satu dokumen besar membuat SETIAP pertanyaan
        // melebihi jendela konteks dan gagal total.
        config(['chatbot.prompt_char_budget' => 5000]);

        foreach (range(1, 6) as $i) {
            ChatbotKnowledge::create([
                'title' => "Dokumen $i",
                'content' => str_repeat("isi panjang dokumen $i. ", 200), // ±4.400 karakter
                'scope' => 'all',
            ]);
        }

        Log::spy();

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        // Tetap dalam orde anggaran, bukan gabungan seluruh 26.000+ karakter.
        $this->assertLessThan(9000, mb_strlen($prompt));

        // Pemangkasan tidak boleh bocor ke pengguna — pemberitahuannya ke log admin.
        $this->assertStringNotContainsString('batas ukuran prompt', $prompt);
        $this->assertStringNotContainsString('tidak disertakan', $prompt);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'melebihi anggaran prompt'))
            ->once();
    }

    public function test_seluruh_pengetahuan_ikut_saat_masih_di_bawah_anggaran(): void
    {
        ChatbotKnowledge::create(['title' => 'Cuti', 'content' => 'Cuti tahunan 12 hari.', 'scope' => 'all']);
        ChatbotKnowledge::create(['title' => 'Presensi', 'content' => 'Jam kerja 08.00-17.00.', 'scope' => 'all']);

        $prompt = app(ChatbotService::class)
            ->buildSystemPrompt(User::factory()->create(['role' => 'staff']));

        $this->assertStringContainsString('Cuti tahunan 12 hari.', $prompt);
        $this->assertStringContainsString('Jam kerja 08.00-17.00.', $prompt);
        $this->assertStringNotContainsString('batas ukuran prompt', $prompt);
    }
}
