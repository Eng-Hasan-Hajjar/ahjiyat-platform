<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\Puzzle;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $puzzles = Puzzle::inRandomOrder()->get();

        if ($puzzles->count() < 8) {
            $this->command->warn('عدد الأحجيات قليل - شغّل PuzzleSeeder أولاً للحصول على تحديات كاملة.');

            return;
        }

        $challenges = [
            [
                'title' => 'تحدي الأسبوع: العقل السريع',
                'description' => 'خمس أحجيات متنوعة، من يحلّها أسرع وأدق يتصدّر لوحة هذا التحدي ويحصل على نصيبه من مجموعة الجواهر.',
                'type' => 'weekly',
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(5),
                'bonus_gem_pool' => 500,
                'is_active' => true,
                'puzzle_count' => 5,
            ],
            [
                'title' => 'بطولة أحجيات الشهر',
                'description' => 'البطولة الكبرى لهذا الشهر - أحجيات أصعب ومجموعة جواهر أكبر، لأقوى الحلّالين فقط.',
                'type' => 'tournament',
                'starts_at' => now()->subDays(10),
                'ends_at' => now()->addDays(20),
                'bonus_gem_pool' => 2000,
                'is_active' => true,
                'puzzle_count' => 8,
            ],
            [
                'title' => 'تحدي الرياضيات السريع',
                'description' => 'تحدٍ قادم مخصص لعشّاق الأرقام والمتتاليات. جهّز نفسك!',
                'type' => 'weekly',
                'starts_at' => now()->addDays(3),
                'ends_at' => now()->addDays(10),
                'bonus_gem_pool' => 300,
                'is_active' => true,
                'puzzle_count' => 5,
            ],
            [
                'title' => 'تحدي الافتتاح',
                'description' => 'أول تحدٍ على المنصة، انتهى بمنافسة قوية بين المشاركين.',
                'type' => 'weekly',
                'starts_at' => now()->subDays(20),
                'ends_at' => now()->subDays(13),
                'bonus_gem_pool' => 400,
                'is_active' => true,
                'puzzle_count' => 6,
            ],
            [
                'title' => 'بطولة الإطلاق التجريبي',
                'description' => 'بطولة تجريبية أقيمت قبل الإطلاق الرسمي لاختبار النظام.',
                'type' => 'tournament',
                'starts_at' => now()->subDays(45),
                'ends_at' => now()->subDays(25),
                'bonus_gem_pool' => 1000,
                'is_active' => true,
                'puzzle_count' => 6,
            ],
        ];

        foreach ($challenges as $data) {
            $puzzleCount = $data['puzzle_count'];
            unset($data['puzzle_count']);

            $challenge = Challenge::firstOrCreate(['title' => $data['title']], $data);

            if ($challenge->puzzles()->count() === 0) {
                $challenge->puzzles()->attach(
                    $puzzles->random(min($puzzleCount, $puzzles->count()))->pluck('id')
                );
            }
        }

        $this->command->info('تم إنشاء '.count($challenges).' تحديات (مفتوحة، قادمة، ومنتهية) مع أحجياتها.');
    }
}