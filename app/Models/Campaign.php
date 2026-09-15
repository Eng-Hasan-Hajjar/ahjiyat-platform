<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'title', 'description', 'cover_image',
        'is_active', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CampaignStage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Campaign عادية أو راعٍ لا تملك Season - Nullable عمداً. */
    public function season(): HasOne
    {
        return $this->hasOne(Season::class);
    }
}