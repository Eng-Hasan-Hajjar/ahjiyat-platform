<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSighting extends Model
{
    protected $fillable = [
        'user_id', 'ip_address', 'device_hash', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
