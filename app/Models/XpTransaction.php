<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class XpTransaction extends Model
{
    use HasFactory;

    public const TYPE_PUZZLE_SOLVE = 'puzzle_solve';
    public const TYPE_CAMPAIGN_STEP = 'campaign_step';
    public const TYPE_ACHIEVEMENT_REWARD = 'achievement_reward';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'amount', 'type', 'reason', 'source_type', 'source_id', 'idempotency_key', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (XpTransaction $transaction) {
            $transaction->created_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}