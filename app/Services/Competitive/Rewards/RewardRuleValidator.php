<?php

namespace App\Services\Competitive\Rewards;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\StoreItem;

/**
 * مصحِّح قواعد الجوائز (E18-A): يُستدعى من حارس النموذج، فيسري على أي مسار (Filament، tinker، خدمة). Structured فقط: لا تعبير ولا منطق ولا نص حر.
 * - مقفلة بعد بدء الحدث (قبل البدء فقط، فلا "جائزة كبرى" بعد معرفة الفائز).
 * - نطاق المركز صالح، **ولا تداخل** بين أي نطاقين (غموض مرفوض لا يُحسَم بالأولوية).
 * - مشاركة: قاعدة واحدة، بلا مراكز.
 * - الجائزة تشير لتعريف حقيقي صالح من الكتالوج: عملة قابلة للكسب، أو عنصر متجر بتنفيذ inventory/entitlement (اليدوي مرفوض: لا تسليم تلقائي له)،
 *   أو XP؛ بمبلغ ضمن الحدود. لا مضاعف مشترى، ولا نقد، ولا رهان: لا يوجد أي حقل لذلك.
 */
class RewardRuleValidator
{
    public function assertEditable(CompetitiveEvent $event): void
    {
        if (! $event->rewardsEditable()) {
            throw new CompetitiveRewardRuleException('قواعد الجوائز مقفلة: لا تُعدَّل بعد بدء المنافسة أو اعتماد نتائجها.');
        }
    }

    public function validate(CompetitiveRewardRule $rule): void
    {
        $event = CompetitiveEvent::query()->find($rule->competitive_event_id)
            ?? throw new CompetitiveRewardRuleException('المنافسة غير موجودة.');

        $this->assertEditable($event);
        $this->validatePlacement($rule);
        $this->validateReward($rule);
    }

    protected function validatePlacement(CompetitiveRewardRule $rule): void
    {
        if (! in_array($rule->kind, [CompetitiveRewardRule::KIND_RANK, CompetitiveRewardRule::KIND_PARTICIPATION], true)) {
            throw new CompetitiveRewardRuleException('نوع القاعدة غير صالح.');
        }

        $others = CompetitiveRewardRule::query()->where('competitive_event_id', $rule->competitive_event_id)
            ->when($rule->exists, fn ($q) => $q->whereKeyNot($rule->getKey()))->get();

        if ($rule->kind === CompetitiveRewardRule::KIND_PARTICIPATION) {
            if ($rule->min_rank !== null || $rule->max_rank !== null) {
                throw new CompetitiveRewardRuleException('قاعدة المشاركة لا تحمل مراكز.');
            }

            if ($others->contains('kind', CompetitiveRewardRule::KIND_PARTICIPATION)) {
                throw new CompetitiveRewardRuleException('لهذه المنافسة قاعدة مشاركة بالفعل.');
            }

            return;
        }

        $max = (int) config('competitive.rewards.max_rank', 100);

        if ($rule->min_rank === null || $rule->max_rank === null || $rule->min_rank < 1) {
            throw new CompetitiveRewardRuleException('حدّد نطاق المراكز (من 1 فأكثر).');
        }

        if ($rule->min_rank > $rule->max_rank) {
            throw new CompetitiveRewardRuleException('أدنى مركز يجب ألا يتجاوز أعلى مركز.');
        }

        if ($rule->max_rank > $max) {
            throw new CompetitiveRewardRuleException("أعلى مركز مسموح {$max}.");
        }

        foreach ($others->where('kind', CompetitiveRewardRule::KIND_RANK) as $other) {
            if ($rule->min_rank <= $other->max_rank && $other->min_rank <= $rule->max_rank) {
                throw new CompetitiveRewardRuleException("نطاق المراكز {$rule->min_rank}–{$rule->max_rank} يتداخل مع قاعدة موجودة ({$other->min_rank}–{$other->max_rank}).");
            }
        }
    }

    protected function validateReward(CompetitiveRewardRule $rule): void
    {
        $amount = (int) $rule->amount;
        $limits = config('competitive.rewards');

        switch ($rule->reward_type) {
            case CompetitiveRewardRule::TYPE_CURRENCY:
                if ($rule->store_item_id !== null) {
                    throw new CompetitiveRewardRuleException('جائزة العملة لا تحمل عنصر متجر.');
                }

                $currency = $rule->currency_id === null ? null : Currency::query()->find($rule->currency_id);

                if ($currency === null || ! $currency->canEarn()) {
                    throw new CompetitiveRewardRuleException('العملة غير موجودة أو غير قابلة للكسب حاليًا.');
                }

                $this->assertBetween($amount, (int) $limits['max_currency_amount'], 'مبلغ العملة');
                break;

            case CompetitiveRewardRule::TYPE_XP:
                if ($rule->currency_id !== null || $rule->store_item_id !== null) {
                    throw new CompetitiveRewardRuleException('جائزة الخبرة لا تحمل عملة ولا عنصرًا.');
                }

                $this->assertBetween($amount, (int) $limits['max_xp_amount'], 'نقاط الخبرة');
                break;

            case CompetitiveRewardRule::TYPE_STORE_ITEM:
                if ($rule->currency_id !== null) {
                    throw new CompetitiveRewardRuleException('جائزة العنصر لا تحمل عملة.');
                }

                $item = $rule->store_item_id === null ? null : StoreItem::query()->find($rule->store_item_id);

                if ($item === null) {
                    throw new CompetitiveRewardRuleException('عنصر المتجر غير موجود.');
                }

                if (! in_array($item->fulfillment_type, [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT], true)) {
                    throw new CompetitiveRewardRuleException('العناصر يدوية التسليم لا تصلح جائزة تلقائية.');
                }

                if ($item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT && blank($item->entitlement_key)) {
                    throw new CompetitiveRewardRuleException('عنصر الامتياز بلا مفتاح امتياز.');
                }

                $this->assertBetween($amount, $item->fulfillment_type === StoreItem::FULFILLMENT_ENTITLEMENT ? 1 : (int) $limits['max_item_quantity'], 'الكمية');
                break;

            default:
                throw new CompetitiveRewardRuleException('نوع الجائزة غير مدعوم.');
        }
    }

    protected function assertBetween(int $value, int $max, string $label): void
    {
        if ($value < 1 || $value > $max) {
            throw new CompetitiveRewardRuleException("{$label} يجب أن يكون بين 1 و{$max}.");
        }
    }
}
