<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProgression extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'total_xp', 'current_level', 'last_xp_at'];

    protected function casts(): array
    {
        return ['last_xp_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}