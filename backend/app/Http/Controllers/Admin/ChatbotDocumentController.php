<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotKnowledge;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatbotDocumentController extends Controller
{
    public function store(Request $r, DocumentTextExtractor $extractor)
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

        // Dokumen besar butuh waktu lebih (rasterisasi halaman + OCR).
        @set_time_limit(0);

        $text = trim($extractor->extract($file->getRealPath(), $file->getClientOriginalExtension()));

        if ($text === '') {
            // Sampaikan sebab yang sebenarnya, bukan tebakan umum.
            $reason = $extractor->notice() ?? 'Tidak ada teks yang bisa dibaca dari berkas ini.';

            Log::info('Unggah dokumen: tidak ada teks terbaca.', [
                'file' => $file->getClientOriginalName(),
                'reason' => $reason,
            ]);

            return response()->json(['message' => $reason], 422);
        }

        $max = (int) config('chatbot.content_max_chars');
        $truncated = mb_strlen($text) > $max;

        if ($truncated) {
            $text = mb_substr($text, 0, $max);

            Log::warning('Unggah dokumen: teks dipotong pada batas konfigurasi.', [
                'file' => $file->getClientOriginalName(),
                'limit' => $max,
            ]);
        }

        $entry = ChatbotKnowledge::create([
            'title' => $r->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'content' => $text,
            'scope' => $r->scope,
            'source' => 'document',
            'file_name' => $file->getClientOriginalName(),
            'char_count' => mb_strlen($text),
            'is_active' => true,
        ]);

        // Catatan tambahan (mis. OCR belum terpasang padahal berkas memuat
        // gambar) ikut dikirim agar admin tahu ada bagian yang mungkin terlewat.
        $payload = $entry->toArray();

        if ($truncated) {
            $payload['notice'] = 'Dokumen sangat panjang dan dipotong pada '.number_format($max).' karakter.';
        } elseif ($note = $extractor->notice()) {
            $payload['notice'] = $note;
        }

        return response()->json($payload, 201);
    }
}
