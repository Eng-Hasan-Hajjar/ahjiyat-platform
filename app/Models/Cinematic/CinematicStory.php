<?php

namespace App\Models\Cinematic;

use App\Models\CreatorSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * CIN-1 - القصة السينمائية: الهوية الثابتة. المحتوى بالنسخ (CinematicRevision). «قصيرة/طويلة» وسم تنظيمي فقط.
 * السلسلة المميزة تُربط بـcreator_series للقراءة فقط: الملكية من CreatorPremiumAccess (E43) ولا تُكتب من هنا.
 */
class CinematicStory extends Model
{
    public const KIND_SHORT = 'short';

    public const KIND_LONG = 'long';

    public const ACCESS_FREE = 'free';

    public const ACCESS_PREMIUM = 'premium';

    protected $table = 'cinematic_stories';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CinematicStory $story) {
            $story->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isPremium(): bool
    {
        return $this->access_mode === self::ACCESS_PREMIUM;
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CinematicRevision::class, 'story_id');
    }

    public function liveRevision(): HasOne
    {
        return $this->hasOne(CinematicRevision::class, 'story_id')->where('status', CinematicRevision::STATUS_PUBLISHED);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(CinematicMediaAsset::class, 'cover_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
