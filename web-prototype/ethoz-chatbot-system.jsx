import React, { useState, useEffect, useRef } from "react";
import * as mammothLib from "mammoth";

// ══ Ethoz brand tokens ═══════════════════════════════════════════
const C = {
  navy: "#0A2A55",
  blue: "#1257A8",
  azure: "#1B6FD8",
  skyAlt: "#8CC9F7",
  sky: "#5FAFF0",
  accent: "#2E8AE8",
  ink: "#0B1E33",
  muted: "#6B7C90",
  line: "#E4EAF2",
  soft: "#F7F9FC",
  field: "#FBFCFE",
};

const ROLES = [
  { id: "staff", label: "Staff / Pegawai" },
  { id: "hr", label: "HC" },
  { id: "manager", label: "Manager" },
];

const SCOPES = [
  { id: "all", label: "Semua pegawai" },
  { id: "hr", label: "HC saja" },
  { id: "manager", label: "Manager saja" },
  { id: "hr_manager", label: "HC & Manager" },
];

// ── Default configuration (sample PT BDP data) ───────────────────
const DEFAULT_CONFIG = {
  botName: "Ethoz Chat",
  company: "PT BDP",
  role: "Asisten HC & informasi perusahaan untuk pegawai PT BDP.",
  knowledge: [
    {
      id: 1,
      title: "Cuti",
      scope: "all",
      content:
        "Cuti tahunan 12 hari kerja/tahun, berlaku setelah masa kerja 1 tahun. Ajukan minimal H-3 lewat menu Cuti, disetujui atasan langsung. Sisa cuti dilihat di menu Profil > Saldo Cuti. Carry-over maksimal 6 hari.",
    },
    {
      id: 2,
      title: "Presensi",
      scope: "all",
      content:
        "Jam kerja Senin–Jumat 08.00–17.00 WIB (istirahat 12.00–13.00). Check-in/out lewat menu Presensi dengan GPS + selfie. Toleransi keterlambatan 15 menit. Lupa absen: ajukan lewat Presensi > Ajukan Koreksi.",
    },
    {
      id: 3,
      title: "Izin & Sakit",
      scope: "all",
      content:
        "Lewat menu Izin. Sakit lebih dari 1 hari wajib melampirkan surat dokter. Izin mendadak tetap diinput di aplikasi paling lambat di hari yang sama.",
    },
    {
      id: 4,
      title: "E-Slip Gaji",
      scope: "all",
      content:
        "Terbit tiap tanggal 25, diunduh di menu e-Slip. Selisih komponen: hubungi tim Payroll HC.",
    },
    {
      id: 5,
      title: "Struktur Organisasi",
      scope: "all",
      content:
        "Direktur Utama membawahi Direktur Operasional, Direktur Keuangan, dan Direktur SDM & Umum. Direktur SDM & Umum membawahi Manager HC (HC Officer, HC Admin) dan Manager IT (IT Support, System Analyst).",
    },
    {
      id: 6,
      title: "Job Description (contoh)",
      scope: "all",
      content:
        "HC Officer: administrasi kepegawaian, rekrutmen, absensi, payroll. IT Support: troubleshooting perangkat & jaringan, reset password, maintenance sistem. Finance Staff: pembayaran, verifikasi tagihan, laporan keuangan.",
    },
    {
      id: 7,
      title: "Rincian Payroll & Tunjangan",
      scope: "hr",
      content:
        "Struktur gaji, komponen tunjangan, dan skema BPJS bersifat rahasia dan hanya untuk tim HC. Pertanyaan pegawai soal komponen gaji pribadi diarahkan ke Payroll HC.",
    },
    {
      id: 8,
      title: "Batas Approval Anggaran",
      scope: "manager",
      content:
        "Manager berwenang menyetujui pengeluaran hingga Rp10 juta. Di atas itu perlu persetujuan Direktur terkait. Panduan ini untuk level Manager ke atas.",
    },
  ],
  constraints: {
    noHallucination: true,
    protectSensitive: true,
    maxLength: "sedang",
    language: "id",
    blockedTopics: "",
  },
  behaviour: {
    tone: "ramah",
    address: "Anda",
    emoji: false,
    allowBullets: true,
    extra: "",
  },
};

// ── System-prompt builder (mirror of the Laravel service) ────────
function scopeAllows(scope, roleId) {
  if (!scope || scope === "all") return true;
  if (scope === "hr_manager") return roleId === "hr" || roleId === "manager";
  return scope === roleId;
}

function buildSystemPrompt(cfg, roleId) {
  const roleLabel = ROLES.find((r) => r.id === roleId)?.label || "Pegawai";
  const allowed = cfg.knowledge.filter((k) => scopeAllows(k.scope, roleId));
  const kb = allowed.length
    ? allowed.map((k) => `[${k.title}]\n${k.content}`).join("\n\n")
    : "(Tidak ada informasi yang tersedia untuk peran ini.)";

  const lengthMap = {
    singkat: "Jawab sangat ringkas, 1–3 kalimat.",
    sedang: "Jawab ringkas dan secukupnya.",
    detail: "Boleh menjawab lebih lengkap bila diperlukan.",
  };
  const langMap = {
    id: "Selalu jawab dalam Bahasa Indonesia.",
    en: "Always answer in English.",
    follow: "Jawab mengikuti bahasa yang dipakai pengguna.",
  };
  const toneMap = {
    formal: "Gunakan nada formal dan resmi.",
    ramah: "Gunakan nada ramah namun profesional.",
    santai: "Gunakan nada santai dan akrab.",
  };

  const L = [];
  L.push(`Kamu adalah "${cfg.botName}", asisten AI di dalam aplikasi ${cfg.company}.`);
  if (cfg.role.trim()) L.push(cfg.role.trim());
  L.push(`Pengguna yang sedang bertanya berperan sebagai: ${roleLabel}.`);
  L.push("");
  L.push("GAYA & PERILAKU:");
  L.push(`- ${toneMap[cfg.behaviour.tone]}`);
  L.push(`- Sapa pengguna dengan "${cfg.behaviour.address}".`);
  L.push(`- ${langMap[cfg.constraints.language]}`);
  L.push(`- ${lengthMap[cfg.constraints.maxLength]}`);
  L.push(
    `- ${cfg.behaviour.emoji ? "Boleh memakai emoji secukupnya." : "Jangan memakai emoji."}`
  );
  L.push(
    `- ${
      cfg.behaviour.allowBullets
        ? 'Boleh memakai daftar berpoin dengan tanda "-".'
        : "Jawab dalam paragraf, hindari daftar berpoin."
    }`
  );
  L.push("- Jawab dalam teks biasa tanpa format markdown (tanpa **, #, atau tabel).");
  if (cfg.behaviour.extra.trim()) L.push(`- ${cfg.behaviour.extra.trim()}`);
  L.push("");
  L.push("BATASAN:");
  if (cfg.constraints.noHallucination)
    L.push(
      "- Jangan mengarang informasi/kebijakan yang tidak ada di BASIS PENGETAHUAN. Jika tidak tahu, katakan jujur dan arahkan ke HC/atasan."
    );
  if (cfg.constraints.protectSensitive)
    L.push(
      "- Jangan meskyAlta atau menampilkan data pribadi sensitif (gaji spesifik, NIK, data medis). Arahkan ke kanal resmi HC."
    );
  L.push("- Hanya jawab berdasarkan informasi yang tersedia untuk peran pengguna ini.");
  const blocked = (cfg.constraints.blockedTopics || "")
    .split(/[\n,]+/)
    .map((s) => s.trim())
    .filter(Boolean);
  if (blocked.length) L.push(`- Tolak dengan sopan bila ditanya soal: ${blocked.join(", ")}.`);
  L.push("");
  L.push("BASIS PENGETAHUAN (hanya yang boleh diakses peran ini):");
  L.push(kb);
  return L.join("\n");
}

// ── Ekstraksi teks dokumen (sisi klien, untuk pratinjau) ─────────
function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve();
    const s = document.createElement("script");
    s.src = src;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error("gagal memuat " + src));
    document.head.appendChild(s);
  });
}

async function extractPdf(file) {
  const CDN = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174";
  await loadScript(`${CDN}/pdf.min.js`);
  const pdfjs = window.pdfjsLib;
  pdfjs.GlobalWorkerOptions.workerSrc = `${CDN}/pdf.worker.min.js`;
  const buf = await file.arrayBuffer();
  const pdf = await pdfjs.getDocument({ data: buf }).promise;
  let out = "";
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const tc = await page.getTextContent();
    out += tc.items.map((it) => it.str).join(" ") + "\n";
  }
  return out.trim();
}

async function extractText(file) {
  const name = file.name.toLowerCase();
  if (name.endsWith(".txt") || name.endsWith(".md")) return (await file.text()).trim();
  if (name.endsWith(".docx")) {
    const arrayBuffer = await file.arrayBuffer();
    const mm = mammothLib.default || mammothLib;
    const res = await mm.extractRawText({ arrayBuffer });
    return (res.value || "").trim();
  }
  if (name.endsWith(".pdf")) return await extractPdf(file);
  return (await file.text()).trim();
}

// ══ Reusable UI atoms ════════════════════════════════════════════
const Card = ({ title, desc, children }) => (
  <div style={S.card}>
    {title && (
      <div style={{ marginBottom: 4 }}>
        <div style={S.cardTitle}>{title}</div>
        {desc && <div style={S.cardDesc}>{desc}</div>}
      </div>
    )}
    {children}
  </div>
);

const Label = ({ children }) => <div style={S.label}>{children}</div>;

const Input = (p) => <input {...p} className="ez-in" style={{ ...S.input, ...p.style }} />;
const Area = (p) => (
  <textarea {...p} className="ez-in" style={{ ...S.input, ...S.area, ...p.style }} />
);
const Select = ({ value, onChange, options }) => (
  <select value={value} onChange={onChange} className="ez-in" style={{ ...S.input, cursor: "pointer" }}>
    {options.map((o) => (
      <option key={o.id} value={o.id}>
        {o.label}
      </option>
    ))}
  </select>
);

const Toggle = ({ on, onClick, label }) => (
  <div style={S.toggleRow} onClick={onClick}>
    <span style={S.toggleLabel}>{label}</span>
    <span style={{ ...S.track, background: on ? C.accent : "#CBD5E1" }}>
      <span style={{ ...S.knob, transform: on ? "translateX(18px)" : "translateX(0)" }} />
    </span>
  </div>
);

// ══ ADMIN CONSOLE ════════════════════════════════════════════════
function AdminConsole({ config, setConfig }) {
  const [previewRole, setPreviewRole] = useState("staff");
  const c = config;
  const fileRef = useRef(null);
  const [uploading, setUploading] = useState(null);

  const setKnowledge = (kn) => setConfig({ ...c, knowledge: kn });
  const updateEntry = (id, patch) =>
    setKnowledge(c.knowledge.map((k) => (k.id === id ? { ...k, ...patch } : k)));
  const addEntry = () =>
    setKnowledge([
      ...c.knowledge,
      { id: Date.now(), title: "Judul informasi", scope: "all", content: "", source: "manual" },
    ]);
  const removeEntry = (id) => setKnowledge(c.knowledge.filter((k) => k.id !== id));

  const addDocs = async (files) => {
    for (const file of Array.from(files)) {
      setUploading(file.name);
      let content, ok = true;
      try {
        const text = await extractText(file);
        content =
          (text || "").replace(/\n{3,}/g, "\n\n") ||
          "(Teks tidak terbaca — untuk PDF hasil scan gunakan OCR, atau tempel isinya di sini.)";
        if (!text) ok = false;
      } catch (err) {
        ok = false;
        content =
          "(Pratinjau tidak bisa membaca dokumen ini. Di sistem asli, PDF/DOCX diekstrak di server — lihat panduan backend. Anda juga bisa menempelkan isinya di sini.)";
      }
      setConfig((prev) => ({
        ...prev,
        knowledge: [
          ...prev.knowledge,
          {
            id: Date.now() + Math.random(),
            title: file.name.replace(/\.[^.]+$/, ""),
            scope: "all",
            content,
            source: "document",
            fileName: file.name,
            ok,
          },
        ],
      }));
      setUploading(null);
    }
  };

  const setC2 = (group, patch) => setConfig({ ...c, [group]: { ...c[group], ...patch } });

  return (
    <div style={S.adminWrap}>
      {/* Identity */}
      <Card title="Identitas & Peran Bot" desc="Nama, perusahaan, dan peran asisten.">
        <Label>Nama asisten</Label>
        <Input value={c.botName} onChange={(e) => setConfig({ ...c, botName: e.target.value })} />
        <Label>Perusahaan</Label>
        <Input value={c.company} onChange={(e) => setConfig({ ...c, company: e.target.value })} />
        <Label>Peran / deskripsi singkat</Label>
        <Area
          rows={2}
          value={c.role}
          onChange={(e) => setConfig({ ...c, role: e.target.value })}
        />
      </Card>

      {/* Knowledge base */}
      <Card
        title="Basis Pengetahuan"
        desc="Unggah dokumen (kebijakan, jobdesc, struktur organisasi) atau tambah manual. Isinya menjadi acuan jawaban bot, sesuai akses per peran."
      >
        <input
          ref={fileRef}
          type="file"
          accept=".pdf,.docx,.txt,.md"
          multiple
          style={{ display: "none" }}
          onChange={(e) => {
            if (e.target.files?.length) addDocs(e.target.files);
            e.target.value = "";
          }}
        />

        <div
          className="ez-drop"
          style={S.dropzone}
          onClick={() => fileRef.current?.click()}
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => {
            e.preventDefault();
            if (e.dataTransfer.files?.length) addDocs(e.dataTransfer.files);
          }}
        >
          <div style={S.dropIcon}>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <path d="M12 16V5m0 0L8 9m4-4 4 4" stroke={C.azure} strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
              <path d="M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" stroke={C.azure} strokeWidth="1.9" strokeLinecap="round" />
            </svg>
          </div>
          <div style={{ fontWeight: 600, fontSize: 13.5, color: C.navy }}>Unggah dokumen</div>
          <div style={{ fontSize: 12, color: C.muted, marginTop: 2 }}>
            Klik atau seret ke sini · PDF, DOCX, TXT, MD
          </div>
        </div>

        {uploading && (
          <div style={S.uploading}>
            <span className="ez-td" /> <span className="ez-td" /> <span className="ez-td" />
            <span style={{ marginLeft: 8 }}>Mengekstrak “{uploading}”…</span>
          </div>
        )}

        <div style={{ display: "flex", flexDirection: "column", gap: 12, marginTop: 14 }}>
          {c.knowledge.map((k) => (
            <div key={k.id} style={S.kbItem}>
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                <span style={k.source === "document" ? S.badgeDoc : S.badgeManual}>
                  {k.source === "document" ? "Dokumen" : "Manual"}
                </span>
                <Input
                  value={k.title}
                  onChange={(e) => updateEntry(k.id, { title: e.target.value })}
                  style={{ flex: 1, fontWeight: 600 }}
                />
                <button
                  onClick={() => removeEntry(k.id)}
                  className="ez-del"
                  title="Hapus"
                  style={S.delBtn}
                >
                  ✕
                </button>
              </div>
              {k.source === "document" && (
                <div style={{ fontSize: 11.5, color: k.ok === false ? "#C0554F" : C.muted, marginTop: 6 }}>
                  {k.fileName} · {k.content.length.toLocaleString("id-ID")} karakter
                  {k.ok === false ? " · perlu ditempel/diperiksa manual" : " terekstrak"}
                </div>
              )}
              <div style={{ display: "flex", gap: 8, alignItems: "center", marginTop: 8 }}>
                <span style={{ fontSize: 12, color: C.muted, whiteSpace: "nowrap" }}>Akses:</span>
                <select
                  value={k.scope}
                  onChange={(e) => updateEntry(k.id, { scope: e.target.value })}
                  className="ez-in"
                  style={{ ...S.input, padding: "8px 10px", cursor: "pointer", flex: 1 }}
                >
                  {SCOPES.map((sc) => (
                    <option key={sc.id} value={sc.id}>
                      {sc.label}
                    </option>
                  ))}
                </select>
              </div>
              <Area
                rows={k.source === "document" ? 4 : 3}
                value={k.content}
                placeholder="Isi informasi…"
                onChange={(e) => updateEntry(k.id, { content: e.target.value })}
                style={{ marginTop: 8 }}
              />
            </div>
          ))}
        </div>
        <button onClick={addEntry} style={S.addBtn} className="ez-add">
          + Tambah manual
        </button>
      </Card>

      {/* Constraints */}
      <Card title="Batasan" desc="Aturan main dan pagar pengaman bot.">
        <Toggle
          on={c.constraints.noHallucination}
          onClick={() => setC2("constraints", { noHallucination: !c.constraints.noHallucination })}
          label="Larang mengarang (hanya jawab dari basis pengetahuan)"
        />
        <Toggle
          on={c.constraints.protectSensitive}
          onClick={() => setC2("constraints", { protectSensitive: !c.constraints.protectSensitive })}
          label="Lindungi data pribadi sensitif"
        />
        <Label>Panjang jawaban</Label>
        <Select
          value={c.constraints.maxLength}
          onChange={(e) => setC2("constraints", { maxLength: e.target.value })}
          options={[
            { id: "singkat", label: "Singkat" },
            { id: "sedang", label: "Sedang" },
            { id: "detail", label: "Detail" },
          ]}
        />
        <Label>Bahasa</Label>
        <Select
          value={c.constraints.language}
          onChange={(e) => setC2("constraints", { language: e.target.value })}
          options={[
            { id: "id", label: "Bahasa Indonesia" },
            { id: "en", label: "English" },
            { id: "follow", label: "Ikuti bahasa pengguna" },
          ]}
        />
        <Label>Topik yang dilarang (pisahkan dengan koma / baris baru)</Label>
        <Area
          rows={2}
          placeholder="mis. gaji karyawan lain, gosip kantor, hal di luar pekerjaan"
          value={c.constraints.blockedTopics}
          onChange={(e) => setC2("constraints", { blockedTopics: e.target.value })}
        />
      </Card>

      {/* Behaviour */}
      <Card title="Behaviour & Gaya Bahasa" desc="Kepribadian dan cara bot menjawab.">
        <Label>Nada bicara</Label>
        <Select
          value={c.behaviour.tone}
          onChange={(e) => setC2("behaviour", { tone: e.target.value })}
          options={[
            { id: "formal", label: "Formal & resmi" },
            { id: "ramah", label: "Ramah profesional" },
            { id: "santai", label: "Santai & akrab" },
          ]}
        />
        <Label>Sapaan</Label>
        <Select
          value={c.behaviour.address}
          onChange={(e) => setC2("behaviour", { address: e.target.value })}
          options={[
            { id: "Anda", label: "Anda" },
            { id: "Kamu", label: "Kamu" },
          ]}
        />
        <Toggle
          on={c.behaviour.emoji}
          onClick={() => setC2("behaviour", { emoji: !c.behaviour.emoji })}
          label="Boleh memakai emoji"
        />
        <Toggle
          on={c.behaviour.allowBullets}
          onClick={() => setC2("behaviour", { allowBullets: !c.behaviour.allowBullets })}
          label="Boleh menjawab dengan poin"
        />
        <Label>Instruksi tambahan (opsional)</Label>
        <Area
          rows={2}
          placeholder="mis. selalu tutup jawaban dengan menawarkan bantuan lanjutan"
          value={c.behaviour.extra}
          onChange={(e) => setC2("behaviour", { extra: e.target.value })}
        />
      </Card>

      {/* Live prompt preview */}
      <Card
        title="Pratinjau Instruksi AI"
        desc="Semua pengaturan di atas dikompilasi menjadi instruksi yang diterima AI. Inilah yang dikirim backend."
      >
        <div style={{ display: "flex", gap: 8, alignItems: "center", marginBottom: 8 }}>
          <span style={{ fontSize: 12, color: C.muted }}>Lihat sebagai peran:</span>
          <select
            value={previewRole}
            onChange={(e) => setPreviewRole(e.target.value)}
            className="ez-in"
            style={{ ...S.input, padding: "6px 10px", width: "auto", cursor: "pointer" }}
          >
            {ROLES.map((r) => (
              <option key={r.id} value={r.id}>
                {r.label}
              </option>
            ))}
          </select>
        </div>
        <pre style={S.pre}>{buildSystemPrompt(c, previewRole)}</pre>
      </Card>
    </div>
  );
}

// ══ USER CHAT ════════════════════════════════════════════════════
function ChatView({ config }) {
  const [role, setRole] = useState("staff");
  const greeting = `Halo, saya ${config.botName}. Ada yang bisa saya bantu?`;
  const [messages, setMessages] = useState([{ role: "assistant", content: greeting }]);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const scrollRef = useRef(null);

  useEffect(() => {
    setMessages([{ role: "assistant", content: `Halo, saya ${config.botName}. Ada yang bisa saya bantu?` }]);
  }, [role, config.botName]);

  useEffect(() => {
    scrollRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  const chips = ["Berapa hak cuti tahunan?", "Cara ajukan izin sakit?", "Kapan e-slip terbit?", "Struktur organisasi"];

  const send = async (raw) => {
    const text = (raw ?? input).trim();
    if (!text || loading) return;
    const userMsg = { role: "user", content: text };
    const convo = [...messages, userMsg];
    setMessages(convo);
    setInput("");
    setLoading(true);

    const firstUser = convo.findIndex((m) => m.role === "user");
    const apiMessages = convo.slice(firstUser).map((m) => ({ role: m.role, content: m.content }));

    try {
      const res = await fetch("https://api.anthropic.com/v1/messages", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          model: "claude-sonnet-4-6",
          max_tokens: 1000,
          system: buildSystemPrompt(config, role),
          messages: apiMessages,
        }),
      });
      const data = await res.json();
      const reply = (data.content || [])
        .filter((b) => b.type === "text")
        .map((b) => b.text)
        .join("\n")
        .trim();
      setMessages((p) => [
        ...p,
        { role: "assistant", content: reply || "Maaf, saya belum bisa memproses pertanyaan itu." },
      ]);
    } catch {
      setMessages((p) => [
        ...p,
        { role: "assistant", content: "Maaf, koneksi ke asisten sedang bermasalah. Coba lagi sebentar." },
      ]);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={S.chatOuter}>
      <div style={S.loginAs}>
        <span style={{ fontSize: 13, color: C.muted }}>Masuk sebagai (dari sesi Ethoz):</span>
        <select
          value={role}
          onChange={(e) => setRole(e.target.value)}
          className="ez-in"
          style={{ ...S.input, padding: "8px 12px", width: "auto", cursor: "pointer", fontWeight: 600 }}
        >
          {ROLES.map((r) => (
            <option key={r.id} value={r.id}>
              {r.label}
            </option>
          ))}
        </select>
      </div>

      <div style={S.phone}>
        {/* header */}
        <div style={S.chatHeader}>
          <div style={S.headerGlow} aria-hidden="true" />
          <div style={S.avatar}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path
                d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H9l-4 3.2V16H6.5A2.5 2.5 0 0 1 4 13.5v-7Z"
                fill={C.navy}
              />
              <path d="M13 6.4l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9.9-2.1Z" fill={C.sky} />
            </svg>
          </div>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={S.chatTitle}>{config.botName}</div>
            <div style={S.status}>
              <span style={S.dot} /> Online • {config.company}
            </div>
          </div>
        </div>

        {/* thread */}
        <div style={S.thread}>
          {messages.map((m, i) => {
            const bot = m.role === "assistant";
            return (
              <div
                key={i}
                className="ez-msg"
                style={{ display: "flex", justifyContent: bot ? "flex-start" : "flex-end" }}
              >
                <div
                  style={{
                    maxWidth: "82%",
                    padding: "10px 14px",
                    fontSize: 14.5,
                    lineHeight: 1.55,
                    whiteSpace: "pre-wrap",
                    wordBreak: "break-word",
                    borderRadius: 16,
                    ...(bot
                      ? { background: C.soft, color: C.ink, border: `1px solid ${C.line}`, borderBottomLeftRadius: 5 }
                      : {
                          background: `linear-gradient(135deg, ${C.accent}, ${C.blue})`,
                          color: "#fff",
                          borderBottomRightRadius: 5,
                        }),
                  }}
                >
                  {m.content}
                </div>
              </div>
            );
          })}
          {loading && (
            <div style={S.typing}>
              <span className="ez-td" />
              <span className="ez-td" />
              <span className="ez-td" />
            </div>
          )}
          <div ref={scrollRef} />
        </div>

        {messages.length <= 1 && !loading && (
          <div style={S.chipRow}>
            {chips.map((ch) => (
              <button key={ch} className="ez-chip" style={S.chip} onClick={() => send(ch)}>
                {ch}
              </button>
            ))}
          </div>
        )}

        <div style={S.inputBar}>
          <input
            className="ez-in"
            style={{ ...S.input, flex: 1, background: C.field }}
            placeholder="Tulis pertanyaan Anda…"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                send();
              }
            }}
          />
          <button
            onClick={() => send()}
            disabled={loading || !input.trim()}
            aria-label="Kirim"
            style={{ ...S.sendBtn, opacity: loading || !input.trim() ? 0.45 : 1 }}
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path d="M4 11.5 20 4l-7.5 16-2.2-6.3L4 11.5Z" fill={C.navy} />
            </svg>
          </button>
        </div>
      </div>
    </div>
  );
}

// ══ APP SHELL ════════════════════════════════════════════════════
export default function App() {
  const [view, setView] = useState("admin");
  const [config, setConfig] = useState(DEFAULT_CONFIG);

  return (
    <div style={S.page}>
      <style>{css}</style>

      <div style={S.topbar}>
        <div style={S.brand}>
          <div style={S.brandDot}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
              <path d="M13 6.4l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9.9-2.1Z" fill={C.navy} />
            </svg>
          </div>
          <div>
            <div style={S.brandName}>Ethoz Assistant</div>
            <div style={S.brandSub}>Sistem chatbot pegawai</div>
          </div>
        </div>
        <div style={S.seg}>
          <button
            onClick={() => setView("admin")}
            style={{ ...S.segBtn, ...(view === "admin" ? S.segActive : {}) }}
          >
            Konsol Admin
          </button>
          <button
            onClick={() => setView("user")}
            style={{ ...S.segBtn, ...(view === "user" ? S.segActive : {}) }}
          >
            Tampilan Pegawai
          </button>
        </div>
      </div>

      <div style={S.body}>
        {view === "admin" ? (
          <AdminConsole config={config} setConfig={setConfig} />
        ) : (
          <ChatView config={config} />
        )}
      </div>
    </div>
  );
}

// ══ Styles ═══════════════════════════════════════════════════════
const S = {
  page: {
    minHeight: "100vh",
    boxSizing: "border-box",
    background: "linear-gradient(165deg,#FFFFFF 0%,#FFFFFF 55%,#FFFFFF 100%)",
    fontFamily: "'Inter',system-ui,-apple-system,sans-serif",
    color: C.ink,
    padding: "0 0 40px",
  },
  topbar: {
    position: "sticky",
    top: 0,
    zIndex: 5,
    display: "flex",
    flexWrap: "wrap",
    gap: 12,
    alignItems: "center",
    justifyContent: "space-between",
    padding: "14px 20px",
    background: "linear-gradient(120deg,#0A2A55 0%,#1257A8 60%,#1B6FD8 100%)",
    color: "#fff",
    boxShadow: "0 6px 20px rgba(10,42,85,0.18)",
  },
  brand: { display: "flex", alignItems: "center", gap: 10 },
  brandDot: {
    width: 34,
    height: 34,
    borderRadius: 10,
    background: "linear-gradient(135deg,#5FAFF0,#2E8AE8)",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
  },
  brandName: { fontFamily: "'Fredoka',sans-serif", fontWeight: 600, fontSize: 16, lineHeight: 1.1 },
  brandSub: { fontSize: 11.5, opacity: 0.8 },
  seg: {
    display: "flex",
    background: "rgba(255,255,255,0.12)",
    borderRadius: 12,
    padding: 4,
    gap: 4,
  },
  segBtn: {
    border: "none",
    background: "transparent",
    color: "rgba(255,255,255,0.85)",
    fontSize: 13.5,
    fontWeight: 600,
    padding: "8px 16px",
    borderRadius: 9,
    cursor: "pointer",
    fontFamily: "'Inter',sans-serif",
    transition: "all 0.15s",
  },
  segActive: { background: "#fff", color: C.navy, boxShadow: "0 2px 8px rgba(0,0,0,0.15)" },
  body: { maxWidth: 780, margin: "0 auto", padding: "24px 16px 0" },

  // admin
  adminWrap: { display: "flex", flexDirection: "column", gap: 16 },
  card: {
    background: "#fff",
    borderRadius: 18,
    padding: 18,
    border: "1px solid rgba(10,42,85,0.06)",
    boxShadow: "0 6px 18px rgba(10,42,85,0.05)",
  },
  cardTitle: { fontFamily: "'Fredoka',sans-serif", fontWeight: 600, fontSize: 16, color: C.navy },
  cardDesc: { fontSize: 12.5, color: C.muted, marginTop: 2, lineHeight: 1.4 },
  label: { fontSize: 12.5, fontWeight: 600, color: C.ink, margin: "12px 0 5px" },
  input: {
    width: "100%",
    boxSizing: "border-box",
    fontFamily: "'Inter',sans-serif",
    fontSize: 13.5,
    color: C.ink,
    padding: "10px 12px",
    borderRadius: 11,
    border: "1px solid #DFE6EF",
    outline: "none",
    background: "#fff",
    transition: "border 0.15s, box-shadow 0.15s",
  },
  area: { resize: "vertical", lineHeight: 1.5, minHeight: 44 },
  kbItem: { background: C.field, borderRadius: 13, padding: 12, border: "1px solid #E4EAF2" },
  dropzone: {
    display: "flex",
    flexDirection: "column",
    alignItems: "center",
    textAlign: "center",
    padding: "20px 16px",
    borderRadius: 14,
    border: "1.8px dashed #BBD4F0",
    background: "#F5F9FF",
    cursor: "pointer",
    transition: "background 0.15s, border-color 0.15s",
  },
  dropIcon: {
    width: 42,
    height: 42,
    borderRadius: 12,
    background: "#E3F0FD",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 8,
  },
  uploading: {
    display: "flex",
    alignItems: "center",
    marginTop: 12,
    padding: "10px 12px",
    borderRadius: 10,
    background: C.soft,
    border: `1px solid ${C.line}`,
    fontSize: 13,
    color: C.azure,
  },
  badgeDoc: {
    fontSize: 10.5,
    fontWeight: 700,
    letterSpacing: 0.3,
    color: "#fff",
    background: "linear-gradient(135deg,#2E8AE8,#1257A8)",
    padding: "3px 8px",
    borderRadius: 6,
    whiteSpace: "nowrap",
  },
  badgeManual: {
    fontSize: 10.5,
    fontWeight: 700,
    letterSpacing: 0.3,
    color: C.muted,
    background: "#EEF2F7",
    padding: "3px 8px",
    borderRadius: 6,
    whiteSpace: "nowrap",
  },
  delBtn: {
    width: 32,
    height: 32,
    borderRadius: 9,
    border: "1px solid #F0D8D8",
    background: "#FDF3F3",
    color: "#C0554F",
    cursor: "pointer",
    fontSize: 13,
    flexShrink: 0,
  },
  addBtn: {
    marginTop: 12,
    width: "100%",
    padding: "11px",
    borderRadius: 12,
    border: "1.5px dashed #BBD4F0",
    background: "#F5F9FF",
    color: C.azure,
    fontWeight: 600,
    fontSize: 13.5,
    cursor: "pointer",
    fontFamily: "'Inter',sans-serif",
  },
  toggleRow: {
    display: "flex",
    alignItems: "center",
    justifyContent: "space-between",
    padding: "10px 0",
    cursor: "pointer",
    borderBottom: `1px solid ${C.line}`,
  },
  toggleLabel: { fontSize: 13.5, color: C.ink, paddingRight: 12 },
  track: {
    width: 38,
    height: 22,
    borderRadius: 999,
    position: "relative",
    flexShrink: 0,
    transition: "background 0.18s",
  },
  knob: {
    position: "absolute",
    top: 2,
    left: 2,
    width: 18,
    height: 18,
    borderRadius: "50%",
    background: "#fff",
    boxShadow: "0 1px 3px rgba(0,0,0,0.25)",
    transition: "transform 0.18s",
  },
  pre: {
    margin: 0,
    background: C.navy,
    color: "#CFE3FF",
    padding: 14,
    borderRadius: 12,
    fontSize: 11.5,
    lineHeight: 1.5,
    fontFamily: "ui-monospace,SFMono-Regular,Menlo,monospace",
    whiteSpace: "pre-wrap",
    wordBreak: "break-word",
    maxHeight: 340,
    overflowY: "auto",
  },

  // chat
  chatOuter: { display: "flex", flexDirection: "column", alignItems: "center", gap: 14 },
  loginAs: {
    display: "flex",
    alignItems: "center",
    gap: 10,
    flexWrap: "wrap",
    justifyContent: "center",
    background: "#fff",
    padding: "10px 16px",
    borderRadius: 14,
    border: "1px solid rgba(10,42,85,0.06)",
    boxShadow: "0 4px 14px rgba(10,42,85,0.05)",
  },
  phone: {
    width: "100%",
    maxWidth: 440,
    height: 620,
    maxHeight: "82vh",
    display: "flex",
    flexDirection: "column",
    background: "#fff",
    borderRadius: 26,
    overflow: "hidden",
    boxShadow: "0 24px 60px rgba(10,42,85,0.16), 0 4px 14px rgba(10,42,85,0.08)",
    border: "1px solid rgba(10,42,85,0.05)",
  },
  chatHeader: {
    position: "relative",
    display: "flex",
    alignItems: "center",
    gap: 12,
    padding: "16px 18px",
    background: "linear-gradient(120deg,#0A2A55 0%,#1257A8 55%,#1B6FD8 100%)",
    color: "#fff",
    overflow: "hidden",
  },
  headerGlow: {
    position: "absolute",
    top: -40,
    right: -20,
    width: 160,
    height: 160,
    borderRadius: "50%",
    background: "radial-gradient(circle,rgba(95,175,240,0.35) 0%,rgba(95,175,240,0) 70%)",
    pointerEvents: "none",
  },
  avatar: {
    position: "relative",
    width: 42,
    height: 42,
    borderRadius: 13,
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    background: "linear-gradient(135deg,#5FAFF0,#2E8AE8)",
    boxShadow: "0 4px 14px rgba(95,175,240,0.4)",
    flexShrink: 0,
  },
  chatTitle: { fontFamily: "'Fredoka',sans-serif", fontWeight: 600, fontSize: 17 },
  status: { display: "flex", alignItems: "center", gap: 6, fontSize: 12, opacity: 0.85, marginTop: 1 },
  dot: { width: 7, height: 7, borderRadius: "50%", background: C.sky, boxShadow: `0 0 8px ${C.sky}`, display: "inline-block" },
  thread: {
    flex: 1,
    overflowY: "auto",
    padding: "18px 16px",
    display: "flex",
    flexDirection: "column",
    gap: 12,
    background: "linear-gradient(180deg,#FFFFFF 0%,#FFFFFF 100%)",
  },
  typing: {
    display: "flex",
    gap: 4,
    alignItems: "center",
    alignSelf: "flex-start",
    padding: "12px 14px",
    background: C.soft,
    border: `1px solid ${C.line}`,
    borderRadius: 16,
    borderBottomLeftRadius: 5,
  },
  chipRow: { display: "flex", flexWrap: "wrap", gap: 8, padding: "0 16px 12px", background: "#FFFFFF" },
  chip: {
    fontFamily: "'Inter',sans-serif",
    fontSize: 12.5,
    color: C.azure,
    background: "#F2F7FF",
    border: "1px solid #CFE0F5",
    borderRadius: 999,
    padding: "7px 12px",
    cursor: "pointer",
    transition: "background 0.15s, transform 0.1s",
  },
  inputBar: { display: "flex", gap: 8, alignItems: "center", padding: "12px 14px", borderTop: `1px solid ${C.line}`, background: "#fff" },
  sendBtn: {
    width: 46,
    height: 46,
    flexShrink: 0,
    borderRadius: 14,
    border: "none",
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    background: "linear-gradient(135deg,#5FAFF0,#2E8AE8)",
    boxShadow: "0 4px 12px rgba(46,138,232,0.35)",
    cursor: "pointer",
    transition: "opacity 0.15s",
  },
};

const css = `
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Inter:wght@400;500;600&display=swap');
* { box-sizing: border-box; }
.ez-in:focus { border-color:${C.accent} !important; box-shadow:0 0 0 3px rgba(46,138,232,0.15); }
.ez-chip:hover { background:#DDEAF8 !important; transform:translateY(-1px); }
.ez-add:hover { background:#EAF3FE !important; }
.ez-drop:hover { background:#EAF3FE !important; border-color:#7FB4E8 !important; }
.ez-del:hover { background:#FBE9E9 !important; }
.ez-msg { animation: ezIn 0.28s ease both; }
@keyframes ezIn { from{opacity:0;transform:translateY(6px);} to{opacity:1;transform:translateY(0);} }
.ez-td { width:6px;height:6px;border-radius:50%;background:${C.accent};display:inline-block;animation:ezB 1.2s infinite ease-in-out; }
.ez-td:nth-child(2){animation-delay:.15s;} .ez-td:nth-child(3){animation-delay:.3s;}
@keyframes ezB { 0%,60%,100%{transform:translateY(0);opacity:.4;} 30%{transform:translateY(-5px);opacity:1;} }
::-webkit-scrollbar { width:7px; }
::-webkit-scrollbar-thumb { background:#D3E4E0;border-radius:10px; }
::-webkit-scrollbar-track { background:transparent; }
@media (prefers-reduced-motion: reduce){ *{animation:none !important;transition:none !important;} }
`;
