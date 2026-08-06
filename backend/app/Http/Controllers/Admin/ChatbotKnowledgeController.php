<?php

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
        // refresh() agar kolom bernilai bawaan DB (is_active, source, char_count)
        // ikut terkirim — bentuk JSON-nya jadi sama dengan index().
        return ChatbotKnowledge::create($this->rules($r))->refresh();
    }

    public function update(Request $r, ChatbotKnowledge $knowledge)
    {
        $data = $this->rules($r);

        // Admin menyunting isinya sendiri (mis. menempel teks dokumen yang
        // gagal di-OCR) — entri itu kembali siap dipakai bot.
        if ($knowledge->status !== ChatbotKnowledge::STATUS_DONE) {
            $data['status'] = ChatbotKnowledge::STATUS_DONE;
            $data['status_message'] = null;
        }

        $data['char_count'] = mb_strlen($data['content']);

        $knowledge->update($data);

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
            'title' => 'required|string|max:120',
            // Batas lama 5.000 karakter membuat entri hasil unggahan dokumen
            // tidak bisa disunting ulang — dokumen wajar saja melampauinya.
            'content' => 'required|string|max:'.config('chatbot.content_max_chars'),
            'scope' => 'required|in:all,hr,manager,hr_manager',
            'is_active' => 'boolean',
        ]);
    }
}
