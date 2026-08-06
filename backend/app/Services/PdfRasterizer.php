<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Mengubah halaman PDF menjadi berkas PNG agar bisa di-OCR.
 *
 * PDF hasil pindai tidak punya lapisan teks — isinya gambar per halaman.
 * Tesseract tidak membaca PDF, jadi halamannya dirasterkan lebih dulu.
 *
 * Dua jalur yang didukung, keduanya tersedia luas:
 *   - `pdftoppm` (poppler-utils) — cepat, tanpa ekstensi PHP tambahan;
 *   - ekstensi Imagick + Ghostscript — jalur cadangan bila poppler tak ada.
 *
 * Catatan: paket spatie/pdf-to-image sengaja tidak dipakai. Isinya pembungkus
 * tipis di atas Imagick yang sama, tetapi mewajibkan ext-imagick di tingkat
 * Composer sehingga `composer install` gagal di mesin pengembang yang belum
 * memasang ekstensi itu (mis. Laragon bawaan).
 */
class PdfRasterizer
{
    public function __construct(protected BinaryLocator $binaries) {}

    /** @return 'pdftoppm'|'imagick'|null Jalur yang benar-benar bisa dipakai. */
    public function driver(): ?string
    {
        $preferred = (string) config('chatbot.ocr.pdf_driver', 'auto');

        $poppler = $this->binaries->find((string) config('chatbot.ocr.pdftoppm')) !== null;
        $imagick = extension_loaded('imagick');

        return match ($preferred) {
            'pdftoppm' => $poppler ? 'pdftoppm' : null,
            'imagick' => $imagick ? 'imagick' : null,
            default => $poppler ? 'pdftoppm' : ($imagick ? 'imagick' : null),
        };
    }

    public function available(): bool
    {
        return $this->driver() !== null;
    }

    /**
     * Rasterkan halaman PDF ke dalam $dir.
     *
     * @param  int  $maxPages  Batas halaman — pengaman agar dokumen ratusan
     *                         halaman tidak menghabiskan waktu worker.
     * @return list<string> Lintasan gambar, terurut sesuai nomor halaman.
     */
    public function pages(string $pdf, string $dir, int $maxPages): array
    {
        $maxPages = max(1, $maxPages);
        $prefix = $dir.DIRECTORY_SEPARATOR.'page';

        $ok = match ($this->driver()) {
            'pdftoppm' => $this->viaPoppler($pdf, $prefix, $maxPages),
            'imagick' => $this->viaImagick($pdf, $prefix, $maxPages),
            default => false,
        };

        if (! $ok) {
            return [];
        }

        // pdftoppm menomori berkas dengan lebar digit mengikuti jumlah halaman
        // (page-1 / page-01 / page-001), jadi urutkan secara natural.
        $files = glob($prefix.'*.png') ?: [];
        sort($files, SORT_NATURAL);

        return array_slice($files, 0, $maxPages);
    }

    protected function viaPoppler(string $pdf, string $prefix, int $maxPages): bool
    {
        try {
            $process = new Process([
                $this->binaries->find((string) config('chatbot.ocr.pdftoppm')),
                '-png',
                '-r', (string) (int) config('chatbot.ocr.dpi'),
                '-f', '1',
                '-l', (string) $maxPages,
                $pdf,
                $prefix,
            ]);
            // Merasterkan seluruh dokumen sekali jalan — beri waktu lebih
            // longgar daripada OCR satu halaman.
            $process->setTimeout((float) config('chatbot.ocr.timeout') * 4);
            $process->mustRun();

            return true;
        } catch (ProcessFailedException|RuntimeException $e) {
            Log::warning('OCR: pdftoppm gagal merasterkan PDF.', [
                'file' => basename($pdf),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function viaImagick(string $pdf, string $prefix, int $maxPages): bool
    {
        $dpi = (int) config('chatbot.ocr.dpi');

        try {
            $im = new \Imagick;
            // Resolusi harus disetel SEBELUM berkas dibaca — Ghostscript
            // merender pada DPI ini, menaikkannya setelah itu tidak menambah
            // detail sama sekali.
            $im->setResolution($dpi, $dpi);
            $im->readImage($pdf);
            $im->setImageFormat('png');

            foreach ($im as $i => $page) {
                if ($i >= $maxPages) {
                    break;
                }
                $page->setImageBackgroundColor('white');
                $page->writeImage(sprintf('%s-%03d.png', $prefix, $i + 1));
            }

            $im->clear();

            return true;
        } catch (\Throwable $e) {
            // Penyebab paling sering: Ghostscript belum terpasang, atau
            // berkas PDF dilarang oleh policy.xml ImageMagick.
            Log::warning('OCR: Imagick gagal merasterkan PDF (Ghostscript terpasang?).', [
                'file' => basename($pdf),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
