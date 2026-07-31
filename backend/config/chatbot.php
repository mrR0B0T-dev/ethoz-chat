<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ekstraksi dokumen
    |--------------------------------------------------------------------------
    |
    | Batas ukuran unggahan (KB) dan panjang teks yang boleh disimpan sebagai
    | satu entri pengetahuan. Kolom `content` memakai LONGTEXT, jadi batas di
    | sini murni pengaman aplikasi, bukan batas basis data.
    |
    */

    'upload_max_kb' => (int) env('CHATBOT_UPLOAD_MAX_KB', 51200), // 50 MB

    'content_max_chars' => (int) env('CHATBOT_CONTENT_MAX_CHARS', 2000000),

    /*
    |--------------------------------------------------------------------------
    | OCR (teks di dalam gambar / PDF hasil pindai)
    |--------------------------------------------------------------------------
    |
    | OCR memakai Tesseract. Bila binernya tidak terpasang, ekstraksi tetap
    | berjalan untuk dokumen ber-teks dan OCR dilewati dengan pesan yang jelas.
    |
    |   Windows : winget install -e --id UB-Mannheim.TesseractOCR
    |   Linux   : apt install tesseract-ocr tesseract-ocr-ind poppler-utils
    |
    | `pdftoppm` (poppler) dipakai untuk mengubah halaman PDF hasil pindai
    | menjadi gambar sebelum di-OCR. Alternatifnya ekstensi Imagick.
    |
    */

    'ocr' => [
        'enabled' => (bool) env('OCR_ENABLED', true),

        'tesseract' => env('TESSERACT_PATH', 'tesseract'),
        'pdftoppm' => env('PDFTOPPM_PATH', 'pdftoppm'),

        // Bahasa Tesseract. 'ind+eng' menangani dokumen campuran.
        'lang' => env('OCR_LANG', 'ind+eng'),

        // Batas halaman PDF yang di-OCR — mencegah proses berjalan sangat lama.
        'max_pages' => (int) env('OCR_MAX_PAGES', 40),

        // Detik per pemanggilan biner.
        'timeout' => (int) env('OCR_TIMEOUT', 120),

        // DPI rasterisasi. 300 adalah titik seimbang akurasi vs kecepatan.
        'dpi' => (int) env('OCR_DPI', 300),

        /*
         | Ambang "PDF ini kemungkinan hasil pindai": bila rata-rata karakter
         | per halaman di bawah angka ini, halaman dianggap tanpa lapisan teks
         | sehingga OCR dijalankan sebagai cadangan.
         */
        'min_chars_per_page' => (int) env('OCR_MIN_CHARS_PER_PAGE', 80),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anggaran ukuran system prompt
    |--------------------------------------------------------------------------
    |
    | Seluruh basis pengetahuan yang boleh diakses ikut dikirim pada SETIAP
    | pertanyaan. Tanpa batas, satu dokumen besar membuat tiap permintaan mahal
    | dan berisiko melebihi jendela konteks model.
    |
    | Bila total melebihi anggaran, entri disertakan sampai batas lalu diberi
    | penanda — jauh lebih baik daripada permintaan gagal seluruhnya. Untuk
    | basis pengetahuan besar, tingkatkan ke pola retrieval (IMPLEMENTATION.md §10).
    |
    */

    'prompt_char_budget' => (int) env('CHATBOT_PROMPT_CHAR_BUDGET', 240000),

    /*
    |--------------------------------------------------------------------------
    | Pencarian pengetahuan (retrieval)
    |--------------------------------------------------------------------------
    |
    | Hanya bagian dokumen yang relevan dengan pertanyaan yang dikirim ke model,
    | bukan seluruh basis pengetahuan. Hemat biaya, lebih cepat, dan jawabannya
    | lebih fokus. Matikan untuk kembali ke perilaku lama (kirim semuanya).
    |
    */

    'retrieval' => [
        'enabled' => (bool) env('CHATBOT_RETRIEVAL', true),

        // Ukuran potongan dokumen saat dipecah untuk dicari.
        'passage_chars' => (int) env('CHATBOT_PASSAGE_CHARS', 1200),

        // Berapa potongan teratas yang boleh ikut.
        'max_passages' => (int) env('CHATBOT_MAX_PASSAGES', 8),

        // Batas total karakter konteks yang ditempel ke prompt.
        'context_char_budget' => (int) env('CHATBOT_CONTEXT_BUDGET', 12000),

        /*
         | Ambang relevansi relatif terhadap potongan terbaik. Satu kata umum
         | yang kebetulan sama (mis. "kerja" pada "12 hari kerja") tidak boleh
         | menyeret dokumen yang tidak berkaitan ikut ke dalam prompt.
         */
        'min_score_ratio' => (float) env('CHATBOT_MIN_SCORE_RATIO', 0.30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Riwayat percakapan
    |--------------------------------------------------------------------------
    */

    'history' => [
        // Jumlah pesan terakhir yang ikut sebagai konteks percakapan.
        'max_turns' => (int) env('CHATBOT_MAX_TURNS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Perkiraan biaya (USD per 1 juta token) — untuk halaman pemantauan
    |--------------------------------------------------------------------------
    |
    | Sesuaikan bila model atau harga berubah. Angka bawaan mengikuti Haiku 4.5.
    |
    */

    'pricing' => [
        'input_per_mtok' => (float) env('CHATBOT_PRICE_INPUT', 1.00),
        'output_per_mtok' => (float) env('CHATBOT_PRICE_OUTPUT', 5.00),
    ],

];
