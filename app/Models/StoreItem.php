<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreItem extends Model
{
    use HasFactory;

    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_DIGITAL = 'digital';
    public const TYPE_COSMETIC = 'cosmetic';
    public const TYPE_CONSUMABLE = 'consumable';
    public const TYPE_ACCESS = 'access';

    public const FULFILLMENT_INVENTORY = 'inventory';
    public const FULFILLMENT_ENTITLEMENT = 'entitlement';
    public const FULFILLMENT_MANUAL = 'manual';

    protected $fillable = [
        'sku', 'slug', 'name', 'short_description', 'description',
        'item_type', 'fulfillment_type', 'image_path',
        'is_active', 'is_featured', 'stock_limit', 'per_user_limit', 'grant_quantity',
        'entitlement_key', 'entitlement_duration_days',
        'scope_type', 'scope_id', 'starts_at', 'ends_at', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StoreItemPrice::class);
    }

    public function activePrices(): HasMany
    {
        return $this->prices()->where('is_active', true);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(StorePurchase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scope(): ?Model
    {
        if ($this->scope_type === null || $this->scope_id === null) {
            return null;
        }

        return match ($this->scope_type) {
            'season' => Season::find($this->scope_id),
            'campaign' => Campaign::find($this->scope_id),
            default => null,
        };
    }

    public function isWithinValidityWindow(): bool
    {
        $now = now();

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->is_active && $this->isWithinValidityWindow();
    }

    public function isPurchasable(): bool
    {
        return $this->isPubliclyVisible();
    }

    public function remainingStock(): ?int
    {
        if ($this->stock_limit === null) {
            return null;
        }

        $consumed = $this->purchases()
            ->whereIn('status', [StorePurchase::STATUS_PENDING_FULFILLMENT, StorePurchase::STATUS_FULFILLED])
            ->count();

        return max(0, $this->stock_limit - $consumed);
    }

    public function isSoldOut(): bool
    {
        $remaining = $this->remainingStock();

        return $remaining !== null && $remaining <= 0;
    }

    public function isProtected(): bool
    {
        return $this->purchases()->exists();
    }
}