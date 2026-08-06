<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Mencari biner luar (tesseract, pdftoppm) sekali lalu mengingat hasilnya.
 *
 * Nilai konfigurasinya boleh berupa nama perintah di PATH ("tesseract") atau
 * lintasan absolut — di Windows biner OCR kerap tidak masuk PATH, sehingga
 * lintasannya ditunjuk lewat .env.
 */
class BinaryLocator
{
    /** @var array<string, ?string> Cache per-proses; pencarian PATH tidak gratis. */
    protected array $found = [];

    /**
     * @param  string  $configured  Nama perintah atau lintasan absolut.
     * @return string|null Lintasan yang bisa dijalankan, atau null bila tidak ada.
     */
    public function find(string $configured): ?string
    {
        $configured = trim($configured);

        if ($configured === '') {
            return null;
        }

        if (array_key_exists($configured, $this->found)) {
            return $this->found[$configured];
        }

        // Lintasan absolut yang memang ada dipakai apa adanya.
        if (is_file($configured) && is_executable($configured)) {
            return $this->found[$configured] = $configured;
        }

        return $this->found[$configured] = (new ExecutableFinder)->find($configured);
    }
}
