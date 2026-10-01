<?php

namespace Database\Seeders;

use App\Models\QuestDefinition;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Engagement\QuestEvaluatorRegistry;
use Illuminate\Database\Seeder;

/**
 * E13 (بند 417-426): القيم أدناه مبنية على تدقيق محتوى فعلي - 51 أحجية
 * مزروعة عبر 5 تصنيفات (~10 لكل تصنيف) وقت كتابة هذا البذر. البذر الافتراضي
 * يستخدم puzzles_solved العام (بلا تصنيف/حملة) تحفُّظًا، قابل للكسب مجانًا
 * بالكامل من أي محتوى حر، بعملة الكسب الافتراضية فقط - لا Premium إطلاقًا.
 */
class EngagementSeeder extends Seeder
{
    public function run(): void
    {
        $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

        $quests = [
            [
                'internal_key' => 'daily_solve_1',
                'name' => 'أول خطوة اليوم',
                'description' => 'حُلَّ أحجية واحدة صحيحة اليوم.',
                'period_type' => QuestDefinition::PERIOD_DAILY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
                'target_value' => 1,
                'xp_reward' => 10,
                'reward_currency_amount' => 5,
                'sort_order' => 1,
            ],
            [
                'internal_key' => 'daily_solve_2',
                'name' => 'استمرار اليوم',
                'description' => 'حُلَّ أحجيتين صحيحتين اليوم.',
                'period_type' => QuestDefinition::PERIOD_DAILY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
                'target_value' => 2,
                'xp_reward' => 20,
                'reward_currency_amount' => 10,
                'sort_order' => 2,
            ],
            [
                'internal_key' => 'daily_solve_3',
                'name' => 'يوم مثمر',
                'description' => 'حُلَّ 3 أحجيات صحيحة اليوم.',
                'period_type' => QuestDefinition::PERIOD_DAILY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
                'target_value' => 3,
                'xp_reward' => 35,
                'reward_currency_amount' => 15,
                'sort_order' => 3,
            ],
            [
                'internal_key' => 'weekly_solve_10',
                'name' => 'أسبوع نشيط',
                'description' => 'حُلَّ 10 أحجيات صحيحة هذا الأسبوع.',
                'period_type' => QuestDefinition::PERIOD_WEEKLY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
                'target_value' => 10,
                'xp_reward' => 100,
                'reward_currency_amount' => 50,
                'sort_order' => 1,
            ],
            [
                'internal_key' => 'weekly_solve_20',
                'name' => 'أسبوع الإنجاز',
                'description' => 'حُلَّ 20 أحجية صحيحة هذا الأسبوع.',
                'period_type' => QuestDefinition::PERIOD_WEEKLY,
                'condition_type' => QuestEvaluatorRegistry::PUZZLES_SOLVED,
                'target_value' => 20,
                'xp_reward' => 200,
                'reward_currency_amount' => 100,
                'sort_order' => 2,
            ],
        ];

        foreach ($quests as $data) {
            QuestDefinition::firstOrCreate(
                ['internal_key' => $data['internal_key']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'period_type' => $data['period_type'],
                    'condition_type' => $data['condition_type'],
                    'target_value' => $data['target_value'],
                    'xp_reward' => $data['xp_reward'],
                    'reward_currency_id' => $currency->id,
                    'reward_currency_amount' => $data['reward_currency_amount'],
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ],
            );
        }
    }
}
