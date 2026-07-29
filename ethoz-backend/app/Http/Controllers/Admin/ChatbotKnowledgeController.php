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
            'title' => 'required|string|max:120',
            'content' => 'required|string|max:5000',
            'scope' => 'required|in:all,hr,manager,hr_manager',
            'is_active' => 'boolean',
        ]);
    }
}
