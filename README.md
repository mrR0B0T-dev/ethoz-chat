# Ethoz Chat

Asisten AI untuk pegawai di dalam aplikasi **Ethoz** (Employee Self-Service HCIS). Pegawai bertanya seputar kebijakan perusahaan, layanan HC (cuti, presensi, e-slip), serta job description & struktur organisasi — cukup lewat login Ethoz yang sudah ada, **tanpa akun AI terpisah**.

## Arsitektur

- **Backend (Laravel)** — proxy aman: menyusun instruksi AI dari basis pengetahuan + peran pegawai, lalu meneruskan ke Claude API dengan API key yang **tersembunyi di server**. Lihat `backend/`.
- **Admin (web)** — konsol untuk mengelola basis pengetahuan (termasuk unggah dokumen), akses per peran, batasan, dan gaya bahasa. Semua dikompilasi menjadi *system prompt*.
- **Pegawai (Flutter)** — layar chat di aplikasi mobile Ethoz yang memanggil backend memakai token Sanctum. Lihat `mobile/`.

Pemisahan peran: pegawai memakai chat di aplikasi, admin/HC mengelola isi lewat web. Otak yang sama berada di backend.

## Struktur folder

- `web-prototype/` — prototipe dua sisi (Konsol Admin + Tampilan Pegawai) untuk demo cepat.
- `mobile/` — layar chat Flutter untuk aplikasi Ethoz + entry point uji.
- `backend/` — panduan implementasi backend Laravel + contoh variabel lingkungan.

## Menjalankan

Lihat README di masing-masing folder. Ringkasnya:
- **Mobile:** `flutter create`, salin isi `mobile/` ke `lib/`, `flutter pub add http`, `flutter run`.
- **Backend:** ikuti `backend/IMPLEMENTATION.md`; salin `backend/.env.example` menjadi `.env` lalu isi `ANTHROPIC_API_KEY`.

## Catatan

- Jangan pernah commit `.env` atau API key. Kunci hanya ada di server.
- `web-prototype/` memakai runtime artifact (memanggil model tanpa key) hanya untuk demo. Di produksi, sisi web memanggil backend Laravel.
