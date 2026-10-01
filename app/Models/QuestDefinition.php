<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestDefinition extends Model
{
    use HasFactory;

    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_TYPES = [self::PERIOD_DAILY, self::PERIOD_WEEKLY];

    protected static function booted(): void
    {
        static::saving(function (QuestDefinition $quest) {
            app(\App\Services\Engagement\QuestDefinitionInvariantGuard::class)->enforce($quest);
        });

        static::deleting(function (QuestDefinition $quest) {
            app(\App\Services\Engagement\QuestDefinitionInvariantGuard::class)->enforceDeletable($quest);
        });
    }

    protected $fillable = [
        'internal_key', 'name', 'description', 'period_type', 'condition_type', 'target_value',
        'scope_type', 'scope_id',
        'xp_reward', 'reward_currency_id', 'reward_currency_amount', 'reward_store_item_id', 'reward_item_quantity',
        'is_active', 'sort_order', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function rewardStoreItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'reward_store_item_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserQuestProgress::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** بند 48 (E13 Part2): أي صف تقدُّم - حتى غير مكتمل - يعني بدء استخدام فعلي. */
    public function isUsed(): bool
    {
        return $this->progress()->exists();
    }
}
