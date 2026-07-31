{{-- Pemantauan Kinerja Ethoz Chat — resources/views/admin/monitor.blade.php
     Route: GET /admin/chatbot/monitor (auth + can:manage-chatbot).
     Warna status divalidasi untuk keterbacaan penyandang buta warna;
     tiap segmen selalu disertai label teks, tidak mengandalkan warna saja. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#062A52">
    <title>Pemantauan — Ethoz Chat</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');

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

            /* Warna status — cadangan khusus, tidak dipakai sebagai warna seri. */
            --good: #1E8E6A;
            --warn: #D9A404;
            --crit: #C0392B;

            --grad-brand: linear-gradient(120deg, #062A52 0%, #0F5AA8 60%, #1E7BD6 100%);
            --grad-accent: linear-gradient(135deg, #2E90E4, #0F5AA8);
            --grad-sky: linear-gradient(135deg, #63BDF5, #2E90E4);

            --r-md: 12px;
            --r-xl: 18px;
            --sh-card: 0 6px 18px rgba(6, 42, 82, .05);
            --sh-bar: 0 6px 20px rgba(6, 42, 82, .18);
            --ring: 0 0 0 3px rgba(46, 144, 228, .18);
            --ease: cubic-bezier(.22, .61, .36, 1);
        }

        * { box-sizing: border-box }

        body {
            margin: 0;
            min-height: 100vh;
            padding-bottom: 60px;
            background: linear-gradient(165deg, #E8F2FD 0%, #F2F8FE 55%, #E9F1FB 100%) fixed;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Topbar ────────────────────────────────────────────── */
        .topbar {
            position: sticky; top: 0; z-index: 10;
            display: flex; flex-wrap: wrap; gap: 12px;
            align-items: center; justify-content: space-between;
            padding: 13px 20px; color: #fff;
            background: var(--grad-brand); box-shadow: var(--sh-bar);
        }
        .brand { display: flex; align-items: center; gap: 11px }
        .brand .dot {
            width: 34px; height: 34px; flex: 0 0 auto; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: var(--grad-sky); box-shadow: 0 4px 14px rgba(99, 189, 245, .35);
        }
        .brand h1 {
            font-family: 'Fredoka', sans-serif; font-weight: 600;
            font-size: 16px; margin: 0; line-height: 1.15;
        }
        .brand .sub { margin-top: 2px; font-size: 11.5px; opacity: .85 }

        .seg { display: flex; background: rgba(255,255,255,.14); border-radius: var(--r-md); padding: 4px; gap: 4px }
        .seg a {
            border: none; background: transparent; color: rgba(255,255,255,.85);
            font: 600 13.5px 'Inter', sans-serif; padding: 7px 14px; border-radius: 9px;
            cursor: pointer; text-decoration: none; transition: all .15s var(--ease);
        }
        .seg a:hover { color: #fff; background: rgba(255,255,255,.12) }
        .seg a.on { background: #fff; color: var(--navy); box-shadow: 0 2px 8px rgba(0,0,0,.15) }

        .bar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap }
        .who {
            display: flex; align-items: center; gap: 7px;
            padding: 6px 12px 6px 7px; border-radius: 999px;
            background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28);
            backdrop-filter: blur(6px); font-size: 12.5px; font-weight: 500; white-space: nowrap;
        }
        .who .av {
            width: 22px; height: 22px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--grad-sky); color: var(--navy); font-size: 11px; font-weight: 700;
        }
        .bar-actions form { margin: 0 }
        .btn {
            border: none; border-radius: 11px; font: 600 13.5px 'Inter', sans-serif;
            cursor: pointer; color: #fff; background: var(--grad-accent);
            padding: 10px 16px; transition: all .16s var(--ease);
        }
        .btn.quiet {
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(6px); font-weight: 500;
        }
        .btn.quiet:hover { background: rgba(255,255,255,.16) }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px }
        .topbar :focus-visible { outline-color: var(--sky) }

        /* ── Tata letak ────────────────────────────────────────── */
        .wrap {
            max-width: 1040px; margin: 22px auto 0; padding: 0 16px;
            display: flex; flex-direction: column; gap: 16px;
        }
        .card {
            background: #fff; border-radius: var(--r-xl); padding: 18px;
            border: 1px solid rgba(6,42,82,.06); box-shadow: var(--sh-card);
        }
        .card h2 {
            display: flex; align-items: center; gap: 9px; margin: 0;
            font-family: 'Fredoka', sans-serif; font-weight: 600; font-size: 16px; color: var(--navy);
        }
        .card h2::before {
            content: ''; width: 4px; height: 17px; border-radius: 999px;
            background: var(--grad-sky); flex: 0 0 auto;
        }
        .card .desc { font-size: 12.5px; color: var(--muted); margin: 3px 0 0 13px; line-height: 1.45 }
        .muted { color: var(--muted) }
        .small { font-size: 11.5px }

        /* Penyaring rentang — satu baris di atas seluruh grafik. */
        .filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap }
        .chip {
            border: 1px solid var(--line); background: #fff; color: var(--muted);
            font: 500 12.5px 'Inter', sans-serif; padding: 7px 14px; border-radius: 999px;
            cursor: pointer; transition: all .16s var(--ease);
        }
        .chip:hover { border-color: var(--accent); color: var(--azure) }
        .chip.on { background: var(--navy); border-color: var(--navy); color: #fff; font-weight: 600 }

        /* ── Ubin statistik (angka utama, bukan grafik) ────────── */
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px }
        .tile {
            background: #fff; border-radius: var(--r-xl); padding: 15px 16px;
            border: 1px solid rgba(6,42,82,.06); box-shadow: var(--sh-card);
        }
        /* min-height menyamakan garis dasar angka walau labelnya turun ke baris kedua. */
        .tile .k { font-size: 11.5px; color: var(--muted); font-weight: 500; min-height: 30px; line-height: 1.3 }
        .tile .v {
            font-family: 'Fredoka', sans-serif; font-weight: 600;
            font-size: 25px; color: var(--navy); line-height: 1.15; margin-top: 5px;
        }
        .tile .u { font-size: 12px; color: var(--muted); font-weight: 500; margin-left: 3px }
        .tile .n { font-size: 11.5px; color: var(--muted); margin-top: 3px }

        /* ── Grafik batang harian (satu seri: tanpa legenda) ───── */
        .chart { margin-top: 14px }
        .plot {
            display: flex; align-items: flex-end; gap: 2px;
            height: 150px; padding-top: 6px;
            border-bottom: 1px solid var(--line);   /* sumbu resesif */
        }
        .bar { flex: 1 1 0; min-width: 3px; position: relative; height: 100%;
               display: flex; align-items: flex-end; cursor: default }
        .bar i {
            display: block; width: 100%;
            background: var(--azure);
            border-radius: 4px 4px 0 0;             /* ujung data membulat 4px */
            transition: background .14s var(--ease);
            min-height: 2px;
        }
        .bar[data-zero="1"] i { background: var(--line) }
        .bar:hover i { background: var(--navy) }
        .xaxis { display: flex; justify-content: space-between; margin-top: 7px }
        .xaxis span { font-size: 11px; color: var(--muted) }

        /* ── Rincian hasil (3 status: legenda + label langsung) ── */
        .stack { display: flex; gap: 2px; margin-top: 14px; height: 26px; border-radius: 6px; overflow: hidden }
        .stack i { display: block; height: 100%; min-width: 3px }
        .sw-good { background: var(--good) }
        .sw-warn { background: var(--warn) }
        .sw-crit { background: var(--crit) }
        .legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px }
        .legend div { display: flex; align-items: center; gap: 7px; font-size: 12.5px }
        .legend b { width: 11px; height: 11px; border-radius: 3px; flex: 0 0 auto }
        .legend span { color: var(--muted) }
        .legend strong { color: var(--ink); font-weight: 600 }

        /* ── Daftar peringkat & celah ──────────────────────────── */
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px }
        @media (max-width: 780px) { .cols { grid-template-columns: 1fr } }
        .rank { margin-top: 12px; display: flex; flex-direction: column; gap: 9px }
        .rank .row { display: flex; align-items: center; gap: 10px; font-size: 12.5px }
        .rank .name { flex: 0 0 42%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
        .rank .track { flex: 1; height: 9px; background: var(--soft); border-radius: 999px; overflow: hidden }
        .rank .fill { height: 100%; background: var(--azure); border-radius: 999px }
        .rank .n { color: var(--muted); min-width: 24px; text-align: right }

        .gap-item {
            padding: 9px 11px; border-radius: 10px; background: var(--field);
            border: 1px solid var(--line); font-size: 12.5px; margin-top: 8px;
        }
        .gap-item .when { color: var(--muted); font-size: 11px; margin-top: 3px }

        /* ── Tabel percakapan ──────────────────────────────────── */
        .tablewrap { overflow-x: auto; margin-top: 12px }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 620px }
        th {
            text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
            color: var(--muted); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--line);
        }
        td { padding: 10px; border-bottom: 1px solid var(--line); vertical-align: middle }
        tbody tr { cursor: pointer; transition: background .14s var(--ease) }
        tbody tr:hover { background: var(--soft) }
        .pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10.5px; font-weight: 700; letter-spacing: .3px;
            padding: 3px 8px; border-radius: 6px; white-space: nowrap;
        }
        .pill.ok { color: #10553F; background: #DCF2EA }
        .pill.gap { color: #7A5B02; background: #FBF0CE }
        .pill.err { color: #8A2F2A; background: #FBE3E1 }

        /* ── Tooltip & transkrip ───────────────────────────────── */
        #tip {
            position: fixed; z-index: 40; pointer-events: none; opacity: 0;
            background: var(--navy); color: #fff; padding: 7px 11px; border-radius: 9px;
            font-size: 11.5px; line-height: 1.45; box-shadow: 0 8px 24px rgba(6,42,82,.3);
            transition: opacity .12s var(--ease); white-space: nowrap;
        }
        #tip.on { opacity: 1 }

        .sheet {
            position: fixed; inset: 0; z-index: 50; display: none;
            background: rgba(6,42,82,.45); padding: 24px 16px; overflow: auto;
        }
        .sheet.on { display: block }
        .sheet .inner {
            max-width: 680px; margin: 0 auto; background: #fff;
            border-radius: var(--r-xl); padding: 20px;
        }
        .msg { padding: 11px 13px; border-radius: 13px; margin-top: 10px; font-size: 13px; line-height: 1.5 }
        .msg.user { background: var(--soft); border: 1px solid var(--line) }
        .msg.bot { background: #fff; border: 1px solid var(--line) }
        .msg .meta { font-size: 11px; color: var(--muted); margin-top: 7px }

        .empty {
            margin-top: 12px; padding: 22px 16px; border-radius: 13px;
            background: var(--soft); border: 1px solid var(--line);
            text-align: center; color: var(--muted); font-size: 12.5px; line-height: 1.5;
        }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important } }
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
                <div class="sub">Pemantauan kinerja</div>
            </div>
        </div>

        <nav class="seg">
            <a href="{{ route('admin.chatbot') }}">Konsol</a>
            <a href="{{ route('admin.chatbot.monitor') }}" class="on" aria-current="page">Pemantauan</a>
        </nav>

        <div class="bar-actions">
            <span class="who" title="{{ auth()->user()->email }}">
                <span class="av">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                {{ auth()->user()->name }}
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn quiet" type="submit">Keluar</button>
            </form>
        </div>
    </header>

    <div class="wrap">
        <div class="filters">
            <span class="muted small">Rentang:</span>
            <button class="chip" data-days="7">7 hari</button>
            <button class="chip on" data-days="30">30 hari</button>
            <button class="chip" data-days="90">90 hari</button>
            <span class="muted small" id="rangeNote" style="margin-left:auto"></span>
        </div>

        <div class="tiles" id="tiles"></div>

        <section class="card">
            <h2>Volume Pertanyaan</h2>
            <p class="desc">Jumlah pertanyaan pegawai per hari.</p>
            <div class="chart">
                <div class="plot" id="plot"></div>
                <div class="xaxis" id="xaxis"></div>
            </div>
        </section>

        <section class="card">
            <h2>Hasil Jawaban</h2>
            <p class="desc">Setiap jawaban dikelompokkan menurut apa yang terjadi saat menjawab.</p>
            <div class="stack" id="stack"></div>
            <div class="legend" id="legend"></div>
        </section>

        <div class="cols">
            <section class="card">
                <h2>Celah Pengetahuan</h2>
                <p class="desc">Pertanyaan yang tidak menemukan dokumen apa pun. Inilah daftar dokumen
                    yang paling layak ditambahkan berikutnya.</p>
                <div id="gaps"></div>
            </section>

            <section class="card">
                <h2>Dokumen Paling Dirujuk</h2>
                <p class="desc">Dokumen yang paling sering dipakai menjawab.</p>
                <div id="sources"></div>
            </section>
        </div>

        <section class="card">
            <h2>Percakapan Terakhir</h2>
            <p class="desc">Klik satu baris untuk membaca transkripnya.</p>
            <div class="filters" style="margin-top:12px">
                <button class="chip on" data-problems="0">Semua</button>
                <button class="chip" data-problems="1">Hanya yang bermasalah</button>
            </div>
            <div class="tablewrap" id="convos"></div>
        </section>
    </div>

    <div id="tip"></div>

    <div class="sheet" id="sheet">
        <div class="inner">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                <h2 style="font-family:Fredoka,sans-serif;font-size:16px;color:var(--navy);margin:0" id="sheetTitle"></h2>
                <button class="btn" id="sheetClose">Tutup</button>
            </div>
            <div id="sheetBody"></div>
        </div>
    </div>

    <script>
        const base = '/admin/chatbot';
        const ACCEPT = { headers: { 'Accept': 'application/json' } };
        let days = 30;
        let problemsOnly = false;

        const esc = s => String(s ?? '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

        // Nilai peran di basis data tetap 'hr' (milik sistem Ethoz); yang
        // ditampilkan ke admin memakai istilah HC.
        const ROLE_LABELS = { staff: 'Staff', hr: 'HC', manager: 'Manager', admin: 'Admin' };
        const roleLabel = r => ROLE_LABELS[r] ?? r;
        const num = n => Number(n || 0).toLocaleString('id-ID');
        const pct = (n, d) => d > 0 ? Math.round((n / d) * 100) : 0;

        async function api(url) {
            const res = await fetch(url, ACCEPT);
            if (!res.ok) throw new Error(res.status);
            return res.json();
        }

        // ── Tooltip melayang ────────────────────────────────────
        const tip = document.getElementById('tip');
        function showTip(e, html) {
            tip.innerHTML = html;
            tip.classList.add('on');
            const pad = 12;
            let x = e.clientX + pad, y = e.clientY - pad - tip.offsetHeight;
            if (x + tip.offsetWidth > innerWidth - 8) x = e.clientX - pad - tip.offsetWidth;
            if (y < 8) y = e.clientY + pad;
            tip.style.left = x + 'px';
            tip.style.top = y + 'px';
        }
        const hideTip = () => tip.classList.remove('on');

        // ── Ubin statistik ──────────────────────────────────────
        function renderTiles(m) {
            const answers = m.answers || 0;
            const rated = m.feedback.up + m.feedback.down;
            const tiles = [
                { k: 'Percakapan', v: num(m.conversations) },
                { k: 'Pertanyaan', v: num(m.questions) },
                {
                    k: 'Terjawab dari dokumen', v: pct(m.outcomes.answered, answers), u: '%',
                    n: `${num(m.outcomes.answered)} dari ${num(answers)} jawaban`
                },
                {
                    k: 'Waktu jawab (median)', v: num(m.latency.p50_ms), u: 'ms',
                    n: `p95 ${num(m.latency.p95_ms)} ms`
                },
                {
                    k: 'Perkiraan biaya', v: '$' + Number(m.tokens.estimated_cost_usd).toFixed(2),
                    n: `${num(m.tokens.input)} token masuk`
                },
                {
                    k: 'Penilaian positif', v: rated ? pct(m.feedback.up, rated) : '—', u: rated ? '%' : '',
                    n: rated ? `${num(rated)} jawaban dinilai` : 'belum ada penilaian'
                },
            ];
            document.getElementById('tiles').innerHTML = tiles.map(t => `
                <div class="tile">
                  <div class="k">${esc(t.k)}</div>
                  <div class="v">${esc(t.v)}${t.u ? `<span class="u">${esc(t.u)}</span>` : ''}</div>
                  ${t.n ? `<div class="n">${esc(t.n)}</div>` : ''}
                </div>`).join('');
        }

        // ── Grafik batang harian (satu seri, tanpa legenda) ─────
        function renderDaily(daily) {
            const max = Math.max(1, ...daily.map(d => d.count));
            document.getElementById('plot').innerHTML = daily.map(d => `
                <div class="bar" data-zero="${d.count === 0 ? 1 : 0}"
                     data-label="${esc(d.day)}" data-count="${d.count}">
                  <i style="height:${d.count === 0 ? 2 : Math.max(4, (d.count / max) * 100)}%"></i>
                </div>`).join('');

            const fmt = s => new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
            document.getElementById('xaxis').innerHTML = daily.length
                ? `<span>${fmt(daily[0].day)}</span><span>${fmt(daily[daily.length - 1].day)}</span>`
                : '';

            document.querySelectorAll('#plot .bar').forEach(bar => {
                bar.addEventListener('mousemove', e => showTip(e,
                    `<strong>${bar.dataset.count}</strong> pertanyaan<br>${fmt(bar.dataset.label)}`));
                bar.addEventListener('mouseleave', hideTip);
            });
        }

        // ── Rincian hasil: warna + label teks, bukan warna saja ─
        function renderOutcomes(o) {
            const total = o.answered + o.no_context + o.fallback;
            const parts = [
                { c: 'sw-good', label: 'Terjawab dari dokumen', n: o.answered, hint: 'ada dokumen yang cocok' },
                { c: 'sw-warn', label: 'Tanpa dokumen cocok', n: o.no_context, hint: 'celah basis pengetahuan' },
                { c: 'sw-crit', label: 'Gagal / cadangan', n: o.fallback, hint: 'API tidak bisa dihubungi' },
            ];

            document.getElementById('stack').innerHTML = total === 0
                ? '<i class="sw-good" style="width:100%;background:var(--line)"></i>'
                : parts.filter(p => p.n > 0)
                    .map(p => `<i class="${p.c}" style="width:${(p.n / total) * 100}%"></i>`).join('');

            document.getElementById('legend').innerHTML = parts.map(p => `
                <div>
                  <b class="${p.c}"></b>
                  <span>${esc(p.label)}</span>
                  <strong>${num(p.n)}</strong>
                  <span class="small">(${pct(p.n, total)}% · ${esc(p.hint)})</span>
                </div>`).join('');
        }

        function renderSources(list) {
            const el = document.getElementById('sources');
            if (!list.length) { el.innerHTML = '<div class="empty">Belum ada jawaban yang merujuk dokumen.</div>'; return; }
            const max = Math.max(...list.map(s => s.count));
            el.innerHTML = '<div class="rank">' + list.map(s => `
                <div class="row">
                  <span class="name" title="${esc(s.title)}">${esc(s.title)}</span>
                  <span class="track"><span class="fill" style="width:${(s.count / max) * 100}%"></span></span>
                  <span class="n">${num(s.count)}</span>
                </div>`).join('') + '</div>';
        }

        function renderGaps(list) {
            const el = document.getElementById('gaps');
            el.innerHTML = list.length
                ? list.map(g => `
                    <div class="gap-item">
                      ${esc(g.question)}
                      <div class="when">${esc(g.at)}</div>
                    </div>`).join('')
                : '<div class="empty">Tidak ada pertanyaan yang gagal menemukan dokumen. Basis pengetahuan menutupi semua yang ditanyakan.</div>';
        }

        function renderConvos(rows) {
            const el = document.getElementById('convos');
            if (!rows.length) { el.innerHTML = '<div class="empty">Belum ada percakapan pada rentang ini.</div>'; return; }
            el.innerHTML = `<table>
                <thead><tr>
                  <th>Pertanyaan pertama</th><th>Pegawai</th><th>Pesan</th><th>Status</th><th>Terakhir</th>
                </tr></thead>
                <tbody>${rows.map(c => `
                  <tr data-id="${c.id}">
                    <td>${esc(c.title)}</td>
                    <td>${esc(c.user)} <span class="muted small">· ${esc(roleLabel(c.role))}</span></td>
                    <td>${num(c.message_count)}</td>
                    <td>${statusPill(c)}</td>
                    <td class="muted">${esc(c.last_message_at ?? '—')}</td>
                  </tr>`).join('')}</tbody></table>`;

            el.querySelectorAll('tr[data-id]').forEach(tr =>
                tr.addEventListener('click', () => openTranscript(tr.dataset.id)));
        }

        function statusPill(c) {
            if (c.fallback_count > 0) return '<span class="pill err">Gagal</span>';
            if (c.down_count > 0) return '<span class="pill err">Dinilai buruk</span>';
            if (c.gap_count > 0) return '<span class="pill gap">Ada celah</span>';
            return '<span class="pill ok">Lancar</span>';
        }

        // ── Transkrip ───────────────────────────────────────────
        const sheet = document.getElementById('sheet');
        async function openTranscript(id) {
            try {
                const c = await api(`${base}/conversations/${id}`);
                document.getElementById('sheetTitle').textContent = c.title ?? 'Percakapan';
                document.getElementById('sheetBody').innerHTML = c.messages.map(m => {
                    const meta = [];
                    if (m.latency_ms) meta.push(`${num(m.latency_ms)} ms`);
                    if (m.outcome) meta.push(m.outcome);
                    if (m.sources?.length) meta.push('sumber: ' + m.sources.map(esc).join(', '));
                    if (m.feedback) meta.push(m.feedback === 'up' ? 'dinilai baik' : 'dinilai buruk');
                    return `<div class="msg ${m.role === 'user' ? 'user' : 'bot'}">
                        <div><strong>${m.role === 'user' ? esc(c.user) : 'Ethoz Chat'}</strong></div>
                        <div style="margin-top:5px;white-space:pre-wrap">${esc(m.content)}</div>
                        ${meta.length ? `<div class="meta">${esc(meta.join(' · '))}</div>` : ''}
                      </div>`;
                }).join('');
                sheet.classList.add('on');
            } catch (e) { /* diamkan: baris tetap bisa diklik lagi */ }
        }
        document.getElementById('sheetClose').addEventListener('click', () => sheet.classList.remove('on'));
        sheet.addEventListener('click', e => { if (e.target === sheet) sheet.classList.remove('on'); });
        addEventListener('keydown', e => { if (e.key === 'Escape') sheet.classList.remove('on'); });

        // ── Muat ────────────────────────────────────────────────
        async function loadMetrics() {
            const m = await api(`${base}/metrics?days=${days}`);
            renderTiles(m);
            renderDaily(m.daily);
            renderOutcomes(m.outcomes);
            renderSources(m.top_sources);
            renderGaps(m.gaps);
            document.getElementById('rangeNote').textContent = `sejak ${m.since}`;
        }

        async function loadConvos() {
            renderConvos(await api(`${base}/conversations${problemsOnly ? '?problems=1' : ''}`));
        }

        document.querySelectorAll('.chip[data-days]').forEach(chip =>
            chip.addEventListener('click', () => {
                document.querySelectorAll('.chip[data-days]').forEach(c => c.classList.remove('on'));
                chip.classList.add('on');
                days = +chip.dataset.days;
                loadMetrics();
            }));

        document.querySelectorAll('.chip[data-problems]').forEach(chip =>
            chip.addEventListener('click', () => {
                document.querySelectorAll('.chip[data-problems]').forEach(c => c.classList.remove('on'));
                chip.classList.add('on');
                problemsOnly = chip.dataset.problems === '1';
                loadConvos();
            }));

        loadMetrics();
        loadConvos();
    </script>
</body>

</html>
