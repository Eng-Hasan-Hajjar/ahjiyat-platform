<?php

namespace App\Models;

use App\Services\Competitive\Rewards\RewardRuleValidator;
use App\Services\OperationalAuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قاعدة جائزة حدث (E18-A). الحارس هنا (لا بالواجهة) يفرض: القفل بعد البدء، سلامة النطاق وعدم التداخل، وصلاحية الجائزة من الكتالوج. كل تغيير
 * (إنشاء/تعديل/حذف) يُدقَّق بـOperationalAuditService. لا حقل يحمل مضاعفًا أو نقدًا أو منطقًا: Structured فقط.
 */
class CompetitiveRewardRule extends Model
{
    public const KIND_RANK = 'rank';

    public const KIND_PARTICIPATION = 'participation';

    public const TYPE_CURRENCY = 'currency';

    public const TYPE_XP = 'xp';

    public const TYPE_STORE_ITEM = 'store_item';

    protected $fillable = ['competitive_event_id', 'kind', 'min_rank', 'max_rank', 'reward_type', 'currency_id', 'store_item_id', 'amount', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_rank' => 'integer', 'max_rank' => 'integer', 'amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (CompetitiveRewardRule $rule) {
            app(RewardRuleValidator::class)->validate($rule);
            $rule->participation_event_id = $rule->kind === self::KIND_PARTICIPATION ? $rule->competitive_event_id : null;
        });

        static::deleting(function (CompetitiveRewardRule $rule) {
            app(RewardRuleValidator::class)->assertEditable($rule->event()->firstOrFail());
        });

        // أحداث صريحة (wasRecentlyCreated تبقى true على الكائن نفسه بعد أي update لاحق)
        static::created(fn (CompetitiveRewardRule $rule) => static::audit('competitive_reward_rule_created', $rule));
        static::updated(fn (CompetitiveRewardRule $rule) => static::audit('competitive_reward_rule_updated', $rule));
        static::deleted(fn (CompetitiveRewardRule $rule) => static::audit('competitive_reward_rule_deleted', $rule));
    }

    protected static function audit(string $action, CompetitiveRewardRule $rule): void
    {
        app(OperationalAuditService::class)->log($action, $rule, [
            'event_id' => $rule->competitive_event_id, 'kind' => $rule->kind, 'min_rank' => $rule->min_rank, 'max_rank' => $rule->max_rank,
            'reward_type' => $rule->reward_type, 'amount' => $rule->amount, 'is_active' => $rule->is_active,
        ]);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompetitiveEvent::class, 'competitive_event_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    public function appliesTo(int $rank): bool
    {
        return $this->kind === self::KIND_RANK && $rank >= $this->min_rank && $rank <= $this->max_rank;
    }

    /** "المركز 1" / "المراكز 2–3" / "المشاركة" */
    public function placementLabel(): string
    {
        return match (true) {
            $this->kind === self::KIND_PARTICIPATION => 'المشاركة',
            $this->min_rank === $this->max_rank => "المركز {$this->min_rank}",
            default => "المراكز {$this->min_rank}–{$this->max_rank}",
        };
    }

    /** وصف الجائزة للعرض واللقطة: بلا معرّفات داخلية. */
    public function rewardLabel(): string
    {
        return match ($this->reward_type) {
            self::TYPE_CURRENCY => $this->amount.' '.($this->currency?->name ?? 'عملة'),
            self::TYPE_XP => $this->amount.' نقطة خبرة',
            default => ($this->storeItem?->name ?? 'عنصر').($this->amount > 1 ? " × {$this->amount}" : ''),
        };
    }
}
