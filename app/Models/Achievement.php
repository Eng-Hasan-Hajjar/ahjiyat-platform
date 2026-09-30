<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achievement extends Model
{
    use HasFactory;

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_PUZZLES = 'puzzles';
    public const CATEGORY_CAMPAIGNS = 'campaigns';
    public const CATEGORY_QUALIFICATIONS = 'qualifications';
    public const CATEGORY_MASTERY = 'mastery';

    protected static function booted(): void
    {
        static::saving(function (Achievement $achievement) {
            app(\App\Services\Progression\AchievementInvariantGuard::class)->enforce($achievement);
        });

        static::deleting(function (Achievement $achievement) {
            app(\App\Services\Progression\AchievementInvariantGuard::class)->enforceDeletable($achievement);
        });
    }

    protected $fillable = [
        'internal_key', 'name', 'description', 'category', 'condition_type', 'target_value',
        'scope_type', 'scope_id',
        'xp_reward', 'reward_currency_id', 'reward_currency_amount', 'reward_store_item_id', 'reward_item_quantity',
        'is_active', 'is_hidden', 'sort_order', 'icon_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_hidden' => 'boolean',
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
        return $this->hasMany(UserAchievementProgress::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsed(): bool
    {
        return $this->progress()->exists();
    }
}