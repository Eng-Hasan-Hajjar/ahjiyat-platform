<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LevelDefinition extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (LevelDefinition $level) {
            app(\App\Services\Progression\LevelDefinitionInvariantGuard::class)->enforce($level);
        });

        static::deleting(function (LevelDefinition $level) {
            app(\App\Services\Progression\LevelDefinitionInvariantGuard::class)->enforceDeletable($level);
        });
    }

    protected $fillable = [
        'level_number', 'name', 'description', 'xp_required_total',
        'reward_currency_id', 'reward_currency_amount', 'reward_store_item_id', 'reward_item_quantity',
        'icon_path', 'color', 'is_active', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function rewardStoreItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'reward_store_item_id');
    }

    public function unlocks(): HasMany
    {
        return $this->hasMany(UserLevelUnlock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsed(): bool
    {
        return $this->unlocks()->exists();
    }
}