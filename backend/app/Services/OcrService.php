<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Pembaca teks di dalam gambar (Tesseract OCR).
 *
 * Seluruh kelas ini bersifat opsional: bila biner OCR tidak terpasang, setiap
 * metode mengembalikan string kosong dan alasannya bisa dibaca lewat
 * unavailableReason() — pemanggil tetap berjalan tanpa OCR.
 */
class OcrService
{
    /** @var array<string, ?string> Cache hasil pencarian biner per proses. */
    protected array $binaries = [];

    public function enabled(): bool
    {
        return (bool) config('chatbot.ocr.enabled');
    }

    /** OCR gambar siap pakai bila Tesseract tersedia. */
    public function available(): bool
    {
        return $this->enabled() && $this->binary('tesseract') !== null;
    }

    /** OCR PDF hasil pindai juga butuh perasteran halaman. */
    public function canReadScannedPdf(): bool
    {
        return $this->available() && ($this->binary('pdftoppm') !== null || extension_loaded('imagick'));
    }

    /** Penjelasan singkat untuk ditampilkan ke admin saat OCR tidak bisa dipakai. */
    public function unavailableReason(): ?string
    {
        if (! $this->enabled()) {
            return 'OCR dimatikan lewat konfigurasi (OCR_ENABLED=false).';
        }

        if ($this->binary('tesseract') === null) {
            return 'Tesseract OCR belum terpasang di server, sehingga teks di dalam gambar tidak terbaca. '
                .'Pasang lebih dulu (Windows: winget install -e --id UB-Mannheim.TesseractOCR).';
        }

        if (! $this->canReadScannedPdf()) {
            return 'Tesseract sudah ada, tetapi poppler (pdftoppm) atau ekstensi Imagick belum terpasang, '
                .'sehingga halaman PDF hasil pindai tidak bisa diubah menjadi gambar untuk di-OCR.';
        }

        return null;
    }

    /** Baca teks dari satu berkas gambar. */
    public function image(string $path): string
    {
        if (! $this->available()) {
            return '';
        }

        try {
            $process = new Process([
                $this->binary('tesseract'),
                $path,
                'stdout',
                '-l', (string) config('chatbot.ocr.lang'),
                '--psm', '3',
            ]);
            $process->setTimeout((float) config('chatbot.ocr.timeout'));
            $process->mustRun();

            return $this->tidy($process->getOutput());
        } catch (ProcessFailedException|RuntimeException $e) {
            Log::warning('OCR: gagal membaca gambar.', ['path' => basename($path), 'error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Baca teks dari PDF hasil pindai: halaman dirasterkan lalu di-OCR.
     *
     * @return string Teks per halaman, diberi penanda halaman.
     */
    public function scannedPdf(string $path): string
    {
        if (! $this->canReadScannedPdf()) {
            return '';
        }

        $dir = $this->tempDir();

        try {
            $images = $this->rasterize($path, $dir);
            if ($images === []) {
                return '';
            }

            $pages = [];
            foreach ($images as $i => $image) {
                $text = $this->image($image);
                if ($text !== '') {
                    $pages[] = '[Halaman '.($i + 1).']'.PHP_EOL.$text;
                }
            }

            return implode(PHP_EOL.PHP_EOL, $pages);
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * Ubah halaman PDF menjadi berkas PNG.
     *
     * @return list<string> Lintasan gambar, terurut per halaman.
     */
    protected function rasterize(string $pdf, string $dir): array
    {
        $maxPages = max(1, (int) config('chatbot.ocr.max_pages'));
        $dpi = (int) config('chatbot.ocr.dpi');
        $prefix = $dir.DIRECTORY_SEPARATOR.'page';

        if ($this->binary('pdftoppm') !== null) {
            try {
                $process = new Process([
                    $this->binary('pdftoppm'),
                    '-png',
                    '-r', (string) $dpi,
                    '-f', '1',
                    '-l', (string) $maxPages,
                    $pdf,
                    $prefix,
                ]);
                $process->setTimeout((float) config('chatbot.ocr.timeout') * 2);
                $process->mustRun();
            } catch (ProcessFailedException|RuntimeException $e) {
                Log::warning('OCR: pdftoppm gagal merasterkan PDF.', ['error' => $e->getMessage()]);

                return [];
            }
        } elseif (extension_loaded('imagick')) {
            try {
                /** @var \Imagick $im */
                $im = new \Imagick;
                $im->setResolution($dpi, $dpi);
                $im->readImage($pdf);
                $im->setImageFormat('png');

                foreach ($im as $i => $page) {
                    if ($i >= $maxPages) {
                        break;
                    }
                    $page->writeImage(sprintf('%s-%03d.png', $prefix, $i + 1));
                }
                $im->clear();
            } catch (\Throwable $e) {
                Log::warning('OCR: Imagick gagal merasterkan PDF.', ['error' => $e->getMessage()]);

                return [];
            }
        } else {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'page*.png') ?: [];
        sort($files, SORT_NATURAL);

        return array_slice($files, 0, $maxPages);
    }

    /** Cari biner sekali lalu ingat hasilnya. */
    protected function binary(string $key): ?string
    {
        if (array_key_exists($key, $this->binaries)) {
            return $this->binaries[$key];
        }

        $configured = (string) config("chatbot.ocr.$key");

        // Lintasan absolut yang memang ada dipakai apa adanya.
        if (is_file($configured) && is_executable($configured)) {
            return $this->binaries[$key] = $configured;
        }

        return $this->binaries[$key] = (new ExecutableFinder)->find($configured);
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

    /** Rapikan keluaran OCR yang kerap penuh spasi dan baris kosong berlebih. */
    protected function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
