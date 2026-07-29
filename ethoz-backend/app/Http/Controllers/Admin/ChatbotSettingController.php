<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotSetting;
use Illuminate\Http\Request;

class ChatbotSettingController extends Controller
{
    public function show()
    {
        // Dibungkus response()->json agar tetap 200 — baris pengaturan bisa
        // baru dibuat di sini, dan model "recently created" akan jadi 201.
        return response()->json(ChatbotSetting::current());
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'bot_name' => 'required|string|max:80',
            'company' => 'required|string|max:120',
            'role' => 'nullable|string|max:500',
            'tone' => 'required|in:formal,ramah,santai',
            'address' => 'required|in:Anda,Kamu',
            'emoji' => 'boolean',
            'allow_bullets' => 'boolean',
            'extra' => 'nullable|string|max:500',
            'no_hallucination' => 'boolean',
            'protect_sensitive' => 'boolean',
            'max_length' => 'required|in:singkat,sedang,detail',
            'language' => 'required|in:id,en,follow',
            'blocked_topics' => 'nullable|string|max:1000',
        ]);

        $setting = ChatbotSetting::current();
        $setting->update($data);

        return response()->json($setting);
    }
}
