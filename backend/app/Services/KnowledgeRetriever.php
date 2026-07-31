<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Memilih bagian pengetahuan yang relevan dengan pertanyaan.
 *
 * Sebelumnya SELURUH basis pengetahuan ditempel ke setiap pertanyaan. Itu boros,
 * melambat, dan justru menurunkan mutu jawaban karena model harus menyaring
 * ratusan baris yang tidak berkaitan.
 *
 * Pencarian di sini bersifat leksikal (tanpa API embedding), sehingga tidak
 * menambah biaya sama sekali dan tetap berjalan saat jaringan ke penyedia AI
 * bermasalah. Dokumen panjang dipecah menjadi paragraf agar yang terkirim hanya
 * bagian yang benar-benar menjawab.
 */
class KnowledgeRetriever
{
    /** Kata umum Bahasa Indonesia + kata tanya: tidak membedakan dokumen. */
    private const STOPWORDS = [
        'yang', 'dan', 'di', 'ke', 'dari', 'untuk', 'pada', 'dengan', 'adalah', 'itu',
        'ini', 'atau', 'sebagai', 'dalam', 'tidak', 'bisa', 'akan', 'oleh', 'juga',
        'sudah', 'saya', 'kamu', 'anda', 'apa', 'apakah', 'bagaimana', 'berapa',
        'kapan', 'siapa', 'mengapa', 'kenapa', 'ada', 'jika', 'bila', 'agar', 'saat',
        'saja', 'lagi', 'punya', 'boleh', 'harus', 'dapat', 'the', 'and', 'for',
        'are', 'was', 'how', 'what', 'when', 'who', 'why', 'can', 'does', 'nya',
    ];

    /**
     * @return array{context: string, sources: list<string>, matched: bool}
     */
    public function retrieve(string $question, Collection $allowed): array
    {
        $empty = ['context' => '', 'sources' => [], 'matched' => false];

        if ($allowed->isEmpty()) {
            return $empty;
        }

        $terms = $this->tokenize($question);

        // Pertanyaan tanpa kata bermakna (mis. "halo") — jangan tebak, biarkan
        // model menjawab dari gaya bicaranya saja.
        if ($terms === []) {
            return $empty;
        }

        $passages = $this->passages($allowed);
        if ($passages === []) {
            return $empty;
        }

        $idf = $this->idf($passages, $terms);

        $scored = [];
        foreach ($passages as $p) {
            $score = $this->score($p, $terms, $idf);
            if ($score > 0) {
                $p['score'] = $score;
                $scored[] = $p;
            }
        }

        if ($scored === []) {
            return $empty;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $this->assemble($scored);
    }

    /** Susun potongan terbaik sampai batas anggaran, tetap urut per dokumen. */
    protected function assemble(array $scored): array
    {
        $maxPassages = max(1, (int) config('chatbot.retrieval.max_passages'));
        $budget = max(500, (int) config('chatbot.retrieval.context_char_budget'));

        // Buang potongan yang jauh lebih lemah daripada yang terbaik: kecocokan
        // satu kata umum tidak cukup untuk ikut membebani prompt.
        $floor = $scored[0]['score'] * (float) config('chatbot.retrieval.min_score_ratio');
        $scored = array_values(array_filter($scored, fn ($p) => $p['score'] >= $floor));

        $picked = [];
        $used = 0;

        foreach (array_slice($scored, 0, $maxPassages) as $p) {
            $length = mb_strlen($p['text']);
            if ($used + $length > $budget && $picked !== []) {
                break;
            }
            $picked[] = $p;
            $used += $length;
        }

        // Kembalikan ke urutan asli dokumen supaya alur kalimat tetap masuk akal.
        usort($picked, fn ($a, $b) => [$a['title'], $a['order']] <=> [$b['title'], $b['order']]);

        $blocks = [];
        $sources = [];

        foreach ($picked as $p) {
            $sources[$p['title']] = true;
            $blocks[] = "[{$p['title']}]\n{$p['text']}";
        }

        return [
            'context' => implode("\n\n", $blocks),
            'sources' => array_keys($sources),
            'matched' => true,
        ];
    }

    /**
     * Pecah tiap entri menjadi potongan seukuran paragraf.
     *
     * @return list<array{title:string, text:string, order:int, tokens:list<string>}>
     */
    protected function passages(Collection $allowed): array
    {
        $size = max(300, (int) config('chatbot.retrieval.passage_chars'));
        $out = [];

        foreach ($allowed as $entry) {
            $content = trim((string) $entry->content);
            if ($content === '') {
                continue;
            }

            foreach ($this->split($content, $size) as $i => $chunk) {
                $out[] = [
                    'title' => (string) $entry->title,
                    'text' => $chunk,
                    'order' => $i,
                    // Judul ikut ditokenkan agar "panduan cuti" cocok lewat judulnya.
                    'tokens' => $this->tokenize($entry->title.' '.$chunk),
                ];
            }
        }

        return $out;
    }

    /**
     * Potong pada batas paragraf, jangan di tengah kalimat.
     *
     * @return list<string>
     */
    protected function split(string $text, int $size): array
    {
        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [$text];

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // Paragraf tunggal yang sangat panjang dipotong per baris.
            if (mb_strlen($paragraph) > $size * 2) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                foreach ($this->splitLong($paragraph, $size) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) + 1 > $size) {
                $chunks[] = $buffer;
                $buffer = $paragraph;
            } else {
                $buffer = $buffer === '' ? $paragraph : $buffer."\n".$paragraph;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /** @return list<string> */
    protected function splitLong(string $paragraph, int $size): array
    {
        $lines = preg_split('/\n/u', $paragraph) ?: [$paragraph];
        $chunks = [];
        $buffer = '';

        foreach ($lines as $line) {
            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($line) + 1 > $size) {
                $chunks[] = $buffer;
                $buffer = $line;
            } else {
                $buffer = $buffer === '' ? $line : $buffer."\n".$line;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // Baris tunggal raksasa (mis. tabel satu baris) dipotong keras.
        $final = [];
        foreach ($chunks as $chunk) {
            if (mb_strlen($chunk) <= $size * 2) {
                $final[] = $chunk;

                continue;
            }
            foreach (str_split($chunk, $size) as $piece) {
                $final[] = $piece;
            }
        }

        return $final;
    }

    /**
     * Bobot kelangkaan kata: kata yang muncul di mana-mana kurang menentukan.
     *
     * @param  list<string>  $terms
     * @return array<string, float>
     */
    protected function idf(array $passages, array $terms): array
    {
        $total = count($passages);
        $idf = [];

        foreach ($terms as $term) {
            $hits = 0;
            foreach ($passages as $p) {
                if ($this->contains($p['tokens'], $term)) {
                    $hits++;
                }
            }
            $idf[$term] = log(1 + ($total / max(1, $hits)));
        }

        return $idf;
    }

    /**
     * @param  list<string>  $terms
     * @param  array<string, float>  $idf
     */
    protected function score(array $passage, array $terms, array $idf): float
    {
        $tokens = $passage['tokens'];
        if ($tokens === []) {
            return 0.0;
        }

        $counts = array_count_values($tokens);
        $score = 0.0;
        $distinct = 0;

        foreach ($terms as $term) {
            $tf = 0;

            if (isset($counts[$term])) {
                $tf = $counts[$term];
            } else {
                // Kecocokan awalan menangkap imbuhan: "cuti" ~ "cutinya",
                // "ambil" ~ "pengambilan". Bobotnya separuh kecocokan penuh.
                foreach ($counts as $token => $n) {
                    if ($this->prefixMatch((string) $token, $term)) {
                        $tf += $n * 0.5;
                    }
                }
            }

            if ($tf > 0) {
                $distinct++;
                // Peredaman logaritmik: pengulangan tidak boleh mendominasi.
                $score += (1 + log($tf)) * ($idf[$term] ?? 1.0);
            }
        }

        if ($distinct === 0) {
            return 0.0;
        }

        // Potongan yang memuat lebih banyak kata kunci berbeda lebih meyakinkan.
        $coverage = $distinct / max(1, count($terms));
        $score *= (0.5 + $coverage);

        // Normalisasi panjang ringan agar potongan panjang tidak otomatis menang.
        return $score / sqrt(max(1, count($tokens) / 60));
    }

    /** @param list<string> $tokens */
    protected function contains(array $tokens, string $term): bool
    {
        if (in_array($term, $tokens, true)) {
            return true;
        }

        foreach ($tokens as $token) {
            if ($this->prefixMatch($token, $term)) {
                return true;
            }
        }

        return false;
    }

    protected function prefixMatch(string $token, string $term): bool
    {
        if (mb_strlen($term) < 5 || mb_strlen($token) < 5) {
            return false;
        }

        return str_contains($token, $term) || str_contains($term, $token);
    }

    /** @return list<string> */
    protected function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            if (in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }
}
