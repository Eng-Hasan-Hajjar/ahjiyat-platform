<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudFlag extends Model
{
    protected $fillable = [
        'user_id', 'reason', 'severity', 'details', 'resolved',
    ];

    protected function casts(): array
    {
        return ['resolved' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
