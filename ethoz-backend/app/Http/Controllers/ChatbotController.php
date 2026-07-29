<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function send(Request $request, ChatbotService $service)
    {
        $data = $request->validate([
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
        ]);

        // Pastikan riwayat diawali giliran user
        $messages = collect($data['messages'])
            ->skipUntil(fn ($m) => $m['role'] === 'user')
            ->values()->all();

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1000,
            'system' => $service->buildSystemPrompt($request->user()),
            'messages' => $messages,
        ]);

        if ($response->failed()) {
            return response()->json(['reply' => 'Maaf, asisten sedang sibuk. Coba lagi sebentar.'], 200);
        }

        $reply = collect($response->json('content'))
            ->where('type', 'text')->pluck('text')->implode("\n");

        return response()->json(['reply' => trim($reply) ?: 'Maaf, saya belum bisa memproses itu.']);
    }
}
