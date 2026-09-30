<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\LevelDefinition;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Database\Seeder;

class ProgressionSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLevels();
        $this->seedAchievements();
    }

    protected function seedLevels(): void
    {
        $levels = [
            [1, 'المبتدئ', 0],
            [2, 'المتحمِّس', 30],
            [3, 'الحلّال الناشئ', 80],
            [4, 'صائد الألغاز', 150],
            [5, 'الخبير الصغير', 250],
            [6, 'المتمرِّس', 400],
            [7, 'سيد الأحجيات', 600],
            [8, 'الاستراتيجي', 850],
            [9, 'العقل اللامع', 1150],
            [10, 'الأسطورة', 1500],
            [11, 'بطل التحدي', 1900],
            [12, 'خبير النخبة', 2350],
            [13, 'الحكيم', 2850],
            [14, 'الأسطورة العظمى', 3400],
            [15, 'الماهر الأعظم', 4000],
        ];

        foreach ($levels as [$number, $name, $threshold]) {
            LevelDefinition::firstOrCreate(
                ['level_number' => $number],
                ['name' => $name, 'xp_required_total' => $threshold, 'is_active' => true, 'sort_order' => $number],
            );
        }
    }

    protected function seedAchievements(): void
    {
        $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();

        $achievements = [
            [
                'internal_key' => 'first_puzzle',
                'name' => 'أول خطوة',
                'description' => 'حللت أول أحجية لك على المنصة.',
                'category' => Achievement::CATEGORY_PUZZLES,
                'condition_type' => 'puzzles_solved_total',
                'target_value' => 1,
                'xp_reward' => 20,
                'reward_currency_amount' => 5,
            ],
            [
                'internal_key' => 'puzzles_5',
                'name' => 'بداية الطريق',
                'description' => 'حللت 5 أحجيات مختلفة.',
                'category' => Achievement::CATEGORY_PUZZLES,
                'condition_type' => 'puzzles_solved_total',
                'target_value' => 5,
                'xp_reward' => 40,
                'reward_currency_amount' => 10,
            ],
            [
                'internal_key' => 'puzzles_10',
                'name' => 'مستكشف الألغاز',
                'description' => 'حللت 10 أحجيات مختلفة.',
                'category' => Achievement::CATEGORY_PUZZLES,
                'condition_type' => 'puzzles_solved_total',
                'target_value' => 10,
                'xp_reward' => 75,
                'reward_currency_amount' => 20,
            ],
        ];

        foreach ($achievements as $data) {
            Achievement::firstOrCreate(
                ['internal_key' => $data['internal_key']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'category' => $data['category'],
                    'condition_type' => $data['condition_type'],
                    'target_value' => $data['target_value'],
                    'xp_reward' => $data['xp_reward'],
                    'reward_currency_id' => $currency->id,
                    'reward_currency_amount' => $data['reward_currency_amount'],
                    'is_active' => true,
                    'is_hidden' => false,
                ],
            );
        }
    }
}