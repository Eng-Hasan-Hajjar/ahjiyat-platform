<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'code', 'slug', 'banner_image', 'logo_image',
        'grand_prize_description', 'theme_config', 'is_published', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'theme_config' => 'array',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function themePreset(): string
    {
        return $this->theme_config['preset'] ?? 'generic';
    }

    public function heroTagline(): ?string
    {
        return $this->theme_config['hero_tagline'] ?? null;
    }
}