<?php

namespace Database\Seeders\Demo;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;

/**
 * أحجيات الديمو بإجابات **معلومة** (إجابات الأحجيات الأصلية تُخزَّن مُجزَّأة ولا تُسترجع). مجموعتان بلا تلميحات: "س" للّعب العادي والتاريخ، و"م" للمنافسات والتحدّيات (صالحة للمنافسة).
 * إنشاء بالعنوان (firstOrCreate): Idempotent. لا تغيير لأحجية موجودة.
 */
class DemoPuzzleCatalog
{
    public const CATEGORY_SLUG = 'demo-qa';

    /** key => [prompt, answer, difficulty, gem_reward] */
    public const SOLVE = [
        'S01' => ['ما ناتج 7 + 5؟', '12', 'easy', 5], 'S02' => ['كم يومًا في الأسبوع؟', '7', 'easy', 5], 'S03' => ['ما عاصمة سوريا؟', 'دمشق', 'easy', 5],
        'S04' => ['15 × 4 = ؟', '60', 'medium', 10], 'S05' => ['ما الجذر التربيعي لـ 81؟', '9', 'medium', 10], 'S06' => ['ما أكبر كوكب في المجموعة الشمسية؟', 'المشتري', 'medium', 10],
        'S07' => ['كم عدد حروف الأبجدية العربية؟', '28', 'medium', 10], 'S08' => ['ما ناتج 144 ÷ 12؟', '12', 'medium', 10], 'S09' => ['ما عاصمة مصر؟', 'القاهرة', 'easy', 5],
        'S10' => ['ما الشيء الذي له أسنان ولا يعضّ؟', 'المشط', 'hard', 20], 'S11' => ['ما مجموع زوايا المربع؟', '360', 'hard', 20], 'S12' => ['ما أصغر عدد أولي؟', '2', 'medium', 10],
        'S13' => ['ما لون الزمرد؟', 'أخضر', 'easy', 5], 'S14' => ['كم دقيقة في ساعتين؟', '120', 'medium', 10], 'S15' => ['ما عاصمة تركيا؟', 'أنقرة', 'easy', 5],
        'S16' => ['2 أس 6 = ؟', '64', 'hard', 20], 'S17' => ['ما أطول نهر في العالم؟', 'النيل', 'hard', 20], 'S18' => ['كم ضلعًا للمسدس؟', '6', 'easy', 5],
        'S19' => ['ما ناتج 99 + 1؟', '100', 'easy', 5], 'S20' => ['كم يومًا في السنة الكبيسة؟', '366', 'medium', 10],
    ];

    public const COMPETE = [
        'C01' => ['ما ناتج 8 × 7؟', '56'], 'C02' => ['ما عاصمة لبنان؟', 'بيروت'], 'C03' => ['كم ثانية في الدقيقة؟', '60'], 'C04' => ['ما ناتج 13 + 29؟', '42'],
        'C05' => ['ما عاصمة الأردن؟', 'عمان'], 'C06' => ['كم يومًا في شهر شباط بالسنة العادية؟', '28'], 'C07' => ['ما ناتج 1000 ÷ 8؟', '125'], 'C08' => ['ما أكبر محيط في العالم؟', 'الهادئ'],
        'C09' => ['كم ضلعًا للمثمن؟', '8'], 'C10' => ['ما ناتج 25 × 25؟', '625'], 'C11' => ['ما عاصمة العراق؟', 'بغداد'], 'C12' => ['ما ناتج 3 أس 4؟', '81'],
        'C13' => ['ما ناتج 9 × 9؟', '81'], 'C14' => ['ما عاصمة ليبيا؟', 'طرابلس'], 'C15' => ['ما ناتج 17 + 18؟', '35'], 'C16' => ['كم ضلعًا للمخمس؟', '5'],
    ];

    /** @var array<string, Puzzle>|null */
    protected static ?array $cache = null;

    public static function ensure(): array
    {
        $category = PuzzleCategory::query()->firstOrCreate(['slug' => self::CATEGORY_SLUG], ['name' => 'ديمو - بيانات اختبار', 'is_active' => true, 'sort_order' => 990]);
        $out = [];

        foreach (self::SOLVE as $key => [$prompt, $answer, $difficulty, $gems]) {
            $out[$key] = Puzzle::query()->firstOrCreate(['title' => "ديمو س {$key}"], [
                'puzzle_category_id' => $category->id, 'type' => 'text', 'difficulty' => $difficulty, 'prompt' => $prompt, 'answer_raw' => $answer,
                'max_attempts' => 3, 'gem_reward' => $gems, 'is_active' => true,
            ]);
        }

        foreach (self::COMPETE as $key => [$prompt, $answer]) {
            $out[$key] = Puzzle::query()->firstOrCreate(['title' => "ديمو منافسة {$key}"], [
                'puzzle_category_id' => $category->id, 'type' => 'text', 'difficulty' => 'medium', 'prompt' => $prompt, 'answer_raw' => $answer,
                'max_attempts' => 3, 'gem_reward' => 10, 'is_active' => true,
            ]);
        }

        return self::$cache = $out;
    }

    public static function puzzle(string $key): Puzzle
    {
        return (self::$cache ?? self::ensure())[$key];
    }

    public static function answer(string $key): string
    {
        return (self::SOLVE[$key] ?? self::COMPETE[$key])[1];
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
