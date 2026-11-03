<?php

namespace App\Services\AiAssistant\Local\Enhanced;

use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Analyzer;
use App\Services\AiAssistant\Local\Learned\Featurizer;
use App\Services\AiAssistant\Local\SectionPrior;
use App\Services\Search\TextFold;

/**
 * TATAWWUR-FINAL - تصنيف واعٍ بالسياق: يصنّف السؤال وحده أولًا، فإن بدا سؤال متابعة مبتورًا («وكيف أكسبها؟»، «وإذا ما عندي رصيد؟») يُجرَّب
 * أيضًا مع الرسالة السابقة **من محادثة السائل نفسه** (تاريخ الدور الحالي فقط، لا مخزن مشترك ولا ذاكرة بين المستخدمين) ويُفضَّل الأقوى دليلًا.
 * لا يُخزَّن شيء ولا تُحفظ أي بيانات شخصية: السياق يُمرَّر من الطلب ويزول معه.
 */
final class ContextualClassifier
{
    /** مفاتيح تدل على سؤال متابعة مبتور حين تبدأ بها الرسالة (مطوية). */
    private const STARTERS = ['و', 'طيب', 'اذا', 'لو', 'يعني', 'ماذا عن', 'ايضا', 'كمان', 'والان', 'تمام', 'اوكي', 'ثم', 'طب', 'بس'];

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly SectionPrior $prior,
        private readonly HybridClassifier $hybrid,
    ) {}

    /** @return array{0: HybridResult, 1: Analysis, 2: bool} [النتيجة، التحليل، هل استُعمل السياق] */
    public function classify(string $text, ?string $previous, bool $member): array
    {
        $a = $this->analyzer->analyze($text, $previous);
        $tokens = Featurizer::tokens($text);
        $r = $this->hybrid->classify($a, $tokens, $this->prior->scores($a, $member));

        // محاولة ثانية بمصحّح إملائي أعمّ عند وجود كلمات مجهولة، وتُقبل بشرط زيادة الدليل.
        if ($a->residual !== [] && in_array($r->classification->status, ['none', 'close', 'ok'], true)) {
            $a2 = $this->analyzer->analyze($text, $previous, true);

            if ($a2->corrections !== $a->corrections) {
                $r2 = $this->hybrid->classify($a2, $tokens, $this->prior->scores($a2, $member));

                if ($this->stronger($r, $r2)) {
                    [$a, $r] = [$a2, $r2];
                }
            }
        }

        if ($previous === null || trim($previous) === '' || ! $this->mayBeFollowUp($a, $r, $text)) {
            return [$r, $a, false];
        }

        $combined = $this->hybrid->classify($a, Featurizer::tokens($text.' '.$previous), $this->prior->scores($a, $member));

        if ($this->stronger($r, $combined, true)) {
            return [$combined, $a, true];
        }

        return [$r, $a, false];
    }

    /** هل يبدو السؤال متابعة مبتورة؟ (مفتاح افتتاحي، أو بلا موضوع، أو قصير جدًا، أو تصنيفه ضعيف). */
    private function mayBeFollowUp(Analysis $a, HybridResult $r, string $text): bool
    {
        $folded = trim((string) preg_replace('/\s+/u', ' ', TextFold::fold($text)));
        $first = explode(' ', $folded)[0] ?? '';
        $startsLikeFollowUp = in_array($first, self::STARTERS, true) || (str_starts_with($first, 'و') && mb_strlen($first) > 2 && mb_strlen($first) <= 5)
            || in_array(implode(' ', array_slice(explode(' ', $folded), 0, 2)), self::STARTERS, true);
        $short = count(explode(' ', $folded)) <= 4;
        $status = $r->classification->status;

        if ($status !== 'ok') {
            return true;
        }

        return $startsLikeFollowUp || $short || $a->topicCount() === 0 || $r->probability((string) $r->classification->intent) < EnhancedPolicy::FOLLOW_UP_SINGLE_P;
    }

    /** هل نتيجة $b أقوى من $a؟ (في وضع المتابعة: تُفضَّل حين تكون الأولى غير حاسمة). */
    private function stronger(HybridResult $a, HybridResult $b, bool $contextual = false): bool
    {
        $sa = $a->classification;
        $sb = $b->classification;

        if (! in_array($sb->status, ['ok', 'close'], true) || $sb->intent === null) {
            return false;
        }

        if (! in_array($sa->status, ['ok', 'close'], true) || $sa->intent === null) {
            return true;
        }

        if ($sa->intent === $sb->intent) {
            return $sb->status === 'ok' && $sa->status !== 'ok';
        }

        $pa = $a->probability($sa->intent);
        $pb = $b->probability($sb->intent);

        return $contextual
            ? ($pb >= EnhancedPolicy::FOLLOW_UP_MIN_P && $pb > $pa + EnhancedPolicy::FOLLOW_UP_GAIN && $sb->status === 'ok')
            : ($sb->status === 'ok' && $sb->score >= $sa->score + 3.0);
    }
}
