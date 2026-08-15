<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PuzzleCategory extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'icon', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function puzzles(): HasMany
    {
        return $this->hasMany(Puzzle::class);
    }
}
