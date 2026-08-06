{{-- Konsol Admin Ethoz Chat — resources/views/admin/chatbot.blade.php
     Route: GET /admin/chatbot (auth + can:manage-chatbot). Lihat IMPLEMENTATION.md §11.
     Seluruh gaya berada di resources/css/app.css (bagian .page-console);
     tampilan ini tidak lagi memuat <style> maupun style="..." statis. --}}
@extends('layouts.app')

@section('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0A2A55">
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
@endsection

@section('title', 'Konsol Admin — Ethoz Chat')
@section('body-class', 'page-console')

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

            <div class="row row--spaced">
                <span class="muted small">Akses dokumen yang diunggah:</span>
                <select class="in in--auto" id="upScope">
                    <option value="all">Semua pegawai</option>
                    <option value="hr">HC saja</option>
                    <option value="manager">Manager saja</option>
                    <option value="hr_manager">HC &amp; Manager</option>
                </select>
            </div>

            <input type="file" id="fileInput" accept=".pdf,.docx,.txt,.md,.csv,.png,.jpg,.jpeg,.webp,.bmp,.tif,.tiff"
                multiple class="is-hidden">
            <div class="dropzone" id="dropzone" role="button" tabindex="0">
                <div class="dropIcon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 16V5m0 0L8 9m4-4 4 4" stroke="#1B6FD8" stroke-width="1.9" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" stroke="#1B6FD8" stroke-width="1.9"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <div class="dropzone-title">Unggah dokumen</div>
                <div class="muted small dropzone-hint">Klik atau seret ke sini · PDF, DOCX, TXT, MD, CSV,
                    gambar
                </div>
                <div class="muted small dropzone-note">Tabel ikut terbaca. Dokumen hasil pindai dan gambar
                    dibaca lewat OCR — prosesnya berjalan di latar, statusnya muncul di daftar.
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
            <label class="lbl" for="f_blocked_topics">Topik yang dilarang (pisahkan dengan koma / baris
                baru)</label>
            <textarea class="in" id="f_blocked_topics" rows="2" placeholder="mis. gaji karyawan lain, gosip kantor"></textarea>
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
                <select class="in in--auto" id="pvRole">
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
@endsection

@push('scripts')
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
            } [c]));
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
                    // Unggahan dari sesi/tab lain bisa saja masih berjalan.
                    if (KB.some(isPending)) watchStatuses();
                } catch (e) {
                    toast('Gagal memuat pengetahuan: ' + shorten(e.message), false);
                }
            }

            function badge(src) {
                return src === 'document' ?
                    '<span class="badge doc">Dokumen</span>' : '<span class="badge man">Manual</span>';
            }

            /* Status ekstraksi — dokumen besar/hasil pindai diproses di antrean,
               jadi entri bisa terlihat sebelum isinya siap. */
            const STATUS = {
                queued: {
                    label: 'Antre',
                    cls: 'st-wait'
                },
                processing: {
                    label: 'Diproses',
                    cls: 'st-run'
                },
                failed: {
                    label: 'Gagal',
                    cls: 'st-fail'
                },
            };

            const isPending = e => e.status === 'queued' || e.status === 'processing';

            function statusBadge(e) {
                const s = STATUS[e.status];
                return s ? `<span class="badge ${s.cls}">${s.label}</span>` : '';
            }

            function skeleton(rows = 3) {
                return '<div>' + Array.from({
                        length: rows
                    },
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
                ${statusBadge(e)}
                <input class="in kb-title" value="${escAttr(e.title || '')}" placeholder="Judul informasi">
                <button class="del kb-del" title="Hapus" aria-label="Hapus informasi">&#10005;</button>
              </div>
              ${e.source === 'document' ? `<div class="muted small kb-meta">${escHtml(e.file_name || '')} · ${(e.content || '').length} karakter</div>` : ''}
              ${e.status_message ? `<div class="small kb-note ${e.status === 'failed' ? 'bad' : ''}">${escHtml(e.status_message)}</div>` : ''}
              <div class="row"><span class="muted small">Akses:</span>
                <select class="in kb-scope">
                  ${SCOPES.map(s => `<option value="${s.id}" ${e.scope === s.id ? 'selected' : ''}>${s.label}</option>`).join('')}
                </select>
              </div>
              ${isPending(e)
                  ? `<div class="proc"><span class="spin"></span>Teks sedang diambil dari berkas — daftar ini menyegarkan sendiri.</div>`
                  : `<textarea class="in kb-content" rows="${e.source === 'document' ? 4 : 3}" placeholder="${e.status === 'failed' ? 'Ekstraksi gagal — tempel teksnya di sini bila ingin tetap dipakai…' : 'Isi informasi…'}">${escHtml(e.content || '')}</textarea>
                     <div class="row end"><button class="btn sm kb-save">Simpan</button></div>`}
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
                    await loadKnowledge();
                    // Ekstraksi (dan OCR) berjalan di antrean — hasilnya menyusul.
                    toast(`${file.name} diantrekan · teksnya diambil di latar belakang`);
                    watchStatuses();
                } catch (err) {
                    toast('Gagal unggah: ' + shorten(err.message), false);
                }
            }

            // ---- Pemantauan status ekstraksi ----
            // Entri hasil unggahan tidak langsung berisi: PDF pindai harus
            // dirasterkan lalu di-OCR halaman per halaman. Selama masih ada yang
            // berjalan, statusnya ditanyakan berkala sampai selesai/gagal.
            let statusTimer = null;

            function stopWatching() {
                clearInterval(statusTimer);
                statusTimer = null;
            }

            function watchStatuses() {
                if (statusTimer) return;
                statusTimer = setInterval(pollStatuses, 3000);
            }

            async function pollStatuses() {
                let rows;
                try {
                    rows = await api(`${base}/documents/status`, {
                        headers: ACCEPT
                    });
                } catch (e) {
                    // Jangan membanjiri admin dengan galat jaringan berulang.
                    stopWatching();
                    return;
                }

                const known = new Map(KB.map(e => [e.id, e.status]));
                const settled = rows.filter(r => known.has(r.id) && known.get(r.id) !== r.status &&
                    !['queued', 'processing'].includes(r.status));

                if (settled.length) {
                    await loadKnowledge();
                    settled.forEach(r => {
                        const name = r.file_name || r.title;
                        if (r.status === 'failed') toast(`${name}: ${shorten(r.status_message || 'ekstraksi gagal')}`, false);
                        else toast(`${name} selesai diproses · ${r.char_count ?? 0} karakter`);
                    });
                } else if (rows.some(r => known.has(r.id) && known.get(r.id) !== r.status)) {
                    // Perpindahan antre → diproses: cukup perbarui lencananya.
                    await loadKnowledge();
                }

                if (!KB.some(isPending)) stopWatching();
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
@endpush
