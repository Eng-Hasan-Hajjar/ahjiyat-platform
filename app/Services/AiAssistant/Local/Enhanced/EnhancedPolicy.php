<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\Search\TextFold;

/**
 * TATAWWUR-FINAL - عتبات طبقة الفهم المحسَّنة. ثوابت حتمية مضبوطة بالتحقق المتقاطع على مجموعات الضبط فقط (tools/tatawwur/tuning-notes.md)،
 * وتُراجَع على المجموعات الخارجية V2 والمحتجزة، ولم تُضبط إطلاقًا على مجموعة القبول V3.
 */
final class EnhancedPolicy
{
    /** احتمال أدنى لقبول فئة «محجوبة» يقترحها المصنِّف الخطي (تُضاف إلى القواعد الحتمية ولا تحلّ محلها). */
    public const BLOCK_P = 0.55;

    /** @var array<string, float> عتبات خاصة بكل فئة محجوبة (أعلى حيث تتشابه مع أسئلة مشروعة عن الآلية). */
    public const BLOCK_P_BY_CLASS = ['blocked.puzzle_secret' => 0.55, 'blocked.injection' => 0.50, 'blocked.hint_bypass' => 0.60, 'blocked.other_user' => 0.60, 'blocked.secrets' => 0.60, 'blocked.action' => 0.75];

    /** فوق هذا اليقين لا يسري حق النقض (استفهام عن آلية/حساب السائل) على الحجب الخطي. */
    public const BLOCK_CERTAIN_P = 0.92;

    /** احتمالات أدنى للفئات الخاصة، وأقصى دليل حتمي لنية معرفية يسمح بقبولها. */
    public const OOS_P = 0.45;

    public const OOS_MAX_ENGINE = 8.0;

    public const UNKNOWN_P = 0.45;

    public const UNKNOWN_MAX_ENGINE = 8.0;

    public const AMBIGUOUS_P = 0.35;

    public const AMBIGUOUS_MAX_ENGINE = 8.0;

    /** المتابعة: لا يُجرَّب السياق إن كان احتمال النية الوحيدة أعلى من هذا؛ ويُشترط للسياقية حد أدنى وفارق مكسب. */
    public const FOLLOW_UP_SINGLE_P = 0.55;

    public const FOLLOW_UP_MIN_P = 0.30;

    public const FOLLOW_UP_GAIN = 0.05;

    /** السؤال المركّب: أدنى احتمال لكل فقرة، وأقصى عدد نيات في الردّ. */
    public const CLAUSE_MIN_P = 0.30;

    public const MAX_COMPOUND = 3;

    /** أقل عدد حروف مفردة متتالية يُعدّ تمويهًا بالتباعد (مثل «a n s w e r» أو «ج و ا ب»)؛ الجملة الطبيعية لا تحوي ثلاثة حروف مفردة متتالية. */
    public const SPACED_RUN_MIN = 3;

    /**
     * تقوية أمنية خاصة بالمسار المحسَّن (لا تمسّ المحارس المشتركة ولا المسار القديم): نصٌّ بديل تُدمج فيه تسلسلات الحروف المفردة المتباعدة
     * كي تفحصه المحارس نفسها (AiPuzzleSafetyGuard وAiScopeGuard) كما تفحص النص الخام. null إن لم يوجد تباعد.
     */
    public static function despaced(string $text): ?string
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $single = fn (string $t): bool => preg_match('/^\p{L}[\p{P}]*$/u', $t) === 1;
        $out = [];
        $changed = false;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (! $single($tokens[$i])) {
                $out[] = $tokens[$i];

                continue;
            }

            $j = $i;

            while ($j < $n && $single($tokens[$j])) {
                $j++;
            }

            if ($j - $i >= self::SPACED_RUN_MIN) {
                $out[] = implode('', array_slice($tokens, $i, $j - $i));
                $changed = true;
            } else {
                foreach (array_slice($tokens, $i, $j - $i) as $t) {
                    $out[] = $t;
                }
            }

            $i = $j - 1;
        }

        return $changed ? implode(' ', $out) : null;
    }

    /** مصطلحات «الكلمة/الرمز السري» (بعد التطبيع). */
    private const SECRET_WORD = ['كلمه السر', 'كلمه السريه', 'الكلمه السريه', 'الرمز السري', 'الرقم السري', 'الكود السري', 'الشفره', 'كلمه المرور'];

    /** سياق الأحجية (بعد التطبيع)، بسوابق حروف الجر والعطف و«ال». */
    private const PUZZLE_NOUN_RE = '/(?:^| )(?:[بلوفك])?(?:ال)?(?:احجي|لغز|الغاز|تحدي اليوم)/u';

    /** سياق الحساب: إن وُجد فالسؤال عن كلمة مرور الحساب لا عن «سر» اللغز. */
    private const ACCOUNT_CONTEXT = ['حسابي', 'حسابك', 'الحساب', 'تسجيل الدخول', 'تسجيل دخولي', 'نسيت', 'اعاده تعيين', 'تغيير كلمه', 'غير كلمه', 'بريدي', 'ايميلي', 'البريد'];

    /**
     * «ما هي الكلمة السرية في الأحجية؟»: طلب سرّ اللغز بلفظ مختلف. يُحجب في المسار المحسَّن فقط حين تجتمع عبارة «كلمة سر» مع اسم لغز
     * وتغيب قرائن الحساب، فيبقى سؤال «نسيت كلمة المرور» والتحقق من الحساب سليمَين.
     */
    public static function secretWordOfPuzzle(string $text): bool
    {
        $f = ' '.trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($text))).' ';

        $has = static function (array $needles) use ($f): bool {
            foreach ($needles as $n) {
                if (str_contains($f, ' '.$n.' ') || str_contains($f, ' '.$n)) {
                    return true;
                }
            }

            return false;
        };

        return $has(self::SECRET_WORD) && preg_match(self::PUZZLE_NOUN_RE, $f) === 1 && ! $has(self::ACCOUNT_CONTEXT);
    }
}
