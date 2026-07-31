<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotMessage extends Model
{
    /** Nilai kolom `outcome`. */
    public const ANSWERED = 'answered';

    public const NO_CONTEXT = 'no_context';

    public const FALLBACK = 'fallback';

    protected $guarded = [];

    protected $casts = [
        'sources' => 'array',
        'latency_ms' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function scopeAssistant($query)
    {
        return $query->where('role', 'assistant');
    }
}
