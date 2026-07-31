{{-- Konsol Admin Ethoz Chat — resources/views/admin/chatbot.blade.php
     Route: GET /admin/chatbot (auth + can:manage-chatbot). Lihat IMPLEMENTATION.md §11.
     Token desain mengikuti prototipe web (web-prototype/ethoz-chatbot-system.jsx)
     dan header aplikasi Flutter, agar konsisten di seluruh produk Ethoz. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#062A52">
    <title>Konsol Admin — Ethoz Chat</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');

        /* ── Token merek Ethoz ─────────────────────────────────────── */
        :root {
            --navy: #062A52;
            --blue: #0F5AA8;
            --azure: #1E7BD6;
            --accent: #2E90E4;
            --sky: #63BDF5;
            --ink: #0A1A2B;
            --muted: #6B7E92;
            --line: #E3ECF5;
            --soft: #F1F6FC;
            --field: #F7FAFE;
            --danger: #C0554F;

            --grad-brand: linear-gradient(120deg, #062A52 0%, #0F5AA8 60%, #1E7BD6 100%);
            --grad-accent: linear-gradient(135deg, #2E90E4, #0F5AA8);
            --grad-sky: linear-gradient(135deg, #63BDF5, #2E90E4);

            --r-sm: 9px;
            --r-md: 12px;
            --r-lg: 14px;
            --r-xl: 18px;

            --sh-card: 0 6px 18px rgba(6, 42, 82, .05);
            --sh-lift: 0 10px 26px rgba(6, 42, 82, .09);
            --sh-bar: 0 6px 20px rgba(6, 42, 82, .18);
            --ring: 0 0 0 3px rgba(46, 144, 228, .18);

            --ease: cubic-bezier(.22, .61, .36, 1);
        }

        * {
            box-sizing: border-box
        }

        html {
            scroll-behavior: smooth
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding-bottom: 60px;
            background: linear-gradient(165deg, #E8F2FD 0%, #F2F8FE 55%, #E9F1FB 100%) fixed;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Topbar ────────────────────────────────────────────────── */
        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            padding: 13px 20px;
            color: #fff;
            background: var(--grad-brand);
            box-shadow: var(--sh-bar);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .brand .dot {
            width: 34px;
            height: 34px;
            flex: 0 0 auto;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--grad-sky);
            box-shadow: 0 4px 14px rgba(99, 189, 245, .35);
        }

        .brand h1 {
            font-family: 'Fredoka', sans-serif;
            font-weight: 600;
            font-size: 16px;
            margin: 0;
            line-height: 1.15;
            letter-spacing: .1px;
        }

        .brand .sub {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            font-size: 11.5px;
            opacity: .85;
        }

        /* Titik "aktif" — gema dari header aplikasi Flutter. */
        .live {
            width: 7px;
            height: 7px;
            flex: 0 0 auto;
            border-radius: 50%;
            background: var(--sky);
            box-shadow: 0 0 8px var(--sky);
        }

        /* Perpindahan antar halaman admin — mengikuti pola segmented control
           pada prototipe web Ethoz. */
        .seg {
            display: flex;
            background: rgba(255, 255, 255, .14);
            border-radius: var(--r-md);
            padding: 4px;
            gap: 4px;
        }

        .seg a {
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, .85);
            font: 600 13.5px 'Inter', sans-serif;
            padding: 7px 14px;
            border-radius: var(--r-sm);
            cursor: pointer;
            text-decoration: none;
            transition: all .15s var(--ease);
        }

        .seg a:hover {
            color: #fff;
            background: rgba(255, 255, 255, .12);
        }

        .seg a.on {
            background: #fff;
            color: var(--navy);
            box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
        }

        .bar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .who {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 6px 12px 6px 7px;
            border-radius: 999px;
            /* Kaca buram di atas biru — mengikuti kartu presensi Ethoz. */
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .28);
            backdrop-filter: blur(6px);
            font-size: 12.5px;
            font-weight: 500;
            white-space: nowrap;
        }

        .who .av {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--grad-sky);
            color: var(--navy);
            font-size: 11px;
            font-weight: 700;
        }

        .bar-actions form {
            margin: 0
        }

        /* ── Kartu & tipografi ─────────────────────────────────────── */
        .wrap {
            max-width: 780px;
            margin: 24px auto 0;
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .card {
            background: #fff;
            border-radius: var(--r-xl);
            padding: 18px;
            border: 1px solid rgba(6, 42, 82, .06);
            box-shadow: var(--sh-card);
            transition: box-shadow .22s var(--ease), transform .22s var(--ease);
        }

        .card:hover {
            box-shadow: var(--sh-lift);
        }

        .card h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            font-family: 'Fredoka', sans-serif;
            font-weight: 600;
            font-size: 16px;
            color: var(--navy);
            margin: 0;
        }

        /* Aksen kecil di kiri judul — pengikat visual antar kartu. */
        .card h2::before {
            content: '';
            width: 4px;
            height: 17px;
            border-radius: 999px;
            background: var(--grad-sky);
            flex: 0 0 auto;
        }

        .card .desc {
            font-size: 12.5px;
            color: var(--muted);
            margin: 3px 0 0 13px;
            line-height: 1.45;
        }

        label.lbl {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            margin: 13px 0 5px;
        }

        .muted {
            color: var(--muted)
        }

        .small {
            font-size: 11.5px
        }

        /* ── Kontrol form ──────────────────────────────────────────── */
        .in {
            width: 100%;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            color: var(--ink);
            padding: 10px 12px;
            border-radius: 11px;
            border: 1px solid #D7E3F2;
            outline: none;
            background: #fff;
            transition: border-color .16s var(--ease), box-shadow .16s var(--ease);
        }

        .in:hover:not(:focus) {
            border-color: #BFD5EC
        }

        .in:focus {
            border-color: var(--accent);
            box-shadow: var(--ring);
        }

        .in::placeholder {
            color: #A3B4C6
        }

        select.in {
            cursor: pointer;
            appearance: none;
            padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7B87' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
        }

        textarea.in {
            resize: vertical;
            line-height: 1.55;
            min-height: 44px;
        }

        .row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .row.end {
            justify-content: flex-end
        }

        /* ── Sakelar ───────────────────────────────────────────────── */
        .toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--line);
            cursor: pointer;
        }

        .toggle:hover span:first-child {
            color: var(--azure)
        }

        .toggle span {
            font-size: 13.5px;
            transition: color .16s var(--ease);
        }

        .sw {
            position: relative;
            width: 38px;
            height: 22px;
            flex: 0 0 auto;
        }

        .sw input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            cursor: pointer;
        }

        .sw .tr {
            position: absolute;
            inset: 0;
            border-radius: 999px;
            background: #C7D5E4;
            transition: background .18s var(--ease);
            pointer-events: none;
        }

        .sw .kn {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
            transition: transform .18s var(--ease);
        }

        .sw input:checked+.tr {
            background: var(--accent)
        }

        .sw input:checked+.tr .kn {
            transform: translateX(16px)
        }

        .sw input:focus-visible+.tr {
            box-shadow: var(--ring)
        }

        /* ── Tombol ────────────────────────────────────────────────── */
        .btn {
            border: none;
            border-radius: 11px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            cursor: pointer;
            color: #fff;
            background: var(--grad-accent);
            padding: 10px 16px;
            font-size: 13.5px;
            transition: transform .16s var(--ease), box-shadow .16s var(--ease), opacity .16s var(--ease);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15, 90, 168, .28);
        }

        .btn:active {
            transform: translateY(0)
        }

        .btn.sm {
            padding: 8px 14px;
            font-size: 12.5px;
        }

        .btn.ghost {
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .30);
            backdrop-filter: blur(6px);
        }

        .btn.ghost:hover {
            background: rgba(255, 255, 255, .28);
            box-shadow: none;
        }

        .btn.quiet {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .24);
            backdrop-filter: blur(6px);
            font-weight: 500;
        }

        .btn.quiet:hover {
            background: rgba(255, 255, 255, .16);
            box-shadow: none;
        }

        .btn[disabled] {
            opacity: .6;
            cursor: progress;
            transform: none;
            box-shadow: none;
        }

        :focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .topbar :focus-visible {
            outline-color: var(--sky)
        }

        /* ── Unggah dokumen ────────────────────────────────────────── */
        .dropzone {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 22px 16px;
            border-radius: var(--r-lg);
            border: 1.8px dashed #AFD2F0;
            background: #EFF7FE;
            cursor: pointer;
            transition: background .18s var(--ease), border-color .18s var(--ease), transform .18s var(--ease);
        }

        .dropzone:hover,
        .dropzone.over {
            background: #E2F0FD;
            border-color: #84BEEC;
        }

        .dropzone.over {
            transform: scale(1.01);
            border-color: var(--accent);
        }

        .dropIcon {
            width: 42px;
            height: 42px;
            border-radius: var(--r-md);
            background: #DAEDFC;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: transform .18s var(--ease);
        }

        .dropzone:hover .dropIcon {
            transform: translateY(-2px)
        }

        /* ── Entri basis pengetahuan ───────────────────────────────── */
        .kb {
            background: var(--field);
            border-radius: 13px;
            padding: 12px;
            border: 1px solid #DEE9F5;
            margin-top: 12px;
            transition: border-color .18s var(--ease), background .18s var(--ease);
        }

        .kb:hover {
            border-color: #C8DCEF
        }

        .kb-head {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .badge {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .3px;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .badge.doc {
            color: #fff;
            background: var(--grad-accent);
        }

        .badge.man {
            color: var(--muted);
            background: #E6EDF5;
        }

        .del {
            width: 32px;
            height: 32px;
            border-radius: var(--r-sm);
            border: 1px solid #F0D8D8;
            background: #FDF3F3;
            color: var(--danger);
            cursor: pointer;
            flex: 0 0 auto;
            transition: background .16s var(--ease);
        }

        .del:hover {
            background: #FBE9E9
        }

        .add {
            margin-top: 12px;
            width: 100%;
            padding: 12px;
            border-radius: var(--r-md);
            border: 1.5px dashed #AFD2F0;
            background: #EFF7FE;
            color: var(--azure);
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: background .16s var(--ease), border-color .16s var(--ease);
        }

        .add:hover {
            background: #E2F0FD;
            border-color: #84BEEC;
        }

        /* Kerangka muat — kartu tidak lagi tampak kosong/rusak selagi memuat. */
        .skel {
            border-radius: 8px;
            background: linear-gradient(90deg, var(--soft) 25%, #E8F1FB 37%, var(--soft) 63%);
            background-size: 400% 100%;
            animation: shimmer 1.4s ease infinite;
        }

        @keyframes shimmer {
            0% { background-position: 100% 0 }
            100% { background-position: 0 0 }
        }

        .skel-row {
            height: 38px;
            margin-top: 10px;
        }

        /* Kondisi kosong — jangan biarkan kartu terasa "rusak" saat belum ada isi. */
        .empty {
            margin-top: 12px;
            padding: 20px 16px;
            border-radius: 13px;
            background: var(--soft);
            border: 1px solid var(--line);
            text-align: center;
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.5;
        }

        /* ── Pratinjau ─────────────────────────────────────────────── */
        pre.out {
            margin: 10px 0 0;
            background: var(--navy);
            color: #CFE6FF;
            padding: 14px;
            border-radius: var(--r-md);
            font-size: 11.5px;
            line-height: 1.55;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 340px;
            overflow: auto;
        }

        pre.out.idle {
            color: #7C97B4
        }

        /* ── Toast ─────────────────────────────────────────────────── */
        #toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            opacity: 0;
            background: var(--navy);
            color: #fff;
            padding: 11px 18px;
            border-radius: var(--r-md);
            font-size: 13.5px;
            box-shadow: 0 8px 24px rgba(6, 42, 82, .3);
            transition: opacity .25s var(--ease), transform .25s var(--ease);
            pointer-events: none;
            z-index: 20;
            max-width: 90%;
        }

        #toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        #toast.err {
            background: #8A2F2A
        }

        /* ── Layar kecil ───────────────────────────────────────────── */
        @media (max-width: 560px) {
            .topbar {
                padding: 12px 14px
            }

            .bar-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .who {
                margin-right: auto
            }

            .wrap {
                margin-top: 16px;
                padding: 0 12px;
            }

            .card {
                padding: 15px
            }
        }

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto
            }

            * {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>
</head>

<body>
    <header class="topbar">
        <div class="brand">
            <div class="dot">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M13 6.4l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9.9-2.1Z" fill="#062A52" />
                </svg>
            </div>
            <div>
                <h1>Ethoz Chat</h1>
                <div class="sub"><span class="live"></span><span id="brandSub">Konsol Admin</span></div>
            </div>
        </div>

        <nav class="seg">
            <a href="{{ route('admin.chatbot') }}" class="on" aria-current="page">Konsol</a>
            <a href="{{ route('admin.chatbot.monitor') }}">Pemantauan</a>
        </nav>

        <div class="bar-actions">
            <span class="who" title="{{ auth()->user()->email }}">
                <span class="av">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                {{ auth()->user()->name }}
            </span>
            <button class="btn ghost" id="saveSettings">Simpan Pengaturan</button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn quiet" type="submit">Keluar</button>
            </form>
        </div>
    </header>

    <div class="wrap">
        <!-- Identitas -->
        <section class="card">
            <h2>Identitas &amp; Peran Bot</h2>
            <p class="desc">Nama, perusahaan, dan peran asisten.</p>
            <label class="lbl" for="f_bot_name">Nama asisten</label>
            <input class="in" id="f_bot_name" placeholder="Ethoz Chat">
            <label class="lbl" for="f_company">Perusahaan</label>
            <input class="in" id="f_company" placeholder="PT Bumi Daya Plaza">
            <label class="lbl" for="f_role">Peran / deskripsi singkat</label>
            <textarea class="in" id="f_role" rows="2"
                placeholder="mis. Asisten HC &amp; informasi perusahaan untuk pegawai PT Bumi Daya Plaza."></textarea>
        </section>

        <!-- Basis Pengetahuan -->
        <section class="card">
            <h2>Basis Pengetahuan</h2>
            <p class="desc">Unggah dokumen (kebijakan, jobdesc, struktur organisasi) atau tambah manual. Atur akses
                per peran.</p>

            <div class="row" style="margin:12px 0">
                <span class="muted small">Akses dokumen yang diunggah:</span>
                <select class="in" id="upScope" style="width:auto">
                    <option value="all">Semua pegawai</option>
                    <option value="hr">HC saja</option>
                    <option value="manager">Manager saja</option>
                    <option value="hr_manager">HC &amp; Manager</option>
                </select>
            </div>

            <input type="file" id="fileInput" accept=".pdf,.docx,.txt,.md,.csv,.png,.jpg,.jpeg,.webp,.bmp,.tif,.tiff"
                multiple style="display:none">
            <div class="dropzone" id="dropzone" role="button" tabindex="0">
                <div class="dropIcon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 16V5m0 0L8 9m4-4 4 4" stroke="#1E7BD6" stroke-width="1.9" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" stroke="#1E7BD6" stroke-width="1.9"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <div style="font-weight:600;font-size:13.5px;color:var(--navy)">Unggah dokumen</div>
                <div class="muted small" style="margin-top:3px">Klik atau seret ke sini · PDF, DOCX, TXT, MD, CSV, gambar
                </div>
                <div class="muted small" style="margin-top:2px">Tabel ikut terbaca. Dokumen hasil pindai dibaca lewat OCR.
                </div>
            </div>

            <div id="kbList"></div>
            <button class="add" id="addManual">+ Tambah manual</button>
        </section>

        <!-- Batasan -->
        <section class="card">
            <h2>Batasan</h2>
            <p class="desc">Aturan main dan pagar pengaman bot. Disimpan bersama tombol Simpan Pengaturan.</p>
            <label class="toggle"><span>Larang mengarang (hanya jawab dari basis pengetahuan)</span>
                <span class="sw"><input type="checkbox" id="f_no_hallucination"><span class="tr"><span
                            class="kn"></span></span></span></label>
            <label class="toggle"><span>Lindungi data pribadi sensitif</span>
                <span class="sw"><input type="checkbox" id="f_protect_sensitive"><span class="tr"><span
                            class="kn"></span></span></span></label>
            <label class="lbl" for="f_max_length">Panjang jawaban</label>
            <select class="in" id="f_max_length">
                <option value="singkat">Singkat</option>
                <option value="sedang">Sedang</option>
                <option value="detail">Detail</option>
            </select>
            <label class="lbl" for="f_language">Bahasa</label>
            <select class="in" id="f_language">
                <option value="id">Bahasa Indonesia</option>
                <option value="en">English</option>
                <option value="follow">Ikuti bahasa pengguna</option>
            </select>
            <label class="lbl" for="f_blocked_topics">Topik yang dilarang (pisahkan dengan koma / baris baru)</label>
            <textarea class="in" id="f_blocked_topics" rows="2"
                placeholder="mis. gaji karyawan lain, gosip kantor"></textarea>
        </section>

        <!-- Gaya Bahasa -->
        <section class="card">
            <h2>Behaviour &amp; Gaya Bahasa</h2>
            <p class="desc">Kepribadian dan cara bot menjawab. Disimpan bersama tombol Simpan Pengaturan.</p>
            <label class="lbl" for="f_tone">Nada bicara</label>
            <select class="in" id="f_tone">
                <option value="formal">Formal &amp; resmi</option>
                <option value="ramah">Ramah profesional</option>
                <option value="santai">Santai &amp; akrab</option>
            </select>
            <label class="lbl" for="f_address">Sapaan</label>
            <select class="in" id="f_address">
                <option value="Anda">Anda</option>
                <option value="Kamu">Kamu</option>
            </select>
            <label class="toggle"><span>Boleh memakai emoji</span>
                <span class="sw"><input type="checkbox" id="f_emoji"><span class="tr"><span
                            class="kn"></span></span></span></label>
            <label class="toggle"><span>Boleh menjawab dengan poin</span>
                <span class="sw"><input type="checkbox" id="f_allow_bullets"><span class="tr"><span
                            class="kn"></span></span></span></label>
            <label class="lbl" for="f_extra">Instruksi tambahan (opsional)</label>
            <textarea class="in" id="f_extra" rows="2"
                placeholder="mis. selalu tutup jawaban dengan menawarkan bantuan lanjutan"></textarea>
        </section>

        <!-- Pratinjau -->
        <section class="card">
            <h2>Pratinjau Instruksi AI</h2>
            <p class="desc">Instruksi yang benar-benar diterima AI, dirakit dari data yang sudah tersimpan.
                Simpan pengaturan lebih dulu agar perubahan terbaru ikut terlihat.</p>
            <div class="row">
                <span class="muted small">Lihat sebagai peran:</span>
                <select class="in" id="pvRole" style="width:auto">
                    <option value="staff">Staff / Pegawai</option>
                    <option value="hr">HC</option>
                    <option value="manager">Manager</option>
                </select>
                <button class="btn sm" id="pvBtn">Muat pratinjau</button>
            </div>
            <pre class="out idle" id="pvOut">Belum dimuat — pilih peran lalu klik “Muat pratinjau”.</pre>
        </section>
    </div>

    <div id="toast" role="status" aria-live="polite"></div>

    <script>
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const base = '/admin/chatbot';
        const J = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CSRF
        };
        const ACCEPT = {
            'Accept': 'application/json'
        };
        const SCOPES = [{
                id: 'all',
                label: 'Semua pegawai'
            },
            {
                id: 'hr',
                label: 'HC saja'
            },
            {
                id: 'manager',
                label: 'Manager saja'
            },
            {
                id: 'hr_manager',
                label: 'HC & Manager'
            }
        ];
        let KB = [];

        const escHtml = s => String(s).replace(/[&<>]/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;'
        }[c]));
        const escAttr = s => String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;');
        const shorten = m => String(m).slice(0, 180);

        let toastTimer;

        function toast(msg, ok = true) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'show' + (ok ? '' : ' err');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.className = t.className.replace('show', '').trim(), 2600);
        }

        /* Kunci tombol selama permintaan berjalan agar tidak terkirim dua kali. */
        async function busy(btn, label, fn) {
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = label;
            try {
                return await fn();
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        }

        async function api(url, opts = {}) {
            const res = await fetch(url, opts);
            if (!res.ok) {
                let msg = res.status;
                try {
                    const j = await res.json();
                    msg = j.message || JSON.stringify(j.errors || j);
                } catch (e) {}
                throw new Error(msg);
            }
            return res.status === 204 ? null : res.json();
        }

        // ---- Settings ----
        const TEXT_FIELDS = ['bot_name', 'company', 'role', 'extra', 'blocked_topics', 'tone', 'address', 'max_length',
            'language'
        ];
        const BOOL_FIELDS = ['emoji', 'allow_bullets', 'no_hallucination', 'protect_sensitive'];

        function fillSettings(s) {
            TEXT_FIELDS.forEach(k => {
                const el = document.getElementById('f_' + k);
                if (el) el.value = s[k] ?? '';
            });
            BOOL_FIELDS.forEach(k => {
                const el = document.getElementById('f_' + k);
                if (el) el.checked = !!s[k];
            });
            // Cerminkan perusahaan di header — sejajar dengan "Online • PT BDP" di aplikasi.
            const sub = document.getElementById('brandSub');
            sub.textContent = s.company ? `Konsol Admin · ${s.company}` : 'Konsol Admin';
        }

        function readSettings() {
            const o = {};
            TEXT_FIELDS.forEach(k => o[k] = document.getElementById('f_' + k).value);
            BOOL_FIELDS.forEach(k => o[k] = document.getElementById('f_' + k).checked);
            return o;
        }

        async function loadSettings() {
            try {
                fillSettings(await api(base + '/settings', {
                    headers: ACCEPT
                }));
            } catch (e) {
                toast('Gagal memuat pengaturan: ' + shorten(e.message), false);
            }
        }

        async function saveSettings(ev) {
            await busy(ev.currentTarget, 'Menyimpan…', async () => {
                try {
                    fillSettings(await api(base + '/settings', {
                        method: 'PUT',
                        headers: J,
                        body: JSON.stringify(readSettings())
                    }));
                    toast('Pengaturan tersimpan');
                } catch (e) {
                    toast('Gagal menyimpan: ' + shorten(e.message), false);
                }
            });
        }

        // ---- Knowledge ----
        async function loadKnowledge() {
            try {
                KB = await api(base + '/knowledge', {
                    headers: ACCEPT
                });
                renderKB();
            } catch (e) {
                toast('Gagal memuat pengetahuan: ' + shorten(e.message), false);
            }
        }

        function badge(src) {
            return src === 'document' ?
                '<span class="badge doc">Dokumen</span>' : '<span class="badge man">Manual</span>';
        }

        function skeleton(rows = 3) {
            return '<div>' + Array.from({ length: rows },
                () => '<div class="skel skel-row"></div>').join('') + '</div>';
        }

        function renderKB() {
            const list = document.getElementById('kbList');
            if (!KB.length) {
                list.innerHTML =
                    '<div class="empty">Belum ada informasi tersimpan.<br>Unggah dokumen di atas atau tambah manual — bot hanya menjawab dari yang tercantum di sini.</div>';
                return;
            }
            list.innerHTML = KB.map((e, i) => `
        <div class="kb" data-idx="${i}">
          <div class="kb-head">
            ${badge(e.source || 'manual')}
            <input class="in kb-title" style="flex:1;font-weight:600" value="${escAttr(e.title || '')}" placeholder="Judul informasi">
            <button class="del kb-del" title="Hapus" aria-label="Hapus informasi">&#10005;</button>
          </div>
          ${e.source === 'document' ? `<div class="muted small" style="margin-top:6px">${escHtml(e.file_name || '')} · ${(e.content || '').length} karakter</div>` : ''}
          <div class="row"><span class="muted small">Akses:</span>
            <select class="in kb-scope" style="flex:1">
              ${SCOPES.map(s => `<option value="${s.id}" ${e.scope === s.id ? 'selected' : ''}>${s.label}</option>`).join('')}
            </select>
          </div>
          <textarea class="in kb-content" style="margin-top:8px" rows="${e.source === 'document' ? 4 : 3}" placeholder="Isi informasi…">${escHtml(e.content || '')}</textarea>
          <div class="row end"><button class="btn sm kb-save">Simpan</button></div>
        </div>`).join('');
        }

        async function saveEntry(idx, card, btn) {
            const e = KB[idx];
            const payload = {
                title: card.querySelector('.kb-title').value.trim(),
                content: card.querySelector('.kb-content').value,
                scope: card.querySelector('.kb-scope').value,
                is_active: true,
            };
            await busy(btn, 'Menyimpan…', async () => {
                try {
                    if (e.id) await api(`${base}/knowledge/${e.id}`, {
                        method: 'PUT',
                        headers: J,
                        body: JSON.stringify(payload)
                    });
                    else await api(`${base}/knowledge`, {
                        method: 'POST',
                        headers: J,
                        body: JSON.stringify(payload)
                    });
                    await loadKnowledge();
                    toast('Informasi tersimpan');
                } catch (err) {
                    toast('Gagal menyimpan: ' + shorten(err.message), false);
                }
            });
        }

        async function delEntry(idx) {
            const e = KB[idx];
            if (!e.id) {
                KB.splice(idx, 1);
                renderKB();
                return;
            }
            if (!confirm('Hapus informasi ini?')) return;
            try {
                await api(`${base}/knowledge/${e.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        ...ACCEPT
                    }
                });
                await loadKnowledge();
                toast('Dihapus');
            } catch (err) {
                toast('Gagal menghapus: ' + shorten(err.message), false);
            }
        }

        async function uploadDoc(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('scope', document.getElementById('upScope').value);
            toast('Mengunggah ' + file.name + '…');
            try {
                const res = await fetch(`${base}/documents`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        ...ACCEPT
                    },
                    body: fd
                });
                if (!res.ok) {
                    let m = res.status;
                    try {
                        const j = await res.json();
                        m = j.message || m;
                    } catch (e) {}
                    throw new Error(m);
                }
                let created = {};
                try {
                    created = await res.json();
                } catch (e) {}
                await loadKnowledge();
                // Berkas tetap tersimpan, tapi ada bagian yang mungkin belum terbaca.
                if (created.notice) toast(created.notice, false);
                else toast(`${file.name} ditambahkan · ${created.char_count ?? 0} karakter`);
            } catch (err) {
                toast('Gagal unggah: ' + shorten(err.message), false);
            }
        }

        // ---- Pratinjau ----
        async function preview(ev) {
            const role = document.getElementById('pvRole').value;
            const out = document.getElementById('pvOut');
            await busy(ev.currentTarget, 'Memuat…', async () => {
                try {
                    const r = await api(`${base}/preview?role=${role}`, {
                        headers: ACCEPT
                    });
                    out.textContent = r.prompt;
                    out.classList.remove('idle');
                } catch (err) {
                    out.textContent = 'Gagal memuat pratinjau: ' + shorten(err.message);
                    out.classList.add('idle');
                }
            });
        }

        // ---- Wiring ----
        document.getElementById('saveSettings').addEventListener('click', saveSettings);

        // Ctrl/Cmd+S menyimpan pengaturan — kebiasaan yang diharapkan di konsol
        // yang banyak diisi dengan mengetik.
        addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                document.getElementById('saveSettings').click();
            }
        });
        document.getElementById('addManual').addEventListener('click', () => {
            KB.push({
                id: null,
                title: '',
                scope: 'all',
                content: '',
                source: 'manual'
            });
            renderKB();
            const last = document.querySelector('#kbList .kb:last-child .kb-title');
            if (last) last.focus({
                preventScroll: false
            });
        });
        document.getElementById('kbList').addEventListener('click', e => {
            const card = e.target.closest('.kb');
            if (!card) return;
            const idx = +card.dataset.idx;
            if (e.target.classList.contains('kb-save')) saveEntry(idx, card, e.target);
            if (e.target.classList.contains('kb-del')) delEntry(idx);
        });

        const fileInput = document.getElementById('fileInput');
        const dropzone = document.getElementById('dropzone');
        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });
        dropzone.addEventListener('dragover', e => {
            e.preventDefault();
            dropzone.classList.add('over');
        });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('over'));
        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            dropzone.classList.remove('over');
            [...e.dataTransfer.files].forEach(uploadDoc);
        });
        fileInput.addEventListener('change', e => {
            [...e.target.files].forEach(uploadDoc);
            e.target.value = '';
        });
        document.getElementById('pvBtn').addEventListener('click', preview);

        document.getElementById('kbList').innerHTML = skeleton();
        loadSettings();
        loadKnowledge();
    </script>
</body>

</html>
