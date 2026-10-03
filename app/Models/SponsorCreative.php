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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SponsorCampaign::class, 'sponsor_campaign_id');
    }
}
