<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\TesseractOcrException;

/**
 * Pembaca teks di dalam gambar (Tesseract OCR).
 *
 * Pemanggilan binernya lewat paket thiagoalessio/tesseract_ocr; yang dipasang
 * Composer hanya pembungkus PHP-nya — biner `tesseract` beserta data bahasa
 * `ind` dan `eng` tetap harus terpasang di host. Lihat config/chatbot.php dan
 * IMPLEMENTATION.md §9b untuk perintah pemasangannya (Windows & Linux).
 *
 * Seluruh kelas ini bersifat opsional: bila biner OCR tidak terpasang, setiap
 * metode mengembalikan string kosong dan alasannya bisa dibaca lewat
 * unavailableReason() — pemanggil tetap berjalan tanpa OCR.
 */
class OcrService
{
    public function __construct(
        protected BinaryLocator $binaries,
        protected PdfRasterizer $rasterizer,
        protected ImagePreprocessor $preprocessor,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('chatbot.ocr.enabled');
    }

    /** OCR gambar siap pakai bila Tesseract tersedia. */
    public function available(): bool
    {
        return $this->enabled() && $this->tesseract() !== null;
    }

    /** OCR PDF hasil pindai juga butuh perasteran halaman. */
    public function canReadScannedPdf(): bool
    {
        return $this->available() && $this->rasterizer->available();
    }

    /** Penjelasan singkat untuk ditampilkan ke admin saat OCR tidak bisa dipakai. */
    public function unavailableReason(): ?string
    {
        if (! $this->enabled()) {
            return 'OCR dimatikan lewat konfigurasi (OCR_ENABLED=false).';
        }

        if ($this->tesseract() === null) {
            return 'Tesseract OCR belum terpasang di server, sehingga teks di dalam gambar tidak terbaca. '
                .'Pasang lebih dulu (Windows: winget install -e --id UB-Mannheim.TesseractOCR; '
                .'Linux: apt-get install tesseract-ocr tesseract-ocr-ind).';
        }

        if (! $this->rasterizer->available()) {
            return 'Tesseract sudah ada, tetapi poppler (pdftoppm) atau ekstensi Imagick belum terpasang, '
                .'sehingga halaman PDF hasil pindai tidak bisa diubah menjadi gambar untuk di-OCR.';
        }

        return null;
    }

    /**
     * Baca teks dari satu berkas gambar.
     *
     * @param  string|null  $workDir  Folder sementara untuk hasil pra-pemrosesan.
     *                                Bila null, dibuat dan dibersihkan sendiri.
     */
    public function image(string $path, ?string $workDir = null): string
    {
        if (! $this->available()) {
            return '';
        }

        $ownDir = $workDir === null;
        $workDir = $workDir ?? $this->tempDir();
        $prepared = $path;

        try {
            $prepared = $this->preprocessor->prepare($path, $workDir);

            $ocr = (new TesseractOCR($prepared))
                ->executable((string) $this->tesseract())
                // Berkas antara Tesseract ikut ke folder kerja agar terhapus
                // bersama sisanya, bukan menumpuk di folder temp sistem.
                ->tempDir($workDir)
                ->lang(...$this->languages())
                ->psm((int) config('chatbot.ocr.psm'));

            if ($dir = config('chatbot.ocr.tessdata_dir')) {
                $ocr->tessdataDir((string) $dir);
            }

            return $this->tidy($ocr->run((int) config('chatbot.ocr.timeout')));
        } catch (TesseractOcrException $e) {
            // Termasuk halaman yang memang tidak memuat teks: paket ini
            // menganggap keluaran kosong sebagai kegagalan perintah.
            Log::warning('OCR: tidak ada teks yang terbaca dari gambar.', [
                'file' => basename($path),
                'error' => $this->firstLine($e->getMessage()),
            ]);

            return '';
        } catch (\Throwable $e) {
            Log::error('OCR: gagal menjalankan Tesseract.', [
                'file' => basename($path),
                'error' => $e->getMessage(),
            ]);

            return '';
        } finally {
            // Hasil pra-pemrosesan langsung dibuang: PDF 40 halaman pada 300 DPI
            // akan menumpuk ratusan MB bila ditahan sampai akhir dokumen.
            if ($prepared !== $path) {
                @unlink($prepared);
            }

            if ($ownDir) {
                $this->cleanup($workDir);
            }
        }
    }

    /**
     * Baca teks dari PDF hasil pindai: tiap halaman dirasterkan lalu di-OCR,
     * hasilnya digabung sesuai urutan halaman.
     */
    public function scannedPdf(string $path): string
    {
        if (! $this->canReadScannedPdf()) {
            return '';
        }

        $dir = $this->tempDir();

        try {
            $images = $this->rasterizer->pages($path, $dir, (int) config('chatbot.ocr.max_pages'));

            if ($images === []) {
                return '';
            }

            $pages = [];

            foreach ($images as $i => $image) {
                $text = $this->image($image, $dir);
                if ($text !== '') {
                    $pages[] = '[Halaman '.($i + 1).']'.PHP_EOL.$text;
                }
                // Halaman yang sudah dibaca langsung dibuang: dokumen 40
                // halaman pada 300 DPI bisa ratusan MB bila ditahan semua.
                @unlink($image);
            }

            if ($pages === []) {
                Log::warning('OCR: PDF berhasil dirasterkan tetapi tidak ada teks yang terbaca.', [
                    'file' => basename($path),
                    'halaman' => count($images),
                ]);
            }

            return implode(PHP_EOL.PHP_EOL, $pages);
        } finally {
            $this->cleanup($dir);
        }
    }

    /** @return list<string> Bahasa Tesseract, mis. ['ind', 'eng']. */
    protected function languages(): array
    {
        $langs = array_filter(array_map('trim', explode('+', (string) config('chatbot.ocr.lang'))));

        return array_values($langs) ?: ['eng'];
    }

    protected function tesseract(): ?string
    {
        return $this->binaries->find((string) config('chatbot.ocr.tesseract'));
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethoz-ocr-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);

        return $dir;
    }

    protected function cleanup(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** Pesan galat paket OCR panjang (memuat perintah + $PATH) — cukup barisnya. */
    protected function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }

    /** Rapikan keluaran OCR yang kerap penuh spasi dan baris kosong berlebih. */
    protected function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
