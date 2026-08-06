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
    | Berkas unggahan & antrean ekstraksi
    |--------------------------------------------------------------------------
    |
    | Ekstraksi (terutama OCR PDF hasil pindai) bisa berjalan menit-menitan,
    | jadi berkasnya disimpan lebih dulu lalu diproses oleh queue worker —
    | permintaan HTTP tidak ikut menunggu. Berkas dihapus setelah selesai.
    |
    | Jalankan worker-nya: `php artisan queue:work` (lihat IMPLEMENTATION.md §9g).
    |
    */

    'uploads' => [
        // Cakram penyimpanan sementara. Bawaan 'local' = storage/app/private.
        'disk' => env('CHATBOT_UPLOAD_DISK', 'local'),

        'directory' => env('CHATBOT_UPLOAD_DIR', 'chatbot-documents'),
    ],

    'extraction' => [
        // Antrean khusus agar unggahan besar tidak menahan pekerjaan lain.
        'queue' => env('CHATBOT_EXTRACT_QUEUE', 'default'),

        // Berapa kali pekerjaan diulang bila gagal karena hal sementara.
        'tries' => (int) env('CHATBOT_EXTRACT_TRIES', 2),

        // Batas waktu satu pekerjaan (detik). PDF pindai tebal butuh lama.
        'timeout' => (int) env('CHATBOT_EXTRACT_TIMEOUT', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR (teks di dalam gambar / PDF hasil pindai)
    |--------------------------------------------------------------------------
    |
    | OCR memakai Tesseract lewat pembungkus thiagoalessio/tesseract_ocr. Yang
    | dipasang lewat Composer hanya pembungkusnya — biner `tesseract` beserta
    | data bahasanya tetap harus ada di host:
    |
    |   Windows : winget install -e --id UB-Mannheim.TesseractOCR
    |             (centang "Additional language data" → Indonesian, atau salin
    |              ind.traineddata ke C:\Program Files\Tesseract-OCR\tessdata)
    |   Linux   : apt-get install -y tesseract-ocr tesseract-ocr-ind \
    |                                tesseract-ocr-eng poppler-utils
    |
    | Basis pengetahuan berbahasa Indonesia, jadi OCR dijalankan dengan
    | 'ind+eng' — data bahasa `ind` DAN `eng` keduanya wajib terpasang.
    |
    | Bila binernya tidak terpasang, ekstraksi tetap berjalan untuk dokumen
    | ber-teks dan OCR dilewati dengan pesan yang jelas ke admin.
    |
    */

    'ocr' => [
        'enabled' => (bool) env('OCR_ENABLED', true),

        'tesseract' => env('TESSERACT_PATH', 'tesseract'),
        'pdftoppm' => env('PDFTOPPM_PATH', 'pdftoppm'),

        // Bahasa Tesseract. 'ind+eng' menangani dokumen campuran.
        'lang' => env('OCR_LANG', 'ind+eng'),

        // Folder tessdata non-standar (mis. pemasangan portabel). Kosong = bawaan.
        'tessdata_dir' => env('OCR_TESSDATA_DIR'),

        /*
         | Tata letak halaman Tesseract (--psm). 3 = deteksi otomatis penuh,
         | cocok untuk memo/surat. 6 dipakai bila halaman berupa satu blok
         | teks rata, 4 untuk kolom.
         */
        'psm' => (int) env('OCR_PSM', 3),

        // Batas halaman PDF yang di-OCR — mencegah proses berjalan sangat lama.
        'max_pages' => (int) env('OCR_MAX_PAGES', 40),

        // Detik per pemanggilan biner.
        'timeout' => (int) env('OCR_TIMEOUT', 120),

        // DPI rasterisasi. 300 adalah titik seimbang akurasi vs kecepatan.
        'dpi' => (int) env('OCR_DPI', 300),

        /*
         | Cara halaman PDF diubah menjadi gambar sebelum di-OCR:
         |   auto     — pdftoppm bila ada, selain itu Imagick (butuh Ghostscript)
         |   pdftoppm — paksa poppler
         |   imagick  — paksa ekstensi Imagick
         */
        'pdf_driver' => env('OCR_PDF_DRIVER', 'auto'),

        /*
         | Ambang "PDF ini kemungkinan hasil pindai": bila rata-rata karakter
         | per halaman di bawah angka ini, halaman dianggap tanpa lapisan teks
         | sehingga OCR dijalankan sebagai cadangan.
         */
        'min_chars_per_page' => (int) env('OCR_MIN_CHARS_PER_PAGE', 80),

        /*
         | Pra-pemrosesan gambar sebelum OCR (butuh ekstensi Imagick).
         | Hasil pindai kantor kerap miring, redup, dan berbayang — tiga
         | langkah di bawah ini yang paling berpengaruh pada akurasi.
         | Matikan bila sumbernya sudah bersih atau Imagick tidak tersedia.
         */
        'preprocess' => [
            'enabled' => (bool) env('OCR_PREPROCESS', true),

            // Warna tidak membantu OCR, tetapi menambah derau.
            'grayscale' => (bool) env('OCR_PREPROCESS_GRAYSCALE', true),

            // Regangkan kontras: pindaian redup jadi tegas.
            'normalize' => (bool) env('OCR_PREPROCESS_NORMALIZE', true),

            // Luruskan halaman miring. Persen ambang; 0 = mati.
            'deskew' => (float) env('OCR_PREPROCESS_DESKEW', 40),

            // Ambang hitam-putih. Persen; 0 = mati (biarkan abu-abu).
            'threshold' => (float) env('OCR_PREPROCESS_THRESHOLD', 55),
        ],
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
