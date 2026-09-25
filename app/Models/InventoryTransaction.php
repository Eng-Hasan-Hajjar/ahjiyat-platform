<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryTransaction extends Model
{
    use HasFactory;

    public const TYPE_GRANT = 'grant';
    public const TYPE_REVOKE = 'revoke';

    public $timestamps = false;

    protected $fillable = ['user_id', 'store_item_id', 'quantity', 'type', 'reason', 'reference_type', 'reference_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (InventoryTransaction $transaction) {
            $transaction->created_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}