<?php

namespace Tests\Feature;

use App\Services\BinaryLocator;
use App\Services\ImagePreprocessor;
use App\Services\OcrService;
use App\Services\PdfRasterizer;
use Mockery;
use Tests\TestCase;

/**
 * Keputusan "OCR bisa dipakai atau tidak" harus sama di mesin mana pun.
 *
 * Biner tesseract/poppler tidak dipasang di mesin uji, jadi pencarinya
 * ditiru — yang diperiksa di sini adalah logikanya, bukan hasil OCR.
 */
class OcrFallbackTest extends TestCase
{
    /** @param string|null $found Lintasan biner yang "ditemukan". */
    protected function locator(?string $found): BinaryLocator
    {
        $locator = Mockery::mock(BinaryLocator::class);
        $locator->shouldReceive('find')->andReturn($found);

        return $locator;
    }

    protected function ocr(?string $binary, bool $canRasterize = true): OcrService
    {
        $rasterizer = Mockery::mock(PdfRasterizer::class);
        $rasterizer->shouldReceive('available')->andReturn($canRasterize);

        return new OcrService(
            $this->locator($binary),
            $rasterizer,
            new ImagePreprocessor,
        );
    }

    public function test_ocr_mati_lewat_konfigurasi_menyebutkan_sebabnya(): void
    {
        config(['chatbot.ocr.enabled' => false]);

        $ocr = $this->ocr('C:\\Tesseract\\tesseract.exe');

        $this->assertFalse($ocr->available());
        $this->assertStringContainsString('OCR_ENABLED', (string) $ocr->unavailableReason());
    }

    public function test_tesseract_tidak_terpasang_menyebutkan_cara_memasangnya(): void
    {
        $ocr = $this->ocr(null);

        $this->assertFalse($ocr->available());

        $reason = (string) $ocr->unavailableReason();
        // Pesannya harus bisa ditindaklanjuti admin di kedua lingkungan.
        $this->assertStringContainsString('winget install', $reason);
        $this->assertStringContainsString('tesseract-ocr-ind', $reason);
    }

    public function test_tesseract_ada_tetapi_pdf_tidak_bisa_dirasterkan(): void
    {
        $ocr = $this->ocr('/usr/bin/tesseract', canRasterize: false);

        // Gambar tetap bisa dibaca; hanya PDF hasil pindai yang tidak.
        $this->assertTrue($ocr->available());
        $this->assertFalse($ocr->canReadScannedPdf());
        $this->assertStringContainsString('pdftoppm', (string) $ocr->unavailableReason());
        $this->assertSame('', $ocr->scannedPdf(__FILE__));
    }

    public function test_semua_siap_tidak_menyisakan_keluhan(): void
    {
        $ocr = $this->ocr('/usr/bin/tesseract');

        $this->assertTrue($ocr->canReadScannedPdf());
        $this->assertNull($ocr->unavailableReason());
    }

    public function test_perasteran_memilih_poppler_lebih_dulu(): void
    {
        $rasterizer = new PdfRasterizer($this->locator('/usr/bin/pdftoppm'));

        config(['chatbot.ocr.pdf_driver' => 'auto']);
        $this->assertSame('pdftoppm', $rasterizer->driver());

        config(['chatbot.ocr.pdf_driver' => 'pdftoppm']);
        $this->assertSame('pdftoppm', $rasterizer->driver());
    }

    public function test_perasteran_menyerah_saat_alatnya_tidak_ada(): void
    {
        // Poppler dipaksa lewat konfigurasi, tetapi binernya tidak ada:
        // jangan diam-diam beralih ke Imagick yang belum tentu terpasang.
        config(['chatbot.ocr.pdf_driver' => 'pdftoppm']);

        $rasterizer = new PdfRasterizer($this->locator(null));

        $this->assertNull($rasterizer->driver());
        $this->assertFalse($rasterizer->available());
        $this->assertSame([], $rasterizer->pages(__FILE__, sys_get_temp_dir(), 5));
    }

    public function test_pra_pemrosesan_tidak_menghalangi_saat_imagick_tidak_ada(): void
    {
        config(['chatbot.ocr.preprocess.enabled' => true]);

        $preprocessor = new ImagePreprocessor;

        if (extension_loaded('imagick')) {
            $this->assertTrue($preprocessor->enabled());

            return;
        }

        // Tanpa Imagick, gambar diteruskan apa adanya — OCR tetap jalan.
        $this->assertFalse($preprocessor->enabled());
        $this->assertSame(__FILE__, $preprocessor->prepare(__FILE__, sys_get_temp_dir()));
    }
}
