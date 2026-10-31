<?php

namespace App\Services\AiAssistant\Safety;

use App\Services\Search\TextFold;

/**
 * E45 - حارس نية أسرار الأحجيات (حتمي، قبل النموذج): «تطوّر ليس مُخبِرًا بالحلول».
 *
 * يرفض طلب: الحل/الجواب/الإجابة/الخيار الصحيح/الترتيب الصحيح/الحقول السرية (answer_raw/solution_data…) متى اقترنت بأحجية أو تحدٍّ، وكذلك أي محاولة التفافية
 * (أول حرف، ترميز base64، استعلام SQL، «بطريقة غير مباشرة»، ادّعاء صفة مشرف). ويحوّل طلب التلميح إلى ميزة التلميح الرسمية ولا يجلبه بنفسه أبدًا.
 *
 * يسمح بالأسئلة الآمنة عن طريقة اللعب: كم محاولة؟ كيف أستخدم التلميح؟ لماذا الأحجية مقفلة؟ كيف أحل الأحجيات عمومًا؟
 * الأولوية للسلامة: الغموض في سياق الأحجية يُرفض. السياق السابق (سؤال المستخدم الأسبق) يُراعى فلا تُلتفّ القاعدة بتقسيم الطلب على رسالتين.
 */
final class AiPuzzleSafetyGuard
{
    public const REASON = 'puzzle_secret_refusal';

    private const SECRET_FIELDS = '/answer_raw|answer_hash|answer_normalized|solution_data|game_config|validation_secret|hidden_choices|correct_option|secret_answer/i';

    private const SECRET_FIELDS_SQUASHED = '/answerraw|answerhash|answernormalized|solutiondata|gameconfig|validationsecret|hiddenchoices|correctoption|secretanswer/i';

    /** أفعال «اكشف/أعطني…» الصريحة: مع كلمة الحل تُرفض ولو لم تُذكر الأحجية (لقد استسلمت، اكشف لي الحل). */
    private const REVEAL = '/\b(?:اكشف|اظهر|اعرض|اعطني|اعطيني|اخبرني|قل\s+لي|قولي|هات|عطني|دلني|reveal|show\s+me|tell\s+me|give\s+me)\b/u';

    /** أحجية/تحدٍّ بصيغ مطوية (TextFold). */
    private const PUZZLE_TERMS = ['احجيه', 'احجيات', 'الغاز', 'لغز', 'تحدي', 'تحديات', 'خيار', 'خيارات', 'اختيار', 'puzzle', 'puzzles', 'riddle', 'challenge', 'quiz', 'مسابقه', 'منافسه', 'مرحله', 'خطوه', 'لعبه'];

    private const ANSWER_TERMS = ['حل', 'الحل', 'حلها', 'حله', 'حلول', 'الحلول', 'جواب', 'الجواب', 'جوابها', 'اجابه', 'الاجابه', 'اجابتها', 'answer', 'answers', 'solution', 'solutions'];

    private const EXTRACTION = '/(?:اول|اخر|ثاني|اول\s+(?:كلمه|حرف))\s*(?:حرف|كلمه)|عدد\s+الحروف|base64|\bhex\b|rot13|ترميز|شفر|مشفر|عكس|تهجئ|\bspell|encode|decode|binary|\bsql\b|\bquery\b|استعلام|غير\s+مباشر|بدون\s+ان\s+تقول|لمح|ايحاء|قافيه|\bhint\s+at\b|first\s+letter|last\s+letter|letters?\b/u';

    private const HINT = '/تلميح|\bhints?\b|\bclues?\b/u';

    private const HOWTO = '/\b(?:كيف|طريقه|طرق|اين|كم|سعر|تكلف|يكلف|تكلفه|شرح|اشرح|ما\s+هي?\s+(?:ميزه|خاصيه|قواعد)|هل\s+(?:يمكن|يوجد|التلميح)|نصائح|استراتيجي|اساسيات|قواعد|لماذا|متي|how|where|what\s+is\s+the\s+hint\s+feature)\b/u';

    private const SPECIFIC = '/(?:رقم|#|no\.?)\s*\d+|هذه\s+(?:ال)?(?:احجيه|لغز|مرحله|خطوه)|هذا\s+(?:ال)?(?:لغز|تحدي|سؤال)|الحاليه|التي\s+امامي|اللي\s+امامي|this\s+puzzle/u';

    private const GIVE = '/\b(?:اعطني|اعطيني|اعطنا|اريد|ابغي|ابي|اعرض|اظهر|اكتب|قل\s+لي|قولي|هات|عطني|ادلني|دلني|اكشف|اخبرني|ما\s+هو|ما\s+هي|ماهو|ماهي|give|show|tell|reveal|what\s+is)\b/u';

    private const PROBLEM_CONTEXT = '/(?:مشكله|مشاكل|خطا|اخطاء|عطل|تسجيل\s+الدخول|نسيت|كلمه\s+المرور|استرجاع|الدعم|تواصل)/u';

    public function inspect(string $text, ?string $previous = null): GuardVerdict
    {
        $raw = mb_strtolower($text);

        // يُفحص النص خامًا ومضغوطًا (بلا مسافات/شرطات/نقاط/أحرف خفية) فلا يفلت «a n s w e r _ h a s h» أو «answer-hash» أو «answer\u200B_raw».
        $squashed = preg_replace('/[\s_\-.\x{200B}-\x{200F}\x{2060}\x{FEFF}]+/u', '', $raw) ?? $raw;

        if (preg_match(self::SECRET_FIELDS, $raw) === 1 || preg_match(self::SECRET_FIELDS_SQUASHED, $squashed) === 1) {
            return new GuardVerdict(GuardVerdict::REFUSE, 'secret_field', self::REASON);
        }

        $folded = TextFold::fold($text);
        $context = $previous !== null ? TextFold::fold($previous) : '';
        $tokens = $this->tokens($folded);
        $contextTokens = $this->tokens($context);

        $hasAnswer = $this->any($tokens, self::ANSWER_TERMS) && preg_match(self::PROBLEM_CONTEXT, $folded) !== 1;
        $hasPuzzle = $this->any($tokens, self::PUZZLE_TERMS) || preg_match(self::SPECIFIC, $folded) === 1;
        $contextPuzzle = $this->any($contextTokens, self::PUZZLE_TERMS);

        // «الجواب/الحل الصحيح» صراحةً.
        if ($hasAnswer && preg_match('/(?:الحل|الجواب|الاجابه|solution|answer)\s*(?:ال)?(?:صحيح|صحيحه|حقيقي|حقيقيه|correct)|correct\s+(?:answer|option|order|sequence)/u', $folded) === 1) {
            return new GuardVerdict(GuardVerdict::REFUSE, 'correct_answer', self::REASON);
        }

        if (preg_match('/(?:ال)?(?:خيار|ترتيب|تسلسل)\s*(?:ال)?صحيح|correct\s+(?:option|order|sequence)/u', $folded) === 1 && ($hasPuzzle || $contextPuzzle)) {
            return new GuardVerdict(GuardVerdict::REFUSE, 'correct_option', self::REASON);
        }

        // التفاف: استخراج جزئي/مرمَّز للحل.
        if ($hasAnswer && preg_match(self::EXTRACTION, $folded) === 1) {
            return new GuardVerdict(GuardVerdict::REFUSE, 'extraction', self::REASON);
        }

        // الحل + أحجية (في الرسالة أو سياقها): يُسمح فقط بسؤال «كيف» عام لا يخص أحجية محددة.
        if ($hasAnswer && ($hasPuzzle || $contextPuzzle)) {
            $howTo = preg_match(self::HOWTO, $folded) === 1;
            $specific = preg_match(self::SPECIFIC, $folded) === 1 || (preg_match(self::GIVE, $folded) === 1 && ! $howTo);

            if (! $howTo || $specific) {
                return new GuardVerdict(GuardVerdict::REFUSE, 'answer_request', self::REASON);
            }
        }

        // طلب كشف «الحل» صراحةً بلا ذكر الأحجية: في منصة أحجيات هو طلب حل أحجية (الأولوية للسلامة). الاستفسارات التقنية (مشكلة/خطأ…) مستثناة عبر hasAnswer.
        if ($hasAnswer && preg_match(self::REVEAL, $folded) === 1 && preg_match(self::HOWTO, $folded) !== 1) {
            return new GuardVerdict(GuardVerdict::REFUSE, 'reveal_answer', self::REASON);
        }

        // التلميح: يُحوَّل للميزة الرسمية ما لم يكن السؤال عن الميزة نفسها (كيف/كم/أين).
        if (preg_match(self::HINT, $folded) === 1 && preg_match(self::HOWTO, $folded) !== 1) {
            return new GuardVerdict(GuardVerdict::REDIRECT, 'hint_redirect', self::REASON);
        }

        return GuardVerdict::allow();
    }

    /** @return list<string> */
    private function tokens(string $folded): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $tokens @param list<string> $terms */
    private function any(array $tokens, array $terms): bool
    {
        foreach ($tokens as $token) {
            // «ال» التعريف وحروف الجر الملصقة (بالحل/للحل/والحل).
            foreach ([$token, preg_replace('/^(?:وال|بال|كال|فال|لل|ال|و|ب|ل)/u', '', $token) ?? $token] as $candidate) {
                if (in_array($candidate, $terms, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
