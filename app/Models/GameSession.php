<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جلسة عامة لأي نوع لعبة Stateful (يحتاج تفاعلاً وسيطاً قبل الحسم النهائي).
 * لا علاقة لها بأي حملة أو قصة - أول مستهلك لها هو spot_difference، لكنها
 * مصمَّمة لخدمة أي نوع مستقبلي مشابه (Memory محصَّنة، Hotspot، ألعاب موقوتة).
 */
class GameSession extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'user_id', 'puzzle_id', 'context_type', 'context_id',
        'status', 'started_at', 'expires_at', 'completed_at', 'server_state',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'server_state' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }
}