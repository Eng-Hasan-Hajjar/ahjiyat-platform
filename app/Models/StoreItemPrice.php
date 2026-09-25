<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreItemPrice extends Model
{
    use HasFactory;

    protected $fillable = ['store_item_id', 'currency_id', 'amount', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(StorePurchase::class, 'store_item_price_id');
    }

    public function isProtected(): bool
    {
        return $this->purchases()->exists();
    }
}