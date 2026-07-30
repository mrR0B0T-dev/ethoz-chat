<?php

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
        $setting = static::firstOrCreate([]); // selalu ada 1 baris

        // Baris baru hanya berisi id + timestamps; nilai bawaan ada di sisi DB,
        // jadi harus dibaca ulang agar bot_name/tone/dst. tidak kosong.
        return $setting->wasRecentlyCreated ? $setting->refresh() : $setting;
    }
}
