# Web Prototype — Ethoz Chat

Prototipe dua sisi dalam satu berkas React (`ethoz-chatbot-system.jsx`):

- **Konsol Admin** — kelola identitas bot, basis pengetahuan (unggah dokumen atau input manual), akses per peran, batasan, dan gaya bahasa. Semua pengaturan tampil sebagai pratinjau *system prompt* yang berubah langsung.
- **Tampilan Pegawai** — chat yang mengikuti pengaturan admin dan peran pegawai yang sedang login.

## Catatan penting

Berkas ini dibuat untuk berjalan sebagai *artifact* di Claude.ai (memakai pemanggilan model tanpa API key, pustaka bawaan seperti `mammoth`, dan Tailwind base). Ini adalah prototipe UI + alur, **bukan aplikasi produksi**. Untuk produksi, ganti pemanggilan model dengan panggilan ke backend Laravel (`../backend`).
