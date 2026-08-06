<?php

namespace App\Jobs;

use App\Models\ChatbotKnowledge;
use App\Services\DocumentTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengekstrak teks satu dokumen unggahan di luar permintaan HTTP.
 *
 * Alasannya OCR: satu PDF pindai 40 halaman berarti 40 kali rasterisasi +
 * 40 kali Tesseract — hitungan menit, jauh melewati batas wajar sebuah
 * permintaan web. Admin cukup menerima entri berstatus "queued", lalu
 * statusnya bergerak sendiri di konsol.
 *
 * Kegagalan tidak pernah membatalkan unggahan: entri tetap ada dengan status
 * "failed" beserta alasannya, sehingga admin tahu harus berbuat apa.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Queueable;

    /** Diisi dari config di konstruktor — dibaca queue worker. */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  int  $knowledgeId  Entri yang menunggu isinya.
     * @param  string  $disk  Cakram tempat berkas unggahan disimpan sementara.
     * @param  string  $storedPath  Lintasan berkas di cakram tersebut.
     * @param  string  $extension  Ekstensi asli — penentu cara ekstraksi.
     * @param  string  $originalName  Nama berkas dari admin, untuk log.
     */
    public function __construct(
        public int $knowledgeId,
        public string $disk,
        public string $storedPath,
        public string $extension,
        public string $originalName,
    ) {
        $this->onQueue((string) config('chatbot.extraction.queue'));
        $this->tries = max(1, (int) config('chatbot.extraction.tries'));
        $this->timeout = max(60, (int) config('chatbot.extraction.timeout'));
    }

    public function handle(DocumentTextExtractor $extractor): void
    {
        $entry = ChatbotKnowledge::find($this->knowledgeId);

        // Admin bisa saja menghapus entrinya selagi antre.
        if (! $entry) {
            $this->discardFile();

            return;
        }

        $entry->markProcessing();

        $local = $this->localCopy();

        if ($local === null) {
            $entry->markFailed('Berkas unggahan tidak ditemukan lagi di penyimpanan sementara. Silakan unggah ulang.');
            Log::error('Ekstraksi: berkas unggahan hilang sebelum diproses.', [
                'knowledge_id' => $entry->id,
                'path' => $this->storedPath,
            ]);

            return;
        }

        try {
            $text = trim($extractor->extract($local['path'], $this->extension));

            if ($text === '') {
                // Bukan galat teknis — dokumen memang tidak memuat teks yang
                // terbaca. Jangan diulang, cukup laporkan sebabnya.
                $reason = $extractor->notice() ?? 'Tidak ada teks yang bisa dibaca dari berkas ini.';
                $entry->markFailed($reason);

                Log::info('Ekstraksi: tidak ada teks terbaca.', [
                    'knowledge_id' => $entry->id,
                    'file' => $this->originalName,
                    'reason' => $reason,
                ]);

                $this->discardFile();

                return;
            }

            $entry->markDone(...$this->limit($text, $extractor));

            Log::info('Ekstraksi: dokumen siap dipakai bot.', [
                'knowledge_id' => $entry->id,
                'file' => $this->originalName,
                'karakter' => $entry->char_count,
                'ocr' => $extractor->usedOcr(),
            ]);

            $this->discardFile();
        } finally {
            // Salinan lokal selalu dibuang; berkas di cakram hanya dibuang pada
            // hasil akhir, supaya percobaan ulang antrean masih menemukannya.
            if ($local['temporary']) {
                @unlink($local['path']);
            }
        }
    }

    /** Dipanggil setelah percobaan terakhir gagal — entri tidak boleh menggantung. */
    public function failed(?Throwable $e): void
    {
        Log::error('Ekstraksi: pekerjaan gagal seluruhnya.', [
            'knowledge_id' => $this->knowledgeId,
            'file' => $this->originalName,
            'error' => $e?->getMessage(),
        ]);

        ChatbotKnowledge::find($this->knowledgeId)?->markFailed(
            'Ekstraksi gagal di server. Periksa log aplikasi untuk rinciannya, '
            .'lalu unggah ulang berkasnya.'
        );

        $this->discardFile();
    }

    /**
     * Batasi panjang teks sesuai konfigurasi lalu susun catatan untuk admin.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function limit(string $text, DocumentTextExtractor $extractor): array
    {
        $max = (int) config('chatbot.content_max_chars');
        $notes = [];

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
            $notes[] = 'Dokumen sangat panjang dan dipotong pada '.number_format($max).' karakter.';

            Log::warning('Ekstraksi: teks dipotong pada batas konfigurasi.', [
                'knowledge_id' => $this->knowledgeId,
                'file' => $this->originalName,
                'limit' => $max,
            ]);
        }

        if ($extractor->usedOcr()) {
            $notes[] = 'Sebagian teks dibaca lewat OCR — periksa hasilnya sebelum dipakai.';
        }

        if ($note = $extractor->notice()) {
            $notes[] = $note;
        }

        return [$text, $notes === [] ? null : implode(' ', $notes)];
    }

    /**
     * Sediakan berkas sebagai lintasan lokal.
     *
     * Ekstraksi memakai pustaka yang membaca berkas dari disk (pdfparser,
     * PhpWord, Tesseract), jadi cakram non-lokal disalin dulu ke temp.
     *
     * @return array{path: string, temporary: bool}|null
     */
    protected function localCopy(): ?array
    {
        $disk = Storage::disk($this->disk);

        if (! $disk->exists($this->storedPath)) {
            return null;
        }

        try {
            $path = $disk->path($this->storedPath);

            if (is_file($path)) {
                return ['path' => $path, 'temporary' => false];
            }
        } catch (Throwable) {
            // Cakram jarak jauh (mis. S3) tidak punya lintasan lokal.
        }

        $temp = tempnam(sys_get_temp_dir(), 'ethoz-doc').'.'.$this->extension;
        file_put_contents($temp, $disk->get($this->storedPath));

        return ['path' => $temp, 'temporary' => true];
    }

    /** Berkas sementara tidak perlu disimpan setelah teksnya diambil. */
    protected function discardFile(): void
    {
        try {
            Storage::disk($this->disk)->delete($this->storedPath);
        } catch (Throwable $e) {
            Log::warning('Ekstraksi: berkas sementara gagal dihapus.', [
                'path' => $this->storedPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
