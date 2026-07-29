<?php

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
            'file' => 'required|file|mimes:pdf,docx,txt,md|max:10240', // maks 10 MB
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
            'title' => $r->title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'content' => $text,
            'scope' => $r->scope,
            'source' => 'document',
            'file_name' => $file->getClientOriginalName(),
            'char_count' => mb_strlen($text),
            'is_active' => true,
        ]);
    }
}
