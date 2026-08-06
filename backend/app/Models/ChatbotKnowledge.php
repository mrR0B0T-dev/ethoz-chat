<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChatbotKnowledge extends Model
{
    /** Menunggu giliran queue worker. */
    public const STATUS_QUEUED = 'queued';

    /** Sedang diekstrak (termasuk OCR). */
    public const STATUS_PROCESSING = 'processing';

    /** Isinya siap dipakai bot. */
    public const STATUS_DONE = 'done';

    /** Ekstraksi gagal — alasannya ada di status_message. */
    public const STATUS_FAILED = 'failed';

    protected $table = 'chatbot_knowledge';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * Entri yang boleh ikut ke prompt bot.
     *
     * Entri yang masih diantre/diproses isinya belum ada, dan entri gagal
     * hanya memuat alasan kegagalan — keduanya tidak boleh sampai ke model.
     */
    public function scopeReady(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('status', self::STATUS_DONE);
    }

    /** Entri yang statusnya masih bergerak — dipantau konsol admin. */
    public function scopePending(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING]);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            'status_message' => null,
        ])->save();
    }

    public function markDone(string $content, ?string $message = null): void
    {
        $this->forceFill([
            'content' => $content,
            'char_count' => mb_strlen($content),
            'status' => self::STATUS_DONE,
            'status_message' => $message,
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'status_message' => $message,
            'processed_at' => now(),
        ])->save();
    }
}
