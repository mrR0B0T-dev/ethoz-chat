<?php

namespace App\Services;

use App\Models\ChatbotKnowledge;
use App\Models\ChatbotSetting;

class ChatbotService
{
    /** Tentukan peran user dari sistem Ethoz. Sesuaikan dengan skema Anda. */
    protected function roleOf($user): string
    {
        // kolom 'role' berisi 'staff' | 'hr' | 'manager' | 'admin'
        return $user->role ?? 'staff';
    }

    protected function scopeAllows(string $scope, string $role): bool
    {
        if ($scope === 'all') {
            return true;
        }
        if ($scope === 'hr_manager') {
            return in_array($role, ['hr', 'manager']);
        }

        return $scope === $role;
    }

    public function buildSystemPrompt($user): string
    {
        $cfg = ChatbotSetting::current();
        $role = $this->roleOf($user);

        $roleLabel = ['staff' => 'Staff/Pegawai', 'hr' => 'HR', 'manager' => 'Manager'][$role] ?? 'Pegawai';

        $allowed = ChatbotKnowledge::where('is_active', true)->get()
            ->filter(fn ($k) => $this->scopeAllows($k->scope, $role));

        $kb = $allowed->count()
            ? $allowed->map(fn ($k) => "[{$k->title}]\n{$k->content}")->implode("\n\n")
            : '(Tidak ada informasi yang tersedia untuk peran ini.)';

        $lengthMap = [
            'singkat' => 'Jawab sangat ringkas, 1–3 kalimat.',
            'sedang' => 'Jawab ringkas dan secukupnya.',
            'detail' => 'Boleh menjawab lebih lengkap bila diperlukan.',
        ];
        $langMap = [
            'id' => 'Selalu jawab dalam Bahasa Indonesia.',
            'en' => 'Always answer in English.',
            'follow' => 'Jawab mengikuti bahasa yang dipakai pengguna.',
        ];
        $toneMap = [
            'formal' => 'Gunakan nada formal dan resmi.',
            'ramah' => 'Gunakan nada ramah namun profesional.',
            'santai' => 'Gunakan nada santai dan akrab.',
        ];

        $L = [];
        $L[] = "Kamu adalah \"{$cfg->bot_name}\", asisten AI di dalam aplikasi {$cfg->company}.";
        if (trim($cfg->role ?? '')) {
            $L[] = trim($cfg->role);
        }
        $L[] = "Pengguna yang sedang bertanya berperan sebagai: {$roleLabel}.";
        $L[] = '';
        $L[] = 'GAYA & PERILAKU:';
        $L[] = '- '.($toneMap[$cfg->tone] ?? $toneMap['ramah']);
        $L[] = "- Sapa pengguna dengan \"{$cfg->address}\".";
        $L[] = '- '.($langMap[$cfg->language] ?? $langMap['id']);
        $L[] = '- '.($lengthMap[$cfg->max_length] ?? $lengthMap['sedang']);
        $L[] = '- '.($cfg->emoji ? 'Boleh memakai emoji secukupnya.' : 'Jangan memakai emoji.');
        $L[] = '- '.($cfg->allow_bullets ? 'Boleh memakai daftar berpoin dengan tanda "-".' : 'Jawab dalam paragraf, hindari daftar berpoin.');
        $L[] = '- Jawab dalam teks biasa tanpa format markdown.';
        if (trim($cfg->extra ?? '')) {
            $L[] = '- '.trim($cfg->extra);
        }
        $L[] = '';
        $L[] = 'BATASAN:';
        if ($cfg->no_hallucination) {
            $L[] = '- Jangan mengarang informasi yang tidak ada di BASIS PENGETAHUAN. Jika tidak tahu, arahkan ke HR/atasan.';
        }
        if ($cfg->protect_sensitive) {
            $L[] = '- Jangan menampilkan data pribadi sensitif (gaji spesifik, NIK, data medis). Arahkan ke kanal resmi HR.';
        }
        $L[] = '- Hanya jawab berdasarkan informasi yang tersedia untuk peran pengguna ini.';
        $blocked = collect(preg_split('/[\n,]+/', $cfg->blocked_topics ?? ''))
            ->map(fn ($s) => trim($s))->filter()->values();
        if ($blocked->count()) {
            $L[] = '- Tolak dengan sopan bila ditanya soal: '.$blocked->implode(', ').'.';
        }
        $L[] = '';
        $L[] = 'BASIS PENGETAHUAN (hanya yang boleh diakses peran ini):';
        $L[] = $kb;

        return implode("\n", $L);
    }
}
