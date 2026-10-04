<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E14 (بند 24-27): حقول مُهيكَلة فقط - صفر HTML/JS/iframe خام بأي حقل. */
class SponsorCreative extends Model
{
    use HasFactory;

    protected $fillable = [
        'sponsor_campaign_id', 'title', 'body', 'cta_label',
        'destination_url', 'image_path', 'alt_text', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (SponsorCreative $c) => \App\Services\Advertising\AdInvariantGuard::creativeCreating($c));
        static::updating(fn (SponsorCreative $c) => \App\Services\Advertising\AdInvariantGuard::creativeUpdating($c));
        static::deleting(fn (SponsorCreative $c) => \App\Services\Advertising\AdInvariantGuard::creativeDeleting($c));
    }

    /** لها Impression أو Click سابق؟ (سجل تحليلات لا يُحذَف أبدًا). */
    public function hasHistory(): bool
    {
        return AdImpression::where('sponsor_creative_id', $this->getKey())->exists()
            || AdClick::where('sponsor_creative_id', $this->getKey())->exists();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SponsorCampaign::class, 'sponsor_campaign_id');
    }
}
