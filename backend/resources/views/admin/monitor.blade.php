{{-- Pemantauan Kinerja Ethoz Chat — resources/views/admin/monitor.blade.php
     Route: GET /admin/chatbot/monitor (auth + can:manage-chatbot).
     Warna status divalidasi untuk keterbacaan penyandang buta warna;
     tiap segmen selalu disertai label teks, tidak mengandalkan warna saja.
     Seluruh gaya berada di resources/css/app.css (bagian .page-monitor). --}}
@extends('layouts.app')

@section('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0A2A55">
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
@endsection

@section('title', 'Pemantauan — Ethoz Chat')
@section('body-class', 'page-monitor')

@section('content')
    <header class="topbar">
        <div class="brand">
            <div class="dot">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M13 6.4l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9.9-2.1Z" fill="#0A2A55" />
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
            <span class="muted small range-note" id="rangeNote"></span>
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
            <div class="filters filters--spaced">
                <button class="chip on" data-problems="0">Semua</button>
                <button class="chip" data-problems="1">Hanya yang bermasalah</button>
            </div>
            <div class="tablewrap" id="convos"></div>
        </section>
    </div>

    <div id="tip"></div>

    <div class="sheet" id="sheet">
        <div class="inner">
            <div class="sheet-head">
                <h2 class="sheet-title" id="sheetTitle">
                </h2>
                <button class="btn" id="sheetClose">Tutup</button>
            </div>
            <div id="sheetBody"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
            const base = '/admin/chatbot';
            const ACCEPT = {
                headers: {
                    'Accept': 'application/json'
                }
            };
            let days = 30;
            let problemsOnly = false;

            const esc = s => String(s ?? '').replace(/[&<>]/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;'
            } [c]));

            // Nilai peran di basis data tetap 'hr' (milik sistem Ethoz); yang
            // ditampilkan ke admin memakai istilah HC.
            const ROLE_LABELS = {
                staff: 'Staff',
                hr: 'HC',
                manager: 'Manager',
                admin: 'Admin'
            };
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
                let x = e.clientX + pad,
                    y = e.clientY - pad - tip.offsetHeight;
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
                const tiles = [{
                        k: 'Percakapan',
                        v: num(m.conversations)
                    },
                    {
                        k: 'Pertanyaan',
                        v: num(m.questions)
                    },
                    {
                        k: 'Terjawab dari dokumen',
                        v: pct(m.outcomes.answered, answers),
                        u: '%',
                        n: `${num(m.outcomes.answered)} dari ${num(answers)} jawaban`
                    },
                    {
                        k: 'Waktu jawab (median)',
                        v: num(m.latency.p50_ms),
                        u: 'ms',
                        n: `p95 ${num(m.latency.p95_ms)} ms`
                    },
                    {
                        k: 'Perkiraan biaya',
                        v: '$' + Number(m.tokens.estimated_cost_usd).toFixed(2),
                        n: `${num(m.tokens.input)} token masuk`
                    },
                    {
                        k: 'Penilaian positif',
                        v: rated ? pct(m.feedback.up, rated) : '—',
                        u: rated ? '%' : '',
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
                      <i style="--bar-height:${d.count === 0 ? 2 : Math.max(4, (d.count / max) * 100)}%"></i>
                    </div>`).join('');

                const fmt = s => new Date(s + 'T00:00:00').toLocaleDateString('id-ID', {
                    day: 'numeric',
                    month: 'short'
                });
                document.getElementById('xaxis').innerHTML = daily.length ?
                    `<span>${fmt(daily[0].day)}</span><span>${fmt(daily[daily.length - 1].day)}</span>` :
                    '';

                document.querySelectorAll('#plot .bar').forEach(bar => {
                    bar.addEventListener('mousemove', e => showTip(e,
                        `<strong>${bar.dataset.count}</strong> pertanyaan<br>${fmt(bar.dataset.label)}`));
                    bar.addEventListener('mouseleave', hideTip);
                });
            }

            // ── Rincian hasil: warna + label teks, bukan warna saja ─
            function renderOutcomes(o) {
                const total = o.answered + o.no_context + o.fallback;
                const parts = [{
                        c: 'sw-good',
                        label: 'Terjawab dari dokumen',
                        n: o.answered,
                        hint: 'ada dokumen yang cocok'
                    },
                    {
                        c: 'sw-warn',
                        label: 'Tanpa dokumen cocok',
                        n: o.no_context,
                        hint: 'celah basis pengetahuan'
                    },
                    {
                        c: 'sw-crit',
                        label: 'Gagal / cadangan',
                        n: o.fallback,
                        hint: 'API tidak bisa dihubungi'
                    },
                ];

                document.getElementById('stack').innerHTML = total === 0 ?
                    '<i class="sw-good stack-empty"></i>' :
                    parts.filter(p => p.n > 0)
                    .map(p => `<i class="${p.c}" style="--seg-width:${(p.n / total) * 100}%"></i>`).join('');

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
                if (!list.length) {
                    el.innerHTML = '<div class="empty">Belum ada jawaban yang merujuk dokumen.</div>';
                    return;
                }
                const max = Math.max(...list.map(s => s.count));
                el.innerHTML = '<div class="rank">' + list.map(s => `
                    <div class="row">
                      <span class="name" title="${esc(s.title)}">${esc(s.title)}</span>
                      <span class="track"><span class="fill" style="--fill-width:${(s.count / max) * 100}%"></span></span>
                      <span class="n">${num(s.count)}</span>
                    </div>`).join('') + '</div>';
            }

            function renderGaps(list) {
                const el = document.getElementById('gaps');
                el.innerHTML = list.length ?
                    list.map(g => `
                        <div class="gap-item">
                          ${esc(g.question)}
                          <div class="when">${esc(g.at)}</div>
                        </div>`).join('') :
                    '<div class="empty">Tidak ada pertanyaan yang gagal menemukan dokumen. Basis pengetahuan menutupi semua yang ditanyakan.</div>';
            }

            function renderConvos(rows) {
                const el = document.getElementById('convos');
                if (!rows.length) {
                    el.innerHTML = '<div class="empty">Belum ada percakapan pada rentang ini.</div>';
                    return;
                }
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
                            <div class="msg-body">${esc(m.content)}</div>
                            ${meta.length ? `<div class="meta">${esc(meta.join(' · '))}</div>` : ''}
                          </div>`;
                    }).join('');
                    sheet.classList.add('on');
                } catch (e) {
                    /* diamkan: baris tetap bisa diklik lagi */ }
            }
            document.getElementById('sheetClose').addEventListener('click', () => sheet.classList.remove('on'));
            sheet.addEventListener('click', e => {
                if (e.target === sheet) sheet.classList.remove('on');
            });
            addEventListener('keydown', e => {
                if (e.key === 'Escape') sheet.classList.remove('on');
            });

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
@endpush
