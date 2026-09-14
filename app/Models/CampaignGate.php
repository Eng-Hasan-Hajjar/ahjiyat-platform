<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignGate extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_stage_id', 'title', 'subtitle', 'narrative_intro',
        'sort_order', 'qualification_rule', 'qualification_config',
    ];

    protected function casts(): array
    {
        return [
            'qualification_config' => 'array',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CampaignStage::class, 'campaign_stage_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(CampaignGateQualification::class);
    }
}