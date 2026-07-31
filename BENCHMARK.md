# Ethoz Chat vs Claude AI — Benchmark

Perbandingan Ethoz Chat terhadap Claude AI (produk chat konsumen) di tiga
lapisan: **PRD**, **alur kerja**, dan **perilaku**. Tujuannya bukan menyamai
Claude AI fitur per fitur — keduanya menjawab kebutuhan berbeda — melainkan
menakar jarak pada hal yang memang relevan bagi asisten kepegawaian internal.

Status per 31 Juli 2026. Legenda: ✅ setara · 🟡 sebagian · ❌ belum ada ·
➖ tidak relevan.

---

## 0. Ringkasan

| | Claude AI | Ethoz Chat |
|---|---|---|
| Pengguna | umum, terbuka | pegawai satu perusahaan |
| Sumber jawaban | pengetahuan model + berkas/alat | **hanya dokumen perusahaan** |
| Identitas | akun sendiri | login Ethoz yang sudah ada, tanpa akun AI |
| Kendali isi | pengguna | admin HC, per peran |
| Ukuran keberhasilan | kepuasan umum | jawaban tepat + celah pengetahuan tertutup |

Perbedaan paling menentukan: **Ethoz Chat sengaja dibatasi**. Claude AI dinilai
dari seberapa luas ia menjawab; Ethoz Chat dinilai dari seberapa **tidak**
mengarang di luar dokumen perusahaan. Sebagian butir "❌" di bawah adalah
keputusan sadar, bukan kekurangan.

---

## 1. PRD — cakupan produk

### 1a. Percakapan inti

| Kemampuan | Claude AI | Ethoz Chat | Catatan |
|---|---|---|---|
| Jawaban mengalir (streaming) | ✅ | ✅ | SSE, `POST /api/chatbot/stream` |
| Render markdown | ✅ | ✅ | tebal, daftar, `kode`, kutipan |
| Riwayat percakapan | ✅ | ✅ | daftar + buka percakapan lama |
| Percakapan baru | ✅ | ✅ | tombol `+` di header |
| Salin jawaban | ✅ | ✅ | per gelembung |
| Ulangi jawaban | ✅ | ✅ | jawaban lama dibuang, dialirkan ulang |
| Hentikan di tengah | ✅ | ✅ | langganan dibatalkan, koneksi ditutup |
| Penilaian jawaban | ✅ | ✅ | 👍/👎, masuk ke pemantauan |
| Sunting pertanyaan terkirim | ✅ | ❌ | bisa disiasati dengan "ulangi" |
| Cabang percakapan | ✅ | ❌ | jarang dipakai di konteks HC |
| Unggah berkas oleh pengguna | ✅ | ❌ | disengaja: isi dikurasi admin |
| Pencarian dalam riwayat | ✅ | ❌ | riwayat masih pendek |
| Artifacts / kanvas | ✅ | ➖ | di luar kebutuhan |
| Suara / gambar | ✅ | ➖ | di luar kebutuhan |

### 1b. Pengetahuan & sumber

| Kemampuan | Claude AI | Ethoz Chat | Catatan |
|---|---|---|---|
| Pencarian potongan relevan | ✅ | ✅ | leksikal TF-IDF, per paragraf |
| Sebut sumber jawaban | ✅ | ✅ | judul dokumen di dalam kalimat |
| Unggah dokumen (admin) | ➖ | ✅ | PDF, DOCX, TXT, MD, CSV, gambar |
| Baca tabel dokumen | 🟡 | ✅ | tabel Word jadi baris `\| sel \|` |
| OCR dokumen pindai | ✅ | 🟡 | siap pakai; Tesseract belum terpasang |
| Pencarian semantik (embedding) | ✅ | ❌ | leksikal dulu — nol biaya, tanpa API |
| Kendali akses per peran | ➖ | ✅ | `all / hr / manager / hr_manager` |

### 1c. Administrasi & pemantauan

| Kemampuan | Claude AI | Ethoz Chat |
|---|---|---|
| Konsol admin isi pengetahuan | ➖ | ✅ |
| Atur nada, sapaan, bahasa, panjang | ➖ | ✅ |
| Pratinjau instruksi AI per peran | ➖ | ✅ |
| Metrik pemakaian & biaya | 🟡 (tingkat org) | ✅ |
| Latensi p50/p95 | ➖ | ✅ |
| Daftar celah pengetahuan | ➖ | ✅ |
| Transkrip percakapan | 🟡 | ✅ |

> Pemantauan adalah tempat Ethoz Chat **melampaui** Claude AI, karena
> pertanyaannya berbeda: bukan "apakah jawabannya bagus", melainkan
> "dokumen apa yang belum kami tulis".

---

## 2. Alur kerja

### 2a. Pegawai bertanya

| Langkah | Claude AI | Ethoz Chat |
|---|---|---|
| Masuk | akun Claude | login Ethoz yang sudah ada — tanpa akun AI |
| Mulai | kotak kosong + saran | sapaan + 4 saran pertanyaan |
| Mengetik | kirim / Enter | sama |
| Menunggu | teks mengalir langsung | teks mengalir langsung |
| Menyela | tombol stop | tombol stop |
| Menindaklanjuti | konteks percakapan | konteks percakapan (20 giliran) |
| Menilai | 👍/👎 | 👍/👎 → dasbor admin |

Perbedaan yang berarti: **tidak ada akun AI terpisah dan tidak ada kunci API di
sisi klien.** Token Sanctum milik pegawai dipakai apa adanya; kunci Anthropic
tidak pernah meninggalkan server.

### 2b. Admin mengelola

Tidak ada padanannya di Claude AI. Alurnya:

```
Unggah dokumen  →  teks diekstraksi (tabel + OCR)  →  atur cakupan peran
      ↓
Pratinjau instruksi AI per peran  →  simpan
      ↓
Pantau: celah pengetahuan → tulis dokumen baru → ulangi
```

Lingkaran umpan balik itu — **celah menjadi daftar kerja** — adalah inti nilai
produk ini dan tidak punya bandingan di Claude AI.

---

## 3. Perilaku

| Perilaku | Claude AI | Ethoz Chat | Catatan |
|---|---|---|---|
| Mengaku tidak tahu | ✅ | ✅ | diarahkan ke HC |
| Menolak mengarang | ✅ | ✅ | dilarang mengarang nomor/tanggal/nominal |
| Sebut sumber | ✅ | ✅ | judul dokumen |
| Tanya balik saat ambigu | ✅ | ✅ | satu pertanyaan penjelas |
| Nada dapat diatur | 🟡 | ✅ | formal/ramah/santai, sapaan, emoji |
| Bahasa dapat dipaksa | 🟡 | ✅ | ID / EN / ikut pengguna |
| Menjelaskan batasannya | ✅ | ❌ **sengaja** | lihat di bawah |
| Ingat lintas percakapan | ✅ | ❌ | konteks hanya dalam satu percakapan |

### Perbedaan perilaku yang disengaja

Claude AI **menjelaskan** ketika sesuatu di luar jangkauannya. Ethoz Chat
justru **dilarang** melakukan itu: menyebut "ada data yang tidak bisa saya
akses" lalu mendaftar kategorinya sama saja membagikan peta batasan sistem
kepada pegawai. Perilakunya sekarang: arahkan singkat ke HC, tanpa alasan.

Ini **divergensi sadar dari Claude AI**, atas permintaan eksplisit. Yang hilang
hanya pengumumannya — perlindungan datanya tetap utuh dan diuji.

---

## 4. Jarak yang tersisa

Diurutkan menurut nilai per usaha.

| # | Celah | Dampak | Usaha | Catatan |
|---|---|---|---|---|
| 1 | Tesseract belum terpasang | dokumen pindai & gambar tertolak | **kecil** | satu perintah `winget` |
| 2 | Sunting pertanyaan terkirim | salah ketik harus diulang manual | kecil | |
| 3 | Pencarian dalam riwayat | terasa saat riwayat menumpuk | sedang | |
| 4 | Retrieval semantik (embedding) | pertanyaan berparafrase meleset | sedang | butuh penyedia embedding + biaya |
| 5 | Prompt caching | hemat biaya & latensi | sedang | prefix stabil masih kecil |
| 6 | Ingatan lintas percakapan | "seperti yang saya tanya kemarin" | besar | perlu keputusan privasi |

### Yang sengaja TIDAK dikejar

- **Unggah berkas oleh pegawai** — akan melubangi kurasi admin dan kendali peran.
- **Artifacts, suara, gambar** — di luar kebutuhan asisten kepegawaian.
- **Cabang percakapan** — kerumitan tanpa nilai di konteks HC.

---

## 5. Cara memverifikasi

```bash
# Backend: 88 pengujian
cd backend && php artisan test && ./vendor/bin/pint --test

# Aplikasi pegawai: 10 pengujian
cd mobile/ethoz_chat && flutter analyze && flutter test

# Tinjau tampilan tanpa backend
flutter run -d chrome --dart-define=ETHOZ_DEMO=true
```

### Batas pengujian yang jujur

Aliran jawaban diuji dengan **aliran SSE tiruan**, bukan API Anthropic
sungguhan — saldo kunci API sedang nol, sehingga jalur ujung-ke-ujung dengan
model asli belum pernah dijalankan. Bentuk peristiwa yang ditiru mengikuti
dokumentasi Anthropic (`message_start`, `content_block_delta`, `message_delta`,
`message_stop`) dan penanganan potongan terbelah ikut diuji.
