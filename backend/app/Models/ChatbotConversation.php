<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotConversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Judul diambil dari pertanyaan pertama agar mudah dikenali di pemantauan. */
    public static function titleFrom(string $question): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

        return mb_strlen($clean) > 120 ? mb_substr($clean, 0, 117).'…' : $clean;
    }
}
