<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractDocumentText;
use App\Models\ChatbotKnowledge;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatbotDocumentController extends Controller
{
    /**
     * Terima berkas, simpan sementara, lalu antrekan ekstraksinya.
     *
     * Ekstraksi tidak lagi dikerjakan di sini: PDF hasil pindai harus
     * dirasterkan lalu di-OCR halaman demi halaman — hitungan menit, terlalu
     * lama untuk ditunggu peramban. Yang dikembalikan adalah entri berstatus
     * "queued"; konsol admin memantau sisanya lewat endpoint status().
     */
    public function store(Request $r)
    {
        $r->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', DocumentTextExtractor::supportedExtensions()),
                'max:'.config('chatbot.upload_max_kb'),
            ],
            'scope' => 'required|in:all,hr,manager,hr_manager',
            'title' => 'nullable|string|max:120',
        ]);

        $file = $r->file('file');
        $disk = (string) config('chatbot.uploads.disk');

        // Berkas asli tidak ikut disimpan permanen — hanya dititipkan sampai
        // teksnya diambil, lalu dihapus oleh pekerjaan antrean.
        $stored = $file->store((string) config('chatbot.uploads.directory'), $disk);

        if ($stored === false) {
            Log::error('Unggah dokumen: berkas gagal disimpan ke penyimpanan sementara.', [
                'file' => $file->getClientOriginalName(),
                'disk' => $disk,
            ]);

            return response()->json([
                'message' => 'Berkas gagal disimpan di server. Coba lagi atau hubungi pengelola sistem.',
            ], 500);
        }

        $entry = ChatbotKnowledge::create([
            'title' => $r->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'content' => '',
            'scope' => $r->scope,
            'source' => 'document',
            'file_name' => $file->getClientOriginalName(),
            'char_count' => 0,
            'is_active' => true,
            'status' => ChatbotKnowledge::STATUS_QUEUED,
            'status_message' => 'Menunggu giliran diproses.',
        ]);

        ExtractDocumentText::dispatch(
            $entry->id,
            $disk,
            $stored,
            strtolower((string) $file->getClientOriginalExtension()),
            $file->getClientOriginalName(),
        );

        // 202: diterima, hasilnya belum ada. Entri sudah terlihat di daftar
        // dengan statusnya, jadi admin tidak menunggu tanpa kabar.
        return response()->json($entry->refresh(), 202);
    }

    /**
     * Status ringkas seluruh entri hasil unggahan — dipanggil berkala oleh
     * konsol selagi ada dokumen yang belum selesai.
     *
     * Sengaja tanpa kolom `content`: daftar pengetahuan bisa berukuran
     * megabyte, sedangkan yang dibutuhkan pemantau hanya statusnya.
     */
    public function status()
    {
        return ChatbotKnowledge::where('source', 'document')
            ->latest()
            ->get(['id', 'title', 'file_name', 'status', 'status_message', 'char_count']);
    }
}
