<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Services\Sponsorship\SponsorshipMoney;

/**
 * E40 - مدقّق جاهزية النشر (قراءة فقط): يُرجع قائمة مشكلات عربية؛ فارغة = جاهز. يُستعمل عند الجدولة وفي معاينة البنّاء.
 * يفحص: النافذة، الشرائح (تسلسل وتصاعد صارم)، المهام (تعريفات صالحة ونقاط وسقوف)، المكافآت (عناصر صالحة)، المميز (سعر/عملة/نافذة بيع)، عدم التداخل مع مسار منشور آخر،
 * وإمكانية بلوغ آخر شريحة (مجموع ما يمكن كسبه ≥ عتبتها).
 */
class PassReadinessChecker
{
    /** @return list<string> */
    public function problems(AhjiyatPass $pass): array
    {
        $problems = [];
        $limits = (array) config('ahjiyat_pass.limits');
        $life = (array) config('ahjiyat_pass.lifecycle');

        if (trim((string) $pass->name) === '') {
            $problems[] = 'اسم المسار مطلوب.';
        }

        // النافذة
        if ($pass->starts_at === null || $pass->ends_at === null || $pass->ends_at->lte($pass->starts_at)) {
            $problems[] = 'نافذة المسار غير صالحة: يجب أن ينتهي بعد بدايته.';
        } else {
            $days = (int) ceil($pass->starts_at->diffInSeconds($pass->ends_at) / 86400);

            if ($days < (int) ($life['min_duration_days'] ?? 7) || $days > (int) ($life['max_duration_days'] ?? 120)) {
                $problems[] = 'مدة المسار خارج الحدود المسموحة ('.(int) ($life['min_duration_days'] ?? 7).'–'.(int) ($life['max_duration_days'] ?? 120).' يومًا).';
            }

            if ($pass->ends_at->lte(now())) {
                $problems[] = 'لا يمكن نشر مسار تاريخ نهايته مضى.';
            }
        }

        if ($pass->claim_grace_days < 0 || $pass->claim_grace_days > (int) ($life['claim_grace_max_days'] ?? 14)) {
            $problems[] = 'مهلة الاستلام خارج الحدود المسموحة.';
        }

        // الشرائح
        $tiers = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('tier_number')->get();

        if ($tiers->isEmpty()) {
            $problems[] = 'أضف شرائح للمسار.';
        } else {
            if ($tiers->count() > (int) ($limits['max_tiers'] ?? 100)) {
                $problems[] = 'عدد الشرائح يتجاوز الحدّ.';
            }

            $previous = null;

            foreach ($tiers->values() as $index => $tier) {
                if ((int) $tier->tier_number !== $index + 1) {
                    $problems[] = 'ترقيم الشرائح يجب أن يكون متسلسلًا من 1 بلا فجوات.';
                    break;
                }

                if ($previous !== null && (int) $tier->required_points <= $previous) {
                    $problems[] = 'عتبات الشرائح يجب أن تكون تصاعدية صارمة (الشريحة '.$tier->tier_number.').';
                    break;
                }

                $previous = (int) $tier->required_points;
            }
        }

        // المهام
        $missions = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $pass->getKey())->with('quest')->get();
        $earnable = 0;

        if ($missions->isEmpty()) {
            $problems[] = 'أضف مهمة واحدة على الأقل لمنح نقاط المسار.';
        } else {
            if ($missions->count() > (int) ($limits['max_missions'] ?? 40)) {
                $problems[] = 'عدد المهام يتجاوز الحدّ.';
            }

            foreach ($missions as $mission) {
                if ($mission->quest === null) {
                    $problems[] = 'مهمة مرتبطة بتعريف غير موجود.';

                    continue;
                }

                if (($problem = PassRules::questProblem($mission->quest, $pass)) !== null) {
                    $problems[] = $problem[1];
                }

                if ($mission->pass_points < 1 || $mission->pass_points > (int) ($limits['max_points_per_mission'] ?? 1000)) {
                    $problems[] = 'نقاط المهمة «'.$mission->quest->name.'» خارج الحدود المسموحة.';
                }

                if ($mission->max_completions < 1 || $mission->max_completions > (int) ($limits['max_completions_cap'] ?? 14)) {
                    $problems[] = 'سقف إكمالات المهمة «'.$mission->quest->name.'» خارج الحدود المسموحة.';
                }

                $earnable += (int) $mission->pass_points * (int) $mission->max_completions;
            }
        }

        if ($tiers->isNotEmpty() && $missions->isNotEmpty() && $earnable < (int) $tiers->last()->required_points) {
            $problems[] = 'مجموع النقاط الممكن كسبه ('.$earnable.') أقل من عتبة الشريحة الأخيرة ('.$tiers->last()->required_points.')؛ لن يبلغها أحد.';
        }

        // المكافآت
        $rewards = AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $pass->getKey())->with('item')->get();

        if ($rewards->isEmpty()) {
            $problems[] = 'أضف مكافأة واحدة على الأقل.';
        }

        foreach ($rewards as $reward) {
            if ($reward->item === null) {
                $problems[] = 'مكافأة مرتبطة بعنصر غير موجود.';

                continue;
            }

            if (($problem = PassRules::itemProblem($reward->item)) !== null) {
                $problems[] = $problem[1];
            }

            if ($reward->quantity < 1 || $reward->quantity > (int) ($limits['max_reward_quantity'] ?? 5)) {
                $problems[] = 'كمية المكافأة «'.$reward->item->name.'» خارج الحدود المسموحة.';
            }
        }

        // المميز
        if ($pass->premium_enabled) {
            if (($problem = PassRules::priceProblem($pass->premium_price_minor, $pass->currency)) !== null) {
                $problems[] = $problem[1];
            }

            if (! $rewards->contains(fn (AhjiyatPassTierReward $r) => $r->track === AhjiyatPassTierReward::TRACK_PREMIUM)) {
                $problems[] = 'المسار المميز مفعّل لكن بلا أي مكافأة مميزة.';
            }
        }

        if ($pass->premium_sales_start_at !== null && $pass->premium_sales_end_at !== null && $pass->premium_sales_end_at->lte($pass->premium_sales_start_at)) {
            $problems[] = 'نافذة بيع المميز غير صالحة.';
        }

        if ($pass->premium_sales_start_at !== null && $pass->starts_at !== null && $pass->premium_sales_start_at->lt($pass->starts_at)) {
            $problems[] = 'لا يبدأ بيع المميز قبل بدء المسار.';
        }

        if ($pass->premium_sales_end_at !== null && $pass->ends_at !== null && $pass->premium_sales_end_at->gt($pass->ends_at)) {
            $problems[] = 'لا يستمر بيع المميز بعد انتهاء المسار.';
        }

        // عدم التداخل مع مسار منشور آخر
        if ($pass->starts_at !== null && $pass->ends_at !== null) {
            $overlap = AhjiyatPass::query()
                ->whereNotNull('locked_at')
                ->whereIn('status', [AhjiyatPass::STATUS_SCHEDULED, AhjiyatPass::STATUS_ACTIVE])
                ->whereKeyNot($pass->getKey())
                ->where('starts_at', '<', $pass->ends_at)
                ->where('ends_at', '>', $pass->starts_at)
                ->first(['id', 'name']);

            if ($overlap !== null) {
                $problems[] = 'تتداخل نافذة هذا المسار مع المسار «'.$overlap->name.'»؛ مسار فعّال واحد فقط في كل وقت.';
            }
        }

        return array_values(array_unique($problems));
    }

    public function money(AhjiyatPass $pass): string
    {
        return $pass->premium_price_minor === null || blank($pass->currency) ? '—' : SponsorshipMoney::format((int) $pass->premium_price_minor, (string) $pass->currency);
    }
}
