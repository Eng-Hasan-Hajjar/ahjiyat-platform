<?php

namespace App\Services\AiAssistant\Local\Learned;

/**
 * TATAWWUR-FINAL - مصنِّف نوايا خطي صغير مدرَّب محليًا (انحدار لوجستي متعدد الفئات على TF-IDF لكلمات وn-gram حرفية).
 *
 * ما هو: جدول أوزان ثابت (ملف PHP ينتجه tools/tatawwur/train_intent_model.py من أسئلة الضبط ونصوص المقالات المنشورة)، وعملية ضرب نقطي بلا أي مكتبة.
 * ما ليس هو: ليس نموذج لغة ولا تضمينات دلالية ولا يفهم المعنى؛ يتعلّم أي كلمات وأجزاء كلمات ترتبط بأي نية، فيقوّي مقاومة الأخطاء الإملائية واللهجات والصياغات القريبة مما رآه.
 * لا يولّد نصًا، ولا يُنتج حقائق: يرتّب النوايا فقط، والإجابة تبقى استخراجًا من المعرفة المنشورة المراجَعة. لا شبكة، لا تنزيل تلقائي، لا ملفات خارج المستودع.
 */
final class LearnedIntentScorer
{
    /** @var array<string, mixed>|null */
    private static ?array $model = null;

    private static bool $tried = false;

    public static function path(): string
    {
        return __DIR__.'/model/intent_model.php';
    }

    /** @return array<string, mixed>|null */
    private static function model(): ?array
    {
        if (! self::$tried) {
            self::$tried = true;
            $file = self::path();

            if (is_file($file)) {
                // مسار حرفي ثابت لا متغير: تدقيق E26 (W6) يمنع أي include/require بمسار ديناميكي. المسار نفسه الذي تعيده path().
                $loaded = require __DIR__.'/model/intent_model.php';
                self::$model = is_array($loaded) && isset($loaded['classes'], $loaded['f'], $loaded['bias']) ? $loaded : null;
            }
        }

        return self::$model;
    }

    /** يصفّر الذاكرة المؤقتة (للاختبار فقط). */
    public static function forget(): void
    {
        self::$model = null;
        self::$tried = false;
    }

    public static function available(): bool
    {
        return self::model() !== null;
    }

    /** @return array<string, mixed> وصف النموذج (الإصدار، بصمات بيانات التدريب، الحجم) بلا أوزان. */
    public static function meta(): array
    {
        return self::model()['meta'] ?? [];
    }

    /**
     * احتمالات الفئات (softmax بعد معايرة الحرارة)، الأعلى أولًا، محدودة بـ $top.
     *
     * @param  list<string>  $tokens  ناتج Featurizer::tokens
     * @return array<string, float>
     */
    public function probabilities(array $tokens, int $top = 12): array
    {
        $model = self::model();

        if ($model === null || $tokens === []) {
            return [];
        }

        $logits = $model['bias'];
        $counts = Featurizer::counts($tokens);

        foreach ($counts as $block) {
            $values = [];
            $norm = 0.0;

            foreach ($block as $feature => $tf) {
                $entry = $model['f'][$feature] ?? null;

                if ($entry === null) {
                    continue;
                }

                $v = (1.0 + log($tf)) * $entry[0];
                $values[$feature] = $v;
                $norm += $v * $v;
            }

            if ($norm <= 0.0) {
                continue;
            }

            $norm = sqrt($norm);

            foreach ($values as $feature => $v) {
                $x = $v / $norm;

                foreach ($model['f'][$feature][1] as $class => $weight) {
                    $logits[$class] += $x * $weight;
                }
            }
        }

        $t = (float) ($model['temperature'] ?? 1.0);
        $max = max($logits);
        $sum = 0.0;
        $exp = [];

        foreach ($logits as $i => $logit) {
            $exp[$i] = exp(($logit - $max) * $t);
            $sum += $exp[$i];
        }

        arsort($exp);
        $out = [];

        foreach (array_slice($exp, 0, $top, true) as $i => $e) {
            $out[$model['classes'][$i]] = round($e / $sum, 5);
        }

        return $out;
    }
}
