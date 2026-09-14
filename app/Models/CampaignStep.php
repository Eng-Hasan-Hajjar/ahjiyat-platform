<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kind مدعومة فعلياً اليوم فقط: narrative و puzzle (ثابتة أدناه). لا Registry
 * ولا Contract الآن - عندما يظهر Kind ثالث فعلي (reflection/external_event)
 * تُعاد دراسة الحاجة لتجريد أوسع (نفس درس Phase A مع Sequence/Memory).
 */
class CampaignStep extends Model
{
    use HasFactory;

    public const KIND_NARRATIVE = 'narrative';

    public const KIND_PUZZLE = 'puzzle';

    public const REWARD_MODE_INHERIT = 'inherit';

    public const REWARD_MODE_OVERRIDE = 'override';

    public const REWARD_MODE_NONE = 'none';

    protected $fillable = [
        'campaign_gate_id', 'kind', 'sort_order', 'title', 'subtitle',
        'content', 'puzzle_id', 'reward_mode', 'reward_override_amount',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(CampaignGate::class, 'campaign_gate_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserCampaignProgress::class, 'campaign_step_id');
    }
}