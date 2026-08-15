<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GemTransaction extends Model
{
    public const TYPE_EARN_PENDING = 'earn_pending';

    public const TYPE_RELEASE_AVAILABLE = 'release_available';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_EXPIRE = 'expire';

    public const TYPE_ADMIN_ADJUSTMENT = 'admin_adjustment';

    protected $fillable = [
        'user_id', 'amount', 'type', 'reason', 'reference_type', 'reference_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
