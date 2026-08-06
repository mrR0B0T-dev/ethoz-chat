<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Mengubah dokumen unggahan menjadi teks datar yang siap dipakai AI.
 *
 * Menangani tiga hal yang dulu hilang:
 *  - tabel Word (dulu dilewati diam-diam karena Table memakai getRows(),
 *    bukan getElements());
 *  - paragraf dengan beberapa gaya huruf (dulu pecah menjadi satu baris per
 *    potongan, sehingga "Kepada : Seluruh Unit Kerja" tercerai-berai);
 *  - teks yang berada di dalam gambar / PDF hasil pindai (lewat OCR).
 */
class DocumentTextExtractor
{
    /** Alasan sebagian isi tidak terbaca — dibaca controller untuk pesan ke admin. */
    protected ?string $notice = null;

    /** Apakah sebagian teks berasal dari OCR — ikut dilaporkan ke admin. */
    protected bool $usedOcr = false;

    public function __construct(protected OcrService $ocr) {}

    /** Format yang bisa diproses, dipakai juga untuk aturan validasi unggahan. */
    public static function supportedExtensions(): array
    {
        return ['pdf', 'docx', 'txt', 'md', 'csv', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'tif', 'tiff'];
    }

    public function notice(): ?string
    {
        return $this->notice;
    }

    public function usedOcr(): bool
    {
        return $this->usedOcr;
    }

    public function extract(string $path, string $ext): string
    {
        $this->notice = null;
        $this->usedOcr = false;

        $text = match (strtolower($ext)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'txt', 'md', 'csv' => $this->plain($path),
            'png', 'jpg', 'jpeg', 'webp', 'bmp', 'tif', 'tiff' => $this->picture($path),
            default => '',
        };

        return $this->tidy($text);
    }

    // ── PDF ──────────────────────────────────────────────────────

    protected function pdf(string $path): string
    {
        $pages = [];
        $pageCount = 0;

        try {
            $pdf = (new PdfParser)->parseFile($path);
            foreach ($pdf->getPages() as $i => $page) {
                $pageCount++;
                $text = $this->tidy((string) $page->getText());
                if ($text !== '') {
                    $pages[] = '[Halaman '.($i + 1).']'.PHP_EOL.$text;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Ekstraksi: PDF gagal dibaca sebagai teks.', ['error' => $e->getMessage()]);
        }

        $text = implode(PHP_EOL.PHP_EOL, $pages);

        // Sedikit/tanpa teks => besar kemungkinan hasil pindai. Coba OCR.
        //
        // Urutannya sengaja begini: lapisan teks asli selalu lebih cepat dan
        // lebih akurat daripada OCR, jadi ia tidak pernah diganti selama ada.
        // OCR hanya menambal halaman yang memang tidak punya teks.
        if ($this->looksScanned($text, $pageCount)) {
            $ocr = $this->ocr->scannedPdf($path);

            if ($ocr !== '') {
                $this->usedOcr = true;

                // Gabungkan: sebagian PDF punya teks pada sebagian halaman saja.
                return trim($text) === '' ? $ocr : $text.PHP_EOL.PHP_EOL.$ocr;
            }

            if (trim($text) === '') {
                $this->notice = $this->ocr->unavailableReason()
                    ?? 'PDF ini tidak memuat lapisan teks dan OCR tidak menghasilkan apa pun.';
            }
        }

        return $text;
    }

    protected function looksScanned(string $text, int $pageCount): bool
    {
        if ($pageCount === 0) {
            return true;
        }

        $perPage = mb_strlen($text) / $pageCount;

        return $perPage < (int) config('chatbot.ocr.min_chars_per_page');
    }

    // ── Word (DOCX) ──────────────────────────────────────────────

    protected function docx(string $path): string
    {
        $lines = [];

        try {
            $doc = IOFactory::load($path);

            foreach ($doc->getSections() as $section) {
                // Kop & kaki halaman kerap memuat nomor dokumen dan unit kerja.
                foreach ($section->getHeaders() as $header) {
                    $this->render($header->getElements(), $lines);
                }

                $this->render($section->getElements(), $lines);

                foreach ($section->getFooters() as $footer) {
                    $this->render($footer->getElements(), $lines);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Ekstraksi: DOCX gagal dibaca.', ['error' => $e->getMessage()]);
        }

        $text = implode(PHP_EOL, $lines);

        // Sebagian DOCX di lapangan ber-XML tidak sah (mis. "&" tanpa escape)
        // sehingga pembacaan terstruktur gagal total. Daripada mengembalikan
        // kosong, ambil teksnya langsung dari XML di dalam arsip.
        if (trim($text) === '') {
            $text = $this->docxRaw($path);
        }

        // Gambar yang disisipkan di dalam dokumen (mis. bagan, tabel hasil pindai).
        $fromImages = $this->docxImages($path);
        if ($fromImages !== '') {
            $this->usedOcr = true;
            $text = trim($text) === '' ? $fromImages : $text.PHP_EOL.PHP_EOL.$fromImages;
        }

        return $text;
    }

    /**
     * Ubah pohon elemen PhpWord menjadi baris teks.
     *
     * @param  iterable<object>  $elements
     * @param  list<string>  $lines
     */
    protected function render(iterable $elements, array &$lines): void
    {
        foreach ($elements as $el) {
            if ($el instanceof Table) {
                $this->renderTable($el, $lines);

                continue;
            }

            if ($el instanceof ListItem) {
                $text = $this->inline([$el->getTextObject()]);
                if ($text !== '') {
                    $lines[] = '- '.$text;
                }

                continue;
            }

            if ($el instanceof ListItemRun) {
                $text = $this->inline($el->getElements());
                if ($text !== '') {
                    $lines[] = '- '.$text;
                }

                continue;
            }

            // Satu paragraf = satu baris, walau terdiri dari banyak potongan gaya.
            if ($el instanceof TextRun) {
                $text = $this->inline($el->getElements());
                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            if ($el instanceof Text) {
                $text = $this->stringify($el->getText());
                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            // Judul, kotak teks, catatan kaki, dsb.
            if (method_exists($el, 'getElements')) {
                $this->render($el->getElements(), $lines);

                continue;
            }

            if (method_exists($el, 'getText')) {
                $text = $this->stringify($el->getText());
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }
    }

    /**
     * Tabel ditulis sebagai baris berpemisah "|" agar hubungan antar kolom
     * tetap terbaca oleh model.
     *
     * @param  list<string>  $lines
     */
    protected function renderTable(Table $table, array &$lines): void
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cellLines = [];
                $this->render($cell->getElements(), $cellLines);
                // Isi sel bisa berupa beberapa paragraf — rapatkan jadi satu sel.
                $cells[] = trim(implode(' ', array_map('trim', $cellLines)));
            }

            // Lewati baris yang seluruh selnya kosong.
            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = '| '.implode(' | ', $cells).' |';
        }

        if ($rows === []) {
            return;
        }

        $lines[] = '[Tabel]';
        foreach ($rows as $row) {
            $lines[] = $row;
        }
        $lines[] = '';
    }

    /**
     * Gabungkan potongan sebaris menjadi satu string, tanpa baris baru.
     *
     * @param  iterable<object>  $elements
     */
    protected function inline(iterable $elements): string
    {
        $parts = [];

        foreach ($elements as $el) {
            if ($el instanceof TextRun || (! $el instanceof Text && method_exists($el, 'getElements'))) {
                $parts[] = $this->inline($el->getElements());

                continue;
            }

            if (method_exists($el, 'getText')) {
                $parts[] = $this->stringify($el->getText());
            }
        }

        // Potongan bersebelahan menyatu apa adanya; spasi ganda dirapikan nanti.
        return trim(preg_replace('/[ \t]{2,}/u', ' ', implode('', $parts)) ?? implode('', $parts));
    }

    /** getText() PhpWord bisa mengembalikan string, array, atau objek. */
    protected function stringify(mixed $value): string
    {
        if (is_string($value)) {
            // PhpWord mengembalikan teks apa adanya dari XML, sehingga "&" pada
            // dokumen asli terbaca sebagai "&amp;". Kembalikan ke bentuk semula
            // agar model tidak menerima entitas mentah.
            return html_entity_decode($value, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');
        }

        if (is_array($value)) {
            return implode(' ', array_map(fn ($v) => $this->stringify($v), $value));
        }

        if (is_object($value) && method_exists($value, 'getText')) {
            return $this->stringify($value->getText());
        }

        return '';
    }

    /**
     * Cadangan untuk DOCX yang XML-nya tidak sah: baca word/document.xml apa
     * adanya, ubah penanda paragraf/sel/baris menjadi pemisah teks, lalu buang
     * sisa tag. Tidak serapi pembacaan terstruktur, tetapi jauh lebih baik
     * daripada dokumen gagal terbaca seluruhnya.
     */
    protected function docxRaw(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        $parts = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || ! preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                    continue;
                }

                $xml = (string) $zip->getFromName($name);
                if ($xml === '') {
                    continue;
                }

                $xml = preg_replace('#<w:tab\b[^>]*/?>#', ' ', $xml) ?? $xml;
                $xml = preg_replace('#<w:br\b[^>]*/?>#', "\n", $xml) ?? $xml;

                // Penanda struktur sebelum seluruh tag dibuang.
                $xml = str_replace(['</w:tc>', '</w:tr>', '</w:p>'], [' | ', "\n", "\n"], $xml);

                $text = strip_tags($xml);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1 | ENT_HTML5, 'UTF-8');

                // Rapikan baris tabel: "a | b | " menjadi "| a | b |".
                $lines = [];
                foreach (preg_split('/\n/', $text) ?: [] as $line) {
                    $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line);
                    if ($line === '') {
                        continue;
                    }
                    if (str_contains($line, '|')) {
                        $line = '| '.trim($line, ' |').' |';
                    }
                    $lines[] = $line;
                }

                if ($lines !== []) {
                    $parts[] = implode(PHP_EOL, $lines);
                }
            }
        } finally {
            $zip->close();
        }

        if ($parts !== []) {
            Log::info('Ekstraksi: DOCX dibaca lewat jalur cadangan (XML mentah).', [
                'file' => basename($path),
            ]);
        }

        return implode(PHP_EOL, $parts);
    }

    /** Baca gambar yang tertanam di dalam DOCX (berkas .docx adalah arsip zip). */
    protected function docxImages(string $path): string
    {
        if (! $this->ocr->available() || ! class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethoz-docx-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);

        $blocks = [];

        try {
            $found = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || ! preg_match('#^word/media/.+\.(png|jpe?g|bmp|tiff?)$#i', $name)) {
                    continue;
                }

                if (++$found > (int) config('chatbot.ocr.max_pages')) {
                    break;
                }

                $target = $dir.DIRECTORY_SEPARATOR.$found.'-'.basename($name);
                $stream = $zip->getStream($name);
                if (! $stream) {
                    continue;
                }
                file_put_contents($target, $stream);
                fclose($stream);

                $text = $this->ocr->image($target);
                if ($text !== '') {
                    $blocks[] = '[Gambar '.$found.']'.PHP_EOL.$text;
                }

                @unlink($target);
            }
        } finally {
            $zip->close();
            @rmdir($dir);
        }

        return implode(PHP_EOL.PHP_EOL, $blocks);
    }

    // ── Berkas teks & gambar ─────────────────────────────────────

    protected function plain(string $path): string
    {
        $raw = (string) file_get_contents($path);

        // Berkas dari Windows kerap ber-encoding non-UTF-8.
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $detected = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252';
            $raw = mb_convert_encoding($raw, 'UTF-8', $detected);
        }

        // Buang BOM agar tidak ikut terbaca sebagai karakter.
        return preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw;
    }

    protected function picture(string $path): string
    {
        $text = $this->ocr->image($path);

        if ($text === '') {
            $this->notice = $this->ocr->unavailableReason()
                ?? 'Tidak ada teks yang terbaca di dalam gambar ini. Pastikan hasil pindainya '
                    .'tegak, tidak buram, dan cukup terang.';

            return '';
        }

        $this->usedOcr = true;

        return $text;
    }

    /** Normalkan baris dan spasi agar prompt tidak boros token. */
    protected function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
