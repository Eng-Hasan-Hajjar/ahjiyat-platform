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
            'went_live_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // انتقال حقيقي: إنشاء موسم منشور، أو نشر موسم موجود. القرار الفعلي "هل صار مباشرًا؟" بـSeasonLifecycleService وحده.
        static::created(fn (Season $season) => \App\Services\SeasonLifecycleService::syncFromHook($season));

        static::updated(function (Season $season) {
            if ($season->wasChanged('is_published')) {
                \App\Services\SeasonLifecycleService::syncFromHook($season);
            }
        });
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