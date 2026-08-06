<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentText;
use App\Models\ChatbotKnowledge;
use App\Models\User;
use App\Services\ChatbotService;
use App\Services\DocumentTextExtractor;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
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

    /**
     * Ganti Tesseract dengan tiruan: mesin pembangun tidak memasang binernya,
     * dan yang diuji di sini adalah keputusan kapan OCR dipakai — bukan mutu
     * pembacaan Tesseract itu sendiri.
     *
     * @param  array{image?: string, scannedPdf?: string}  $hasil
     */
    protected function fakeOcr(array $hasil = []): MockInterface
    {
        return $this->mock(OcrService::class, function (MockInterface $m) use ($hasil) {
            $m->shouldReceive('enabled')->andReturnTrue();
            $m->shouldReceive('available')->andReturnTrue();
            $m->shouldReceive('canReadScannedPdf')->andReturnTrue();
            $m->shouldReceive('unavailableReason')->andReturnNull();
            $m->shouldReceive('image')->andReturn($hasil['image'] ?? '');

            if (array_key_exists('scannedPdf', $hasil)) {
                $m->shouldReceive('scannedPdf')->andReturn($hasil['scannedPdf']);
            }
        });
    }

    /**
     * Tulis PDF satu halaman dengan lapisan teks seadanya.
     *
     * Teks kosong menghasilkan halaman tanpa lapisan teks sama sekali —
     * persis seperti PDF keluaran mesin pemindai.
     */
    protected function makePdf(string $isi): string
    {
        $stream = $isi === '' ? '' : 'BT /F1 12 Tf 50 700 Td ('.$isi.') Tj ET';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }

        $startxref = strlen($pdf);
        $size = count($objects) + 1;

        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        foreach ($objects as $n => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$startxref\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
        file_put_contents($path, $pdf);
        $this->temp[] = $path;

        return $path;
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
            ->assertAccepted();

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertStringContainsString('| Kepada | Seluruh Unit Kerja |', $entry->content);
        $this->assertSame('document', $entry->source);
        $this->assertSame(ChatbotKnowledge::STATUS_DONE, $entry->status);
    }

    public function test_unggahan_diantrekan_dan_berkas_sementara_dibersihkan(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->createWithContent('sop.txt', 'Isi kebijakan.'),
                'scope' => 'hr',
            ])
            ->assertAccepted()
            ->assertJsonPath('status', ChatbotKnowledge::STATUS_QUEUED);

        $entry = ChatbotKnowledge::firstOrFail();

        // Permintaan HTTP tidak ikut menunggu ekstraksi — hanya mengantrekan.
        Queue::assertPushed(ExtractDocumentText::class, function ($job) use ($entry) {
            $this->assertSame($entry->id, $job->knowledgeId);
            $this->assertSame('txt', $job->extension);
            $this->assertTrue(Storage::disk('local')->exists($job->storedPath));

            // Berkas dititipkan, lalu dihapus pekerjaan antrean setelah selesai.
            $job->handle(app(DocumentTextExtractor::class));
            $this->assertFalse(Storage::disk('local')->exists($job->storedPath));

            return true;
        });

        $this->assertSame('Isi kebijakan.', $entry->fresh()->content);
        $this->assertSame(ChatbotKnowledge::STATUS_DONE, $entry->fresh()->status);
    }

    public function test_pekerjaan_yang_gagal_menandai_entri_bukan_menggantung(): void
    {
        $entry = ChatbotKnowledge::create([
            'title' => 'Pindaian Rapat',
            'content' => '',
            'scope' => 'all',
            'source' => 'document',
            'status' => ChatbotKnowledge::STATUS_QUEUED,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('chatbot-documents/rapat.pdf', 'bukan pdf sungguhan');

        (new ExtractDocumentText($entry->id, 'local', 'chatbot-documents/rapat.pdf', 'pdf', 'rapat.pdf'))
            ->failed(new \RuntimeException('Tesseract mati di tengah jalan'));

        $entry->refresh();
        $this->assertSame(ChatbotKnowledge::STATUS_FAILED, $entry->status);
        $this->assertStringContainsString('Ekstraksi gagal di server', $entry->status_message);
        $this->assertFalse(Storage::disk('local')->exists('chatbot-documents/rapat.pdf'));
    }

    public function test_status_dokumen_bisa_dipantau_konsol(): void
    {
        ChatbotKnowledge::create([
            'title' => 'Struktur Organisasi',
            'content' => '',
            'scope' => 'all',
            'source' => 'document',
            'file_name' => 'struktur.png',
            'status' => ChatbotKnowledge::STATUS_PROCESSING,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/chatbot/documents/status')
            ->assertOk()
            ->assertJsonPath('0.status', ChatbotKnowledge::STATUS_PROCESSING)
            ->assertJsonPath('0.file_name', 'struktur.png')
            // Isi dokumen bisa bermegabyte — pemantau tidak boleh ikut menyeretnya.
            ->assertJsonMissingPath('0.content');
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
            ->assertAccepted();

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

    public function test_gambar_ditandai_gagal_dengan_alasan_yang_jelas_saat_ocr_mati(): void
    {
        config(['chatbot.ocr.enabled' => false]);

        $upload = UploadedFile::fake()->image('struktur-organisasi.png', 600, 400);

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', ['file' => $upload, 'scope' => 'all'])
            ->assertAccepted();

        // Unggahannya tidak dibatalkan — admin melihat sebabnya pada entrinya.
        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertSame(ChatbotKnowledge::STATUS_FAILED, $entry->status);
        $this->assertSame('OCR dimatikan lewat konfigurasi (OCR_ENABLED=false).', $entry->status_message);
    }

    public function test_gambar_diunggah_masuk_basis_pengetahuan_lewat_ocr(): void
    {
        $this->fakeOcr(['image' => "STRUKTUR ORGANISASI\nDivisi Human Capital"]);

        $this->actingAs($this->admin())
            ->post('/api/admin/chatbot/documents', [
                'file' => UploadedFile::fake()->image('struktur-organisasi.jpg', 800, 600),
                'scope' => 'all',
            ])
            ->assertAccepted();

        $entry = ChatbotKnowledge::firstOrFail();
        $this->assertSame(ChatbotKnowledge::STATUS_DONE, $entry->status);
        $this->assertStringContainsString('Divisi Human Capital', $entry->content);
        // Tersimpan persis seperti dokumen lain — sumbernya tetap 'document'.
        $this->assertSame('document', $entry->source);
        $this->assertStringContainsString('OCR', (string) $entry->status_message);
    }

    public function test_pdf_dengan_lapisan_teks_tidak_di_ocr(): void
    {
        // Lapisan teks asli lebih cepat DAN lebih akurat daripada OCR, jadi
        // selama ada, OCR tidak boleh dijalankan sama sekali.
        $mock = $this->fakeOcr();
        $mock->shouldReceive('scannedPdf')->never();

        $isi = 'Kebijakan cuti tahunan berlaku bagi seluruh pegawai tetap. '
            .'Pengajuan minimal tiga hari sebelumnya melalui atasan langsung.';

        $text = $this->extractor()->extract($this->makePdf($isi), 'pdf');

        $this->assertStringContainsString('Kebijakan cuti tahunan', $text);
    }

    public function test_pdf_hasil_pindai_jatuh_ke_ocr(): void
    {
        // Halaman tanpa lapisan teks — persis seperti PDF hasil pemindai.
        $this->fakeOcr(['scannedPdf' => "[Halaman 1]\nSURAT EDARAN Nomor 012/HCM/IX/2026"]);

        $text = $this->extractor()->extract($this->makePdf(''), 'pdf');

        $this->assertStringContainsString('SURAT EDARAN Nomor 012/HCM/IX/2026', $text);
    }

    public function test_pdf_berteks_tipis_dilengkapi_hasil_ocr(): void
    {
        // Halaman pindai kerap menyisakan sedikit teks (nomor halaman, kop
        // digital). Di bawah ambang, halaman dianggap tanpa lapisan teks —
        // hasil OCR ditambahkan tanpa membuang teks aslinya.
        config(['chatbot.ocr.min_chars_per_page' => 80]);
        $this->fakeOcr(['scannedPdf' => "[Halaman 1]\nIsi lengkap hasil pemindaian."]);

        $text = $this->extractor()->extract($this->makePdf('Halaman 1 dari 1'), 'pdf');

        $this->assertStringContainsString('Halaman 1 dari 1', $text);
        $this->assertStringContainsString('Isi lengkap hasil pemindaian.', $text);
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
