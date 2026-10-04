<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * E14: صفّ قاعدة البيانات لا يُنشئ مواضع جديدة - هو فقط يُفعِّل/يُعطِّل
 * موضعًا معروفًا بالفعل بسجلّ الكود (AdPlacementRegistry). internal_key
 * هو المفتاح الموثوق للربط بين الاثنين، لا الاسم المعروض.
 */
class AdPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_key', 'name', 'description', 'surface', 'position',
        'is_active', 'desktop_enabled', 'mobile_enabled', 'max_ads_per_render', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'desktop_enabled' => 'boolean',
        'mobile_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (AdPlacement $p) => \App\Services\Advertising\AdInvariantGuard::placementDeleting($p));
    }

    public function hasHistory(): bool
    {
        return AdImpression::where('ad_placement_id', $this->getKey())->exists()
            || AdClick::where('ad_placement_id', $this->getKey())->exists();
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(SponsorCampaign::class, 'campaign_ad_placement');
    }
}
