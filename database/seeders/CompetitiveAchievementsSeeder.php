<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Services\Progression\AchievementEvaluatorRegistry;
use Illuminate\Database\Seeder;

/**
 * إنجازات المنافسة الافتراضية (E18-C8): اختيارية، تُشغَّل يدويًا (`db:seed --class=CompetitiveAchievementsSeeder`) وآمنة لإعادة التشغيل (updateOrCreate
 * بالمفتاح الداخلي). **بلا مكافآت** (xp_reward = 0): المدير يضبط المكافأة والعتبة من لوحة الإنجازات كأي إنجاز، فلا اقتصاد مفروض هنا.
 */
class CompetitiveAchievementsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['competitive_first_win', 'أول فوز', 'فُزت بالمركز الأول في منافسة رسمية.', AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_WON, 1],
            ['competitive_three_wins', 'ثلاثة انتصارات', 'فُزت بثلاث منافسات رسمية.', AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_WON, 3],
            ['competitive_ten_wins', 'بطل المنافسات', 'فُزت بعشر منافسات رسمية.', AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_WON, 10],
            ['competitive_first_top3', 'أول منصة تتويج', 'أنهيت منافسة رسمية ضمن المراكز الثلاثة الأولى.', AchievementEvaluatorRegistry::COMPETITIVE_TOP3_FINISHES, 1],
            ['competitive_five_completed', 'منافس منتظم', 'أكملت خمس منافسات رسمية بنتيجة صحيحة.', AchievementEvaluatorRegistry::COMPETITIVE_EVENTS_COMPLETED, 5],
        ];

        foreach ($rows as $i => [$key, $name, $description, $type, $target]) {
            Achievement::query()->updateOrCreate(['internal_key' => $key], [
                'name' => $name, 'description' => $description, 'category' => Achievement::CATEGORY_COMPETITIVE,
                'condition_type' => $type, 'target_value' => $target, 'xp_reward' => 0, 'is_active' => true, 'is_hidden' => false, 'sort_order' => 100 + $i,
            ]);
        }
    }
}
