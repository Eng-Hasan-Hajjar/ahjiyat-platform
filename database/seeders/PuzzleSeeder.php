<?php

namespace Database\Seeders;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use Illuminate\Database\Seeder;

class PuzzleSeeder extends Seeder
{
    public function run(): void
    {
        $logic = PuzzleCategory::where('slug', 'logic')->first();
        $math = PuzzleCategory::where('slug', 'math')->first();
        $language = PuzzleCategory::where('slug', 'language')->first();

        $samples = [
            [
                'puzzle_category_id' => $logic->id,
                'title' => 'الأخوة الثلاثة',
                'type' => 'text',
                'difficulty' => 'easy',
                'prompt' => 'لدى أحمد ثلاثة إخوة، وكل أخ لديه أخت واحدة. كم عدد الأخوات في العائلة؟',
                'answer_raw' => 'واحدة',
                'hint' => 'فكر: هل كل أخ يتكلم عن أخت مختلفة، أم نفس الأخت؟',
                'gem_reward' => 5,
            ],
            [
                'puzzle_category_id' => $math->id,
                'title' => 'المتتالية الناقصة',
                'type' => 'text',
                'difficulty' => 'medium',
                'prompt' => 'أكمل المتتالية: 2، 6، 12، 20، 30، ...؟',
                'answer_raw' => '42',
                'hint' => 'الفرق بين كل رقمين متتاليين يزيد بمقدار 2 كل مرة.',
                'gem_reward' => 10,
            ],
            [
                'puzzle_category_id' => $language->id,
                'title' => 'الحرف المحذوف',
                'type' => 'multiple_choice',
                'difficulty' => 'hard',
                'prompt' => 'أي حرف عربي لا يقبل الوصل بالحرف الذي يليه أبداً من هذه الخيارات؟',
                'choices' => ['الباء', 'الدال', 'السين', 'الكاف'],
                'answer_raw' => 'الدال',
                'hint' => 'من الحروف التي "تكتب منفصلة دائماً" رغم اتصالها بما قبلها.',
                'gem_reward' => 20,
            ],
        ];

        foreach ($samples as $sample) {
            Puzzle::firstOrCreate(['title' => $sample['title']], $sample);
        }

        $this->command->info('تمت إضافة أحجيات تجريبية - استبدلها بمحتوى حقيقي قبل الإطلاق.');
    }
}
