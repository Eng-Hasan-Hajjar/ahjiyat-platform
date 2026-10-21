<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Services\LiveOps\StreakMilestoneService;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Illuminate\Database\Seeder;

/**
 * معالم السلسلة اليومية (E34): اختيارية، تُشغَّل يدويًا (`db:seed --class=StreakMilestoneAchievementsSeeder`) وآمنة لإعادة التشغيل
 * (updateOrCreate بالمفتاح الداخلي). **بلا مكافآت** (xp_reward = 0): تقدير فقط؛ يضبط المدير أي مكافأة صغيرة من لوحة الإنجازات.
 * لا يُعدّل معلمًا صار مستعملًا: الحارس يمنع تغيير الحقول الحساسة، فنتجاوز الصفوف المستعملة بدل رمي استثناء.
 */
class StreakMilestoneAchievementsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (app(StreakMilestoneService::class)->thresholds() as $i => $days) {
            $key = "streak_{$days}_days";
            $existing = Achievement::query()->where('internal_key', $key)->first();

            if ($existing !== null && $existing->isUsed()) {
                continue;
            }

            Achievement::query()->updateOrCreate(['internal_key' => $key], [
                'name' => "سلسلة {$days} يومًا",
                'description' => "لعبت أحجية رسمية صحيحة {$days} أيام متتالية.",
                'category' => Achievement::CATEGORY_GENERAL,
                'condition_type' => AchievementEvaluatorRegistry::STREAK_LONGEST_DAYS,
                'target_value' => $days,
                'xp_reward' => 0,
                'is_active' => true,
                'is_hidden' => false,
                'sort_order' => 200 + $i,
            ]);
        }
    }
}
