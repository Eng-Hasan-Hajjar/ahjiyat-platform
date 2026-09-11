<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'pending_balance', 'available_balance',
        'lifetime_earned', 'lifetime_redeemed',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalBalance(): int
    {
        return $this->pending_balance + $this->available_balance;
    }
}