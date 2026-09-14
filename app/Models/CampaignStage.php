<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'title', 'subtitle', 'sort_order',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(CampaignGate::class);
    }
}