<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StorePurchase extends Model
{
    use HasFactory;

    public const STATUS_PENDING_FULFILLMENT = 'pending_fulfillment';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'store_item_id', 'store_item_price_id', 'currency_id', 'price_amount',
        'status', 'request_key', 'item_snapshot', 'fulfillment_type',
        'fulfilled_by', 'fulfilled_at', 'refunded_by', 'refunded_at',
        'admin_note', 'user_message',
    ];

    protected function casts(): array
    {
        return [
            'item_snapshot' => 'array',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(StoreItemPrice::class, 'store_item_price_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(UserEntitlement::class, 'store_purchase_id');
    }

    public function isRefundable(): bool
    {
        if ($this->status === self::STATUS_PENDING_FULFILLMENT) {
            return true;
        }

        if ($this->status === self::STATUS_FULFILLED) {
            return $this->fulfillment_type !== StoreItem::FULFILLMENT_MANUAL;
        }

        return false;
    }
}