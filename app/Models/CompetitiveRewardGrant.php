<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل توزيع جائزة (E18-B): لاعب واحد، حدث واحد، جائزة واحدة (UNIQUE بالقاعدة). ليس دفترًا اقتصاديًا: الأصل الممنوح مصدر حقيقته دفتر المحفظة/XP/المخزون.
 * الكتابة عبر CompetitiveRewardDistributionService فقط. لا تُعرض معرّفاته للاعب (خصوصية).
 */
class CompetitiveRewardGrant extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'competitive_event_id', 'user_id', 'competitive_event_result_id', 'competitive_reward_rule_id', 'status', 'final_rank',
        'reward_type', 'currency_id', 'store_item_id', 'amount', 'reward_label', 'attempts', 'failure_reason', 'granted_at',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CompetitiveRewardRule::class, 'competitive_reward_rule_id');
    }
}
