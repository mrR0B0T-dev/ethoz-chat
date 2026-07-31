# Backend Laravel — Ethoz Chat

Panduan implementasi backend agar chatbot bisa dipakai pegawai **cukup dengan login Ethoz**, tanpa akun AI apa pun. API key rahasia perusahaan tersimpan di server dan tidak pernah dikirim ke aplikasi pegawai.

## Alur permintaan

```
Pegawai (login Ethoz)
      │  POST /chatbot/send  (kirim cookie/token sesi, TANPA API key)
      ▼
Laravel  ── cek sesi login (auth) ──► tolak jika belum login
      │  ── ChatbotService: susun system prompt dari DB
      │       (identitas + gaya + batasan + pengetahuan sesuai PERAN user)
      │  ── tempel ANTHROPIC_API_KEY (dari .env, di server)
      ▼
Claude API  ──► jawaban ──► Laravel ──► ditampilkan di Ethoz
```

Admin mengelola isi lewat halaman admin → tersimpan di database → otomatis dipakai saat menyusun system prompt. Tidak perlu ubah kode untuk mengganti kebijakan/gaya bahasa.

---

## 1. Kunci API & konfigurasi

`.env`
```env
ANTHROPIC_API_KEY=sk-ant-xxxxxxxx
ANTHROPIC_MODEL=claude-sonnet-5
```

`config/services.php`
```php
'anthropic' => [
    'key'   => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
],
```
> Untuk menekan biaya, `claude-haiku-4-5-20251001` jauh lebih murah dan tetap memadai untuk Q&A helpdesk.

---

## 2. Migrasi database

`php artisan make:migration create_chatbot_tables`

```php
public function up(): void
{
    // Pengaturan bot — satu baris konfigurasi
    Schema::create('chatbot_settings', function (Blueprint $t) {
        $t->id();
        $t->string('bot_name')->default('Ethoz Chat');
        $t->string('company')->default('PT BDP');
        $t->text('role')->nullable();
        $t->string('tone')->default('ramah');        // formal | ramah | santai
        $t->string('address')->default('Anda');       // Anda | Kamu
        $t->boolean('emoji')->default(false);
        $t->boolean('allow_bullets')->default(true);
        $t->text('extra')->nullable();
        $t->boolean('no_hallucination')->default(true);
        $t->boolean('protect_sensitive')->default(true);
        $t->string('max_length')->default('sedang');  // singkat | sedang | detail
        $t->string('language')->default('id');        // id | en | follow
        $t->text('blocked_topics')->nullable();
        $t->timestamps();
    });

    // Basis pengetahuan
    Schema::create('chatbot_knowledge', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->text('content');
        $t->string('scope')->default('all');          // all | hr | manager | hr_manager
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
}
```

---

## 3. Model

`app/Models/ChatbotSetting.php`
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotSetting extends Model
{
    protected $guarded = [];
    protected $casts = [
        'emoji' => 'boolean',
        'allow_bullets' => 'boolean',
        'no_hallucination' => 'boolean',
        'protect_sensitive' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([]); // selalu ada 1 baris
    }
}
```

`app/Models/ChatbotKnowledge.php`
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotKnowledge extends Model
{
    protected $table = 'chatbot_knowledge';
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
}
```

---

## 4. Service — penyusun system prompt

Inilah versi PHP dari logika yang ada di prototipe (identitas + gaya + batasan + filter pengetahuan berdasarkan peran).

`app/Services/ChatbotService.php`
```php
namespace App\Services;

use App\Models\ChatbotSetting;
use App\Models\ChatbotKnowledge;

class ChatbotService
{
    /** Tentukan peran user dari sistem Ethoz. Sesuaikan dengan skema Anda. */
    protected function roleOf($user): string
    {
        // contoh: kolom 'role' berisi 'staff' | 'hr' | 'manager'
        return $user->role ?? 'staff';
    }

    protected function scopeAllows(string $scope, string $role): bool
    {
        if ($scope === 'all') return true;
        if ($scope === 'hr_manager') return in_array($role, ['hr', 'manager']);
        return $scope === $role;
    }

    public function buildSystemPrompt($user): string
    {
        $cfg  = ChatbotSetting::current();
        $role = $this->roleOf($user);

        $roleLabel = ['staff' => 'Staff/Pegawai', 'hr' => 'HC', 'manager' => 'Manager'][$role] ?? 'Pegawai';

        $allowed = ChatbotKnowledge::where('is_active', true)->get()
            ->filter(fn ($k) => $this->scopeAllows($k->scope, $role));

        $kb = $allowed->count()
            ? $allowed->map(fn ($k) => "[{$k->title}]\n{$k->content}")->implode("\n\n")
            : '(Tidak ada informasi yang tersedia untuk peran ini.)';

        $lengthMap = [
            'singkat' => 'Jawab sangat ringkas, 1–3 kalimat.',
            'sedang'  => 'Jawab ringkas dan secukupnya.',
            'detail'  => 'Boleh menjawab lebih lengkap bila diperlukan.',
        ];
        $langMap = [
            'id'     => 'Selalu jawab dalam Bahasa Indonesia.',
            'en'     => 'Always answer in English.',
            'follow' => 'Jawab mengikuti bahasa yang dipakai pengguna.',
        ];
        $toneMap = [
            'formal' => 'Gunakan nada formal dan resmi.',
            'ramah'  => 'Gunakan nada ramah namun profesional.',
            'santai' => 'Gunakan nada santai dan akrab.',
        ];

        $L = [];
        $L[] = "Kamu adalah \"{$cfg->bot_name}\", asisten AI di dalam aplikasi {$cfg->company}.";
        if (trim($cfg->role)) $L[] = trim($cfg->role);
        $L[] = "Pengguna yang sedang bertanya berperan sebagai: {$roleLabel}.";
        $L[] = "";
        $L[] = "GAYA & PERILAKU:";
        $L[] = "- {$toneMap[$cfg->tone]}";
        $L[] = "- Sapa pengguna dengan \"{$cfg->address}\".";
        $L[] = "- {$langMap[$cfg->language]}";
        $L[] = "- {$lengthMap[$cfg->max_length]}";
        $L[] = "- " . ($cfg->emoji ? "Boleh memakai emoji secukupnya." : "Jangan memakai emoji.");
        $L[] = "- " . ($cfg->allow_bullets ? "Boleh memakai daftar berpoin dengan tanda \"-\"." : "Jawab dalam paragraf, hindari daftar berpoin.");
        $L[] = "- Jawab dalam teks biasa tanpa format markdown.";
        if (trim($cfg->extra)) $L[] = "- " . trim($cfg->extra);
        $L[] = "";
        $L[] = "BATASAN:";
        if ($cfg->no_hallucination)
            $L[] = "- Jangan mengarang informasi yang tidak ada di BASIS PENGETAHUAN. Jika tidak tahu, arahkan ke HC/atasan.";
        if ($cfg->protect_sensitive)
            $L[] = "- Jangan menampilkan data pribadi sensitif (gaji spesifik, NIK, data medis). Arahkan ke kanal resmi HC.";
        $L[] = "- Hanya jawab berdasarkan informasi yang tersedia untuk peran pengguna ini.";
        $blocked = collect(preg_split('/[\n,]+/', $cfg->blocked_topics ?? ''))
            ->map(fn ($s) => trim($s))->filter()->values();
        if ($blocked->count())
            $L[] = "- Tolak dengan sopan bila ditanya soal: " . $blocked->implode(', ') . ".";
        $L[] = "";
        $L[] = "BASIS PENGETAHUAN (hanya yang boleh diakses peran ini):";
        $L[] = $kb;

        return implode("\n", $L);
    }
}
```

---

## 5. Controller sisi pegawai (proxy aman)

`app/Http/Controllers/ChatbotController.php`
```php
namespace App\Http\Controllers;

use App\Services\ChatbotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function send(Request $request, ChatbotService $service)
    {
        $data = $request->validate([
            'messages'             => 'required|array|min:1|max:40',
            'messages.*.role'      => 'required|in:user,assistant',
            'messages.*.content'   => 'required|string|max:4000',
        ]);

        // Pastikan riwayat diawali giliran user
        $messages = collect($data['messages'])
            ->skipUntil(fn ($m) => $m['role'] === 'user')
            ->values()->all();

        $response = Http::withHeaders([
            'x-api-key'         => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model'      => config('services.anthropic.model'),
            'max_tokens' => 1000,
            'system'     => $service->buildSystemPrompt($request->user()),
            'messages'   => $messages,
        ]);

        if ($response->failed()) {
            return response()->json(['reply' => 'Maaf, asisten sedang sibuk. Coba lagi sebentar.'], 200);
        }

        $reply = collect($response->json('content'))
            ->where('type', 'text')->pluck('text')->implode("\n");

        return response()->json(['reply' => trim($reply) ?: 'Maaf, saya belum bisa memproses itu.']);
    }
}
```

---

## 6. Controller sisi admin

`app/Http/Controllers/Admin/ChatbotKnowledgeController.php`
```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\Request;

class ChatbotKnowledgeController extends Controller
{
    public function index()
    {
        return ChatbotKnowledge::latest()->get();
    }

    public function store(Request $r)
    {
        return ChatbotKnowledge::create($this->rules($r));
    }

    public function update(Request $r, ChatbotKnowledge $knowledge)
    {
        $knowledge->update($this->rules($r));
        return $knowledge;
    }

    public function destroy(ChatbotKnowledge $knowledge)
    {
        $knowledge->delete();
        return response()->noContent();
    }

    protected function rules(Request $r): array
    {
        return $r->validate([
            'title'     => 'required|string|max:120',
            'content'   => 'required|string|max:5000',
            'scope'     => 'required|in:all,hr,manager,hr_manager',
            'is_active' => 'boolean',
        ]);
    }
}
```

`app/Http/Controllers/Admin/ChatbotSettingController.php`
```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotSetting;
use Illuminate\Http\Request;

class ChatbotSettingController extends Controller
{
    public function show()
    {
        return ChatbotSetting::current();
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'bot_name'          => 'required|string|max:80',
            'company'           => 'required|string|max:120',
            'role'              => 'nullable|string|max:500',
            'tone'              => 'required|in:formal,ramah,santai',
            'address'           => 'required|in:Anda,Kamu',
            'emoji'             => 'boolean',
            'allow_bullets'     => 'boolean',
            'extra'             => 'nullable|string|max:500',
            'no_hallucination'  => 'boolean',
            'protect_sensitive' => 'boolean',
            'max_length'        => 'required|in:singkat,sedang,detail',
            'language'          => 'required|in:id,en,follow',
            'blocked_topics'    => 'nullable|string|max:1000',
        ]);

        $setting = ChatbotSetting::current();
        $setting->update($data);
        return $setting;
    }
}
```

---

## 7. Routes

`routes/web.php` (atau `api.php` untuk mobile dengan Sanctum)
```php
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\Admin\ChatbotKnowledgeController;
use App\Http\Controllers\Admin\ChatbotSettingController;

// Sisi pegawai — cukup login Ethoz. throttle mencegah penyalahgunaan.
Route::middleware(['auth', 'throttle:30,1'])->group(function () {
    Route::post('/chatbot/send', [ChatbotController::class, 'send']);
});

// Sisi admin — login + izin kelola chatbot.
Route::middleware(['auth', 'can:manage-chatbot'])->prefix('admin/chatbot')->group(function () {
    Route::get('settings',  [ChatbotSettingController::class, 'show']);
    Route::put('settings',  [ChatbotSettingController::class, 'update']);
    Route::apiResource('knowledge', ChatbotKnowledgeController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});
```

Gate `manage-chatbot` di `app/Providers/AppServiceProvider.php` (boot):
```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-chatbot', fn ($user) => in_array($user->role, ['hr', 'admin']));
```

---

## 8. Cara Ethoz memanggilnya (sisi klien)

Aplikasi Ethoz **tidak pernah** menyentuh API key. Cukup kirim ke backend sendiri dengan sesi login yang sudah ada:

```js
const res = await fetch('/chatbot/send', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  credentials: 'include', // ikutkan cookie sesi Ethoz (atau Bearer token utk mobile)
  body: JSON.stringify({ messages }),
});
const { reply } = await res.json();
```

Untuk aplikasi mobile, gunakan **Laravel Sanctum**: token yang sama untuk login Ethoz dipakai sebagai `Authorization: Bearer <token>`, dan route dipindah ke `auth:sanctum`.

---

## Catatan keamanan & biaya

- **API key hanya di server** (`.env`). Jangan pernah menaruhnya di kode frontend atau aplikasi mobile.
- **`throttle:30,1`** membatasi 30 permintaan/menit per pegawai — cegah spam & lonjakan tagihan.
- **Filter peran** di `ChatbotService` memastikan pegawai biasa tidak bisa memancing info khusus HC/Manager, karena info itu tidak ikut dikirim ke AI.
- **Biaya per token** — untuk menghemat, pakai model Haiku dan batasi `max_tokens`. Simpan log pemakaian bila perlu memantau biaya.
- **Data real-time** (mis. sisa cuti pribadi) sebaiknya diarahkan lewat pemanggilan API internal Ethoz, bukan ditaruh di prompt — bisa ditambahkan sebagai langkah berikutnya (tool/function calling).

---

## 9. Inject informasi lewat unggah dokumen

Alih-alih mengetik manual, admin mengunggah file (kebijakan, jobdesc, struktur organisasi). Backend mengekstrak teksnya lalu menyimpannya sebagai entri basis pengetahuan — jadi bot langsung bisa memakainya.

### 9a. Kolom tambahan

`php artisan make:migration add_source_to_chatbot_knowledge`
```php
public function up(): void
{
    Schema::table('chatbot_knowledge', function (Blueprint $t) {
        $t->string('source')->default('manual');   // manual | document
        $t->string('file_name')->nullable();
        $t->integer('char_count')->default(0);
    });
}
```

### 9b. Dependensi ekstraksi

```bash
composer require smalot/pdfparser   # PDF
composer require phpoffice/phpword  # DOCX
```

#### Kemampuan ekstraksi saat ini

| Isi dokumen | Status | Catatan |
|---|---|---|
| Paragraf & daftar berpoin | ✅ | Satu paragraf tetap satu baris walau banyak gaya huruf |
| **Tabel Word** | ✅ | Ditulis sebagai `\| sel \| sel \|` agar hubungan antar kolom terbaca |
| Kop & kaki halaman | ✅ | Kerap memuat nomor memo dan unit kerja |
| Karakter khusus (`&`, `<`) | ✅ | Entitas XML dikembalikan ke bentuk aslinya |
| PDF ber-teks | ✅ | Diberi penanda `[Halaman n]` |
| **PDF hasil pindai / gambar** | ⚠️ | Perlu OCR — lihat di bawah |
| DOCX dengan XML tidak sah | ✅ | Ada jalur cadangan pembacaan XML mentah |
| Berkas non-UTF-8 | ✅ | Dideteksi lalu dikonversi |

#### Mengaktifkan OCR (teks di dalam gambar)

Tanpa OCR, dokumen hasil pindai dan gambar ditolak dengan pesan yang menjelaskan
sebabnya — bukan gagal diam-diam.

```bash
# Windows
winget install -e --id UB-Mannheim.TesseractOCR
winget install -e --id oschwartz10612.Poppler   # pdftoppm, untuk PDF pindai

# Linux
apt install tesseract-ocr tesseract-ocr-ind poppler-utils
```

Bila binernya tidak berada di PATH, tunjuk langsung lewat `.env`:

```env
TESSERACT_PATH="C:\Program Files\Tesseract-OCR\tesseract.exe"
PDFTOPPM_PATH="C:\poppler\bin\pdftoppm.exe"
OCR_LANG=ind+eng
OCR_MAX_PAGES=40
```

Seluruh setelan lain ada di `config/chatbot.php` (batas unggahan, panjang teks,
dan anggaran ukuran prompt).

> **Penting:** perbaikan ekstraksi hanya berlaku saat dokumen **diunggah**.
> Entri yang sudah tersimpan memuat hasil ekstraksi lama — berkas aslinya tidak
> disimpan, jadi tidak bisa diproses ulang. Unggah ulang dokumen lama agar
> tabelnya ikut terbaca.

### 9c. Helper ekstraksi teks

`app/Services/DocumentTextExtractor.php`
```php
namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;

class DocumentTextExtractor
{
    public function extract(string $path, string $ext): string
    {
        return match (strtolower($ext)) {
            'pdf'        => $this->pdf($path),
            'docx'       => $this->docx($path),
            'txt', 'md'  => (string) file_get_contents($path),
            default      => '',
        };
    }

    protected function pdf(string $path): string
    {
        return trim((new PdfParser())->parseFile($path)->getText());
    }

    protected function docx(string $path): string
    {
        $doc = IOFactory::load($path);
        $out = [];
        foreach ($doc->getSections() as $section) {
            $this->walk($section->getElements(), $out);
        }
        return trim(implode("\n", $out));
    }

    protected function walk($elements, array &$out): void
    {
        foreach ($elements as $el) {
            if (method_exists($el, 'getText')) {
                $t = $el->getText();
                if (is_string($t) && $t !== '') $out[] = $t;
            }
            if (method_exists($el, 'getElements')) {
                $this->walk($el->getElements(), $out);
            }
        }
    }
}
```
> Untuk PDF hasil scan (gambar), teks tidak akan terbaca — perlu langkah OCR (mis. Tesseract) sebelum ekstraksi.

### 9d. Controller unggah (sisi admin)

`app/Http/Controllers/Admin/ChatbotDocumentController.php`
```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotKnowledge;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\Request;

class ChatbotDocumentController extends Controller
{
    public function store(Request $r, DocumentTextExtractor $extractor)
    {
        $r->validate([
            'file'  => 'required|file|mimes:pdf,docx,txt,md|max:10240', // maks 10 MB
            'scope' => 'required|in:all,hr,manager,hr_manager',
            'title' => 'nullable|string|max:120',
        ]);

        $file = $r->file('file');
        $text = $extractor->extract($file->getRealPath(), $file->getClientOriginalExtension());

        if (trim($text) === '') {
            return response()->json([
                'message' => 'Teks tidak terbaca dari dokumen (mungkin PDF hasil scan). Coba OCR atau input manual.',
            ], 422);
        }

        return ChatbotKnowledge::create([
            'title'      => $r->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'content'    => $text,
            'scope'      => $r->scope,
            'source'     => 'document',
            'file_name'  => $file->getClientOriginalName(),
            'char_count' => mb_strlen($text),
            'is_active'  => true,
        ]);
    }
}
```

### 9e. Route

Tambahkan di grup admin (`auth` + `can:manage-chatbot`):
```php
Route::post('documents', [ChatbotDocumentController::class, 'store']);
```

### 9f. Cara admin mengunggah dari halaman admin

```js
const form = new FormData();
form.append('file', selectedFile);
form.append('scope', 'all');

await fetch('/admin/chatbot/documents', {
  method: 'POST',
  credentials: 'include',           // sesi login admin — tanpa API key
  headers: { 'X-CSRF-TOKEN': csrfToken },
  body: form,
});
```

Setelah tersimpan, entri dokumen ikut terbaca oleh `ChatbotService::buildSystemPrompt()` yang sudah ada (karena mengambil dari tabel `chatbot_knowledge`) — tanpa perubahan lain.

---

## 10. Saat dokumen mulai banyak/besar (upgrade ke RAG)

Menempelkan seluruh isi dokumen ke system prompt hanya sanggup untuk volume kecil–sedang. Begitu total dokumen besar, biaya per pertanyaan membengkak dan bisa melebihi batas konteks. Solusinya: **jangan kirim semua, kirim yang relevan saja.**

Pola retrieval (RAG):

1. **Pecah (chunk)** teks tiap dokumen jadi potongan ~500–1.000 karakter. Simpan di tabel `chatbot_chunks` (kolom: knowledge_id, scope, content, embedding).
2. **Embedding** tiap potongan jadi vektor lewat API embeddings, simpan (pakai `pgvector` di PostgreSQL, atau layanan vector DB).
3. **Saat pegawai bertanya:** ubah pertanyaan jadi vektor, ambil beberapa potongan paling mirip **yang boleh diakses peran itu**, lalu hanya potongan itu yang ditempel ke system prompt.

Alurnya:
```
Pertanyaan ──embed──► cari top-k chunk (filter scope peran)
        └─► tempel hanya chunk relevan ke prompt ──► Claude
```

Ini membuat jawaban tetap akurat, hemat token, dan skala ke ratusan halaman dokumen. Struktur `ChatbotService` tinggal diganti bagian "ambil semua knowledge" menjadi "ambil chunk relevan hasil pencarian".
