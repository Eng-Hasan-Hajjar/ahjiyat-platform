<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignStep extends Model
{
    use HasFactory;

    public const KIND_NARRATIVE = 'narrative';
    public const KIND_PUZZLE = 'puzzle';
    public const KIND_REFLECTION = 'reflection';

    public const REWARD_MODE_INHERIT = 'inherit';
    public const REWARD_MODE_OVERRIDE = 'override';
    public const REWARD_MODE_NONE = 'none';

    public const CONTENT_STATUS_FINAL = 'final';
    public const CONTENT_STATUS_PLACEHOLDER = 'placeholder';
    public const CONTENT_STATUS_CONTENT_PENDING = 'content_pending';
    public const CONTENT_STATUS_TECHNICAL_PENDING = 'technical_pending';

    protected $fillable = [
        'campaign_gate_id', 'kind', 'sort_order', 'title', 'subtitle',
        'content', 'puzzle_id', 'reward_mode', 'reward_override_amount', 'reward_currency_id',
    ];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(CampaignGate::class, 'campaign_gate_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserCampaignProgress::class, 'campaign_step_id');
    }

    public function contentStatus(): string
    {
        return $this->content['status'] ?? self::CONTENT_STATUS_FINAL;
    }

    protected static function booted(): void
    {
        static::saving(function (CampaignStep $step) {
            if ($step->kind !== self::KIND_PUZZLE) {
                $step->puzzle_id = null;
            }

            if ($step->reward_mode !== self::REWARD_MODE_OVERRIDE) {
                $step->reward_override_amount = null;
                $step->reward_currency_id = null;
            }
        });
    }
}