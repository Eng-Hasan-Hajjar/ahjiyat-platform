<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignGateQualification extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_gate_id', 'user_id', 'rank', 'qualified_at',
    ];

    protected function casts(): array
    {
        return [
            'qualified_at' => 'datetime',
        ];
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(CampaignGate::class, 'campaign_gate_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}