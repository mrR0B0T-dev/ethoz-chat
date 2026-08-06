<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Membersihkan gambar sebelum diserahkan ke Tesseract.
 *
 * Pindaian kantor jarang rapi: miring beberapa derajat karena diletakkan
 * asal di kaca pemindai, redup karena tinta menipis, dan berwarna karena
 * kop surat. Tesseract paling akurat pada halaman hitam-putih yang tegak,
 * jadi urutannya: abu-abu → kontras dinormalkan → diluruskan → diambangkan.
 *
 * Seluruh langkah butuh ekstensi Imagick dan bisa dimatikan lewat
 * `chatbot.ocr.preprocess`. Bila Imagick tidak ada atau salah satu langkah
 * gagal, gambar aslinya dikembalikan apa adanya — OCR tetap jalan, hanya
 * tanpa perbaikan.
 */
class ImagePreprocessor
{
    public function enabled(): bool
    {
        return (bool) config('chatbot.ocr.preprocess.enabled') && extension_loaded('imagick');
    }

    /**
     * @param  string  $path  Gambar sumber.
     * @param  string  $workDir  Folder sementara untuk menyimpan hasil.
     * @return string Lintasan gambar yang siap di-OCR (bisa sama dengan sumber).
     */
    public function prepare(string $path, string $workDir): string
    {
        if (! $this->enabled()) {
            return $path;
        }

        $target = $workDir.DIRECTORY_SEPARATOR.'prep-'.bin2hex(random_bytes(4)).'.png';

        try {
            $im = new \Imagick;
            $im->readImage($path);

            // TIFF banyak halaman: Tesseract membacanya utuh sendiri, sedangkan
            // di sini halamannya justru akan saling menimpa. Biarkan apa adanya.
            if ($im->getNumberImages() > 1) {
                $im->clear();

                return $path;
            }

            // PNG berlatar tembus pandang menjadi hitam pekat saat diratakan
            // ke format lain — paksa latar putih lebih dulu.
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('png');

            $cfg = (array) config('chatbot.ocr.preprocess');
            $quantum = $im->getQuantumRange()['quantumRangeLong'];

            if ($cfg['grayscale'] ?? true) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            }

            if ($cfg['normalize'] ?? true) {
                $im->normalizeImage();
            }

            $deskew = (float) ($cfg['deskew'] ?? 0);
            if ($deskew > 0) {
                $im->deskewImage($quantum * ($deskew / 100));
                // Pelurusan menyisakan kanvas maya; tanpa ini ukuran halaman
                // ikut bergeser saat ditulis ulang.
                $im->setImagePage(0, 0, 0, 0);
            }

            $threshold = (float) ($cfg['threshold'] ?? 0);
            if ($threshold > 0) {
                $im->thresholdImage($quantum * ($threshold / 100));
            }

            $im->writeImage($target);
            $im->clear();
        } catch (\Throwable $e) {
            Log::warning('OCR: pra-pemrosesan gambar dilewati.', [
                'file' => basename($path),
                'error' => $e->getMessage(),
            ]);

            return $path;
        }

        return is_file($target) ? $target : $path;
    }
}
