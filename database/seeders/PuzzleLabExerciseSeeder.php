<?php

namespace Database\Seeders;

use App\Models\PuzzleLabExercise;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * E47 - أربعة تمارين أصلية لـ«مختبر الأحجيات» (تمرينان لكل نوع).   php artisan db:seed --class=PuzzleLabExerciseSeeder
 *
 * - صريح الاستدعاء لبيئة الاختبار: لا يُستدعى من DatabaseSeeder ولا من أي seeder آخر، ويرفض الإنتاج (كباقي seeders المحتوى التجريبي).
 * - نصوص أصلية بلا وسائط ولا ملفات ولا مصادر خارجية. لا حسابات ولا مستخدمين ولا أي بيانات اقتصادية.
 * - Idempotent وغير كاتب فوق المحتوى: التمرين الموجود بالـslug نفسه يُتخطّى كاملًا (حتى لو عدّله المحرّر أو أخفاه)؛ إعادة التشغيل لا تضاعف شيئًا.
 * - التمارين تُنشأ منشورة ليجرّبها المختبِر فورًا؛ يمرّ الحفظ بحارس النموذج (محتوى غير صالح لا يُحفظ منشورًا).
 */
class PuzzleLabExerciseSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        $this->refuseInProduction('PuzzleLabExerciseSeeder');

        $created = 0;
        $skipped = 0;

        foreach (self::exercises() as $row) {
            if (PuzzleLabExercise::query()->where('slug', $row['slug'])->exists()) {
                $skipped++;

                continue;
            }

            $exercise = new PuzzleLabExercise($row);
            $exercise->forceFill(['status' => PuzzleLabExercise::STATUS_PUBLISHED, 'published_at' => now()])->save();
            $created++;
        }

        $this->command?->info("PuzzleLabExerciseSeeder: أُنشئ {$created} وتُخطّي {$skipped} موجود مسبقًا.");
    }

    /** @return list<array<string, mixed>> */
    public static function exercises(): array
    {
        return [
            [
                'slug' => 'lab-memory-nature',
                'game_type' => PuzzleLabExercise::TYPE_MEMORY,
                'level' => 'easy',
                'sort_order' => 10,
                'title' => 'أزواج الطبيعة',
                'summary' => 'أربعة أزواج من عناصر الطبيعة: تسخين خفيف للذاكرة البصرية.',
                'instructions' => "اضغط بطاقة لتكشف وجهها، ثم بطاقة ثانية. إن تطابقتا بقيتا مكشوفتين، وإن اختلفتا انقلبتا من جديد.\nطابق الأزواج الأربعة كلها. لا يوجد وقت محدد ولا حدّ للمحاولات.",
                'wrong_note' => 'لم تتطابق البطاقتان، وهذا جزء طبيعي من التدريب. تذكّر مكان كل وجه رأيته، وحين تقلب بطاقة جديدة اسأل نفسك أولًا: هل رأيت شبيهتها من قبل؟',
                'success_explanation' => 'أحسنت! تقوى الذاكرة حين تربط كل وجه بموضعه وتبني على ما رأيته، لا حين تحاول حفظ كل البطاقات دفعة واحدة.',
                'game_config' => ['faces' => ['شمس', 'قمر', 'نجمة', 'غيمة']],
            ],
            [
                'slug' => 'lab-memory-twelves',
                'game_type' => PuzzleLabExercise::TYPE_MEMORY,
                'level' => 'medium',
                'sort_order' => 20,
                'title' => 'مضاعفات الاثني عشر',
                'summary' => 'ستة أزواج من الأرقام المتقاربة الشكل: تحتاج تركيزًا وتنظيمًا في التذكّر.',
                'instructions' => "كل رقم هنا يظهر مرتين، وكلها من مضاعفات العدد 12.\nاكشف بطاقتين في كل مرة وطابق الأزواج الستة. جرّب أن تتذكر الأرقام مجمّعة لا منفردة.",
                'wrong_note' => 'الأرقام متقاربة الشكل فتختلط على العين. جرّب ربط كل رقم بترتيبه بين مضاعفات 12 (الأول، الثاني، الثالث...) فيسهل عليك تذكّر موضعه.',
                'success_explanation' => 'التجميع هو السر: حين تعطي كل رقم معنى (مضاعف 12 رقم كذا) يصبح تذكّره أسهل من حفظ شكله فقط.',
                'game_config' => ['faces' => ['12', '24', '36', '48', '60', '72']],
            ],
            [
                'slug' => 'lab-sequence-plant',
                'game_type' => PuzzleLabExercise::TYPE_SEQUENCE,
                'level' => 'easy',
                'sort_order' => 30,
                'title' => 'مراحل نمو النبتة',
                'summary' => 'رتّب خمس مراحل من حياة النبتة من البذرة إلى الثمرة.',
                'instructions' => "رتّب مراحل نمو النبتة من البداية إلى النهاية.\nاستعمل أزرار «تحريك لأعلى» و«تحريك لأسفل» لتغيير موضع العنصر، ثم اضغط «تحقّق من الترتيب».",
                'wrong_note' => 'فكّر فيما لا يمكن أن يحدث قبل غيره: ما الذي يجب أن يوجد أولًا حتى تنمو الأجزاء التالية؟ حدّد المرحلة الأولى بيقين ثم ابنِ عليها.',
                'success_explanation' => 'تبدأ النبتة ببذرة، ثم تنمو الجذور لتغذّيها، فيظهر الساق والأوراق، ثم تتفتح الزهرة التي تتحول إلى ثمرة تحمل بذورًا جديدة.',
                'game_config' => ['items' => [
                    'تُزرع البذرة في التربة',
                    'تتغذّى البذرة وتنبت الجذور',
                    'يظهر الساق وتنمو الأوراق',
                    'تتفتّح الزهرة',
                    'تتكوّن الثمرة',
                ]],
            ],
            [
                'slug' => 'lab-sequence-planets',
                'game_type' => PuzzleLabExercise::TYPE_SEQUENCE,
                'level' => 'medium',
                'sort_order' => 40,
                'title' => 'الكواكب من الأقرب إلى الشمس',
                'summary' => 'ستة كواكب تحتاج ترتيبها بحسب بعدها عن الشمس.',
                'instructions' => "رتّب الكواكب التالية من الأقرب إلى الشمس إلى الأبعد عنها.\nحرّك العنصر بالأزرار ثم اضغط «تحقّق من الترتيب». يمكنك إعادة المحاولة بجولة جديدة متى شئت.",
                'wrong_note' => 'قسّم المسألة: ابدأ بالكوكب الذي تعرف يقينًا أنه الأقرب، ثم ما يليه. وتذكّر أن الكواكب الصخرية الصغيرة تسبق العملاقة الغازية.',
                'success_explanation' => 'الترتيب هو: عطارد، فالزهرة، فالأرض، فالمريخ، فالمشتري، فزحل. الكواكب الصخرية الأربعة الأولى أقرب إلى الشمس، ثم يأتي العملاقان الغازيان.',
                'game_config' => ['items' => ['عطارد', 'الزهرة', 'الأرض', 'المريخ', 'المشتري', 'زحل']],
            ],
        ];
    }
}
