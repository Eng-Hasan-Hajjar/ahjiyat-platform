<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyPack extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku', 'currency_id', 'name', 'description', 'base_amount', 'bonus_amount',
        'price_minor', 'fiat_currency', 'image_path', 'is_active',
        'starts_at', 'ends_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function totalAmount(): int
    {
        return $this->base_amount + $this->bonus_amount;
    }

    public function priceDisplay(): string
    {
        return number_format($this->price_minor / 100, 2).' '.$this->fiat_currency;
    }

    public function isCurrentlyAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return $this->currency?->is_active ?? false;
    }
}