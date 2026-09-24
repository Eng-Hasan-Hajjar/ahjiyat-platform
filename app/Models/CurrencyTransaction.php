<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CurrencyTransaction extends Model
{
    use HasFactory;

    protected $table = 'currency_transactions';

    public const TYPE_EARN_PENDING = 'earn_pending';
    public const TYPE_RELEASE_AVAILABLE = 'release_available';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SPEND = 'spend';

    protected $fillable = [
        'user_id', 'currency_id', 'amount', 'type', 'reason',
        'reference_type', 'reference_id', 'metadata', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}