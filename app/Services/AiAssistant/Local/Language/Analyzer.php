<?php

namespace App\Services\AiAssistant\Local\Language;

use App\Services\AiAssistant\Knowledge\KnowledgeNormalizer;
use App\Services\Search\TextFold;

/**
 * TI-2 - محلّل الأسئلة العربية: تطبيع (همزات/تشكيل/تطويل/أرقام) ← تقطيع ← تصحيح إملائي محافظ ← استخراج المفاهيم (عبارات ثم مفردات بتحليل صرفي)
 * ← وجه السؤال (ما/كيف/أين/متى/لماذا/كم/هل/الفرق) ← النفي المباشر ← الملكية (سؤال عن حال السائل نفسه) ← دمج سياق السؤال السابق عند غياب الموضوع.
 * محلي بالكامل: بلا شبكة ولا نموذج ولا قاعدة بيانات.
 */
final class Analyzer
{
    private const MAX_TOKENS = 60;

    /** كلمات نداء (مطوية) تأتي بعد «يا» في مخاطبة المساعد. */
    private const VOCATIVES = ['مساعد', 'بوت', 'روبوت', 'تطور', 'ذكي', 'صاحبي', 'اخي', 'اخ'];

    /** كلمات (مطوية) إذا سبقت «فرق» فهي بمعنى الاختلاف لا الأندية. */
    private const DIFF_PRECEDERS = ['ما', 'ماهو', 'ماهي', 'هو', 'هي', 'ايش', 'اش', 'شو', 'وش', 'ايه', 'شنو', 'عن', 'اعرف', 'عرفني', 'اشرح', 'اشرحلي', 'وضح', 'وضحلي', 'فسر', 'اذكر', 'هناك', 'يوجد', 'في', 'فيه', 'هل', 'اي', 'كم', 'يعني', 'بيان', 'قارن', 'عندكم', 'لي'];

    /** @var array<string, true>|null */
    private static ?array $stop = null;

    /** @var array<string, true>|null */
    private static ?array $strong = null;

    /** @var array<string, true>|null */
    private static ?array $weak = null;

    /** @var array<string, true>|null */
    private static ?array $negators = null;

    public function analyze(string $text, ?string $previous = null, bool $loose = false): Analysis
    {
        $analysis = $this->analyzeOne($text, $loose);

        if ($previous !== null && trim($previous) !== '' && $analysis->topicCount() === 0) {
            $prev = $this->analyzeOne($previous, $loose);
            $inherited = array_diff_key($prev->concepts, array_flip(Lexicon::STRUCTURAL));

            if ($inherited !== []) {
                return new Analysis($analysis->folded, $analysis->tokens, $analysis->concepts, $analysis->negated, $analysis->facets, $analysis->personal, $analysis->corrections, $analysis->residual, $inherited);
            }
        }

        return $analysis;
    }

    private function analyzeOne(string $text, bool $loose = false): Analysis
    {
        $this->boot();

        $clean = KnowledgeNormalizer::normalize($text);
        $folded = TextFold::fold($clean);
        $folded = preg_replace('/(\p{L})\1{2,}/u', '$1', $folded) ?? $folded;      // شووو ← شو
        $folded = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $folded) ?? $folded;
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($folded)) ?: [], fn ($t) => $t !== ''));
        $tokens = array_slice($tokens, 0, self::MAX_TOKENS);

        // نداء المساعد في أول الجملة («يا مساعد …») مخاطَبة لا موضوع سؤال: لا يُحتسب مفهوم «المساعد».
        if (count($tokens) > 2 && $tokens[0] === 'يا' && in_array($tokens[1], self::VOCATIVES, true)) {
            $tokens = array_slice($tokens, 2);
        }

        $singles = Lexicon::singles();
        $corrections = [];

        // تصحيح إملائي: فقط لكلمة لا يعرف المعجم أيًّا من مرشَّحاتها.
        foreach ($tokens as $i => $token) {
            if (isset(self::$stop[$token]) || $this->known($token, $singles)) {
                continue;
            }

            $fixed = null;

            foreach (Morphology::bases($token) as $base) {
                $fixed = SpellCorrector::correct($base, $loose);

                if ($fixed !== null) {
                    break;
                }
            }

            if ($fixed !== null) {
                $corrections[] = [$token, $fixed];
                $tokens[$i] = $fixed;
            }
        }

        // استخراج المفاهيم: عبارات أولًا (الأطول فالأقصر) ثم المفردات.
        $phrases = Lexicon::phrases();
        $maxPhrase = Lexicon::maxPhraseLength();
        $concepts = [];
        $positions = [];
        $consumed = [];
        $strongPersonal = false;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (isset($consumed[$i])) {
                continue;
            }

            $hit = null;

            for ($len = min($maxPhrase, $n - $i); $len >= 2 && $hit === null; $len--) {
                foreach ($this->phraseKeys(array_slice($tokens, $i, $len)) as $key) {
                    if (isset($phrases[$key])) {
                        $hit = [$phrases[$key], $len];

                        break;
                    }
                }
            }

            if ($hit !== null) {
                $concepts[$hit[0]] = ($concepts[$hit[0]] ?? 0) + 1;
                $positions[] = [$hit[0], $i];

                for ($k = 0; $k < $hit[1]; $k++) {
                    $consumed[$i + $k] = true;
                }

                continue;
            }
        }

        $residual = [];

        for ($i = 0; $i < $n; $i++) {
            $token = $tokens[$i];

            if (isset($consumed[$i])) {
                continue;
            }

            $found = null;
            $possessive = false;

            foreach (Morphology::candidates($token) as [$form, $poss]) {
                $key = Lexicon::stripAl($form);

                if (isset($singles[$key])) {
                    $found = $singles[$key];
                    $possessive = $poss;

                    break;
                }
            }

            // «الفرق بين …» اختلاف لا أندية: لا يُحتسب مفهوم «الفريق».
            if ($found === 'team' && Lexicon::stripAl($token) === 'فرق' && ($tokens[$i + 1] ?? '') === 'بين') {
                $found = null;
            }

            if ($found !== null) {
                $concepts[$found] = ($concepts[$found] ?? 0) + 1;
                $positions[] = [$found, $i];

                if ($possessive && ! in_array($found, ['hold', 'cap', 'invite', 'status', 'cash_value', 'figure_gems'], true)) {
                    $strongPersonal = $strongPersonal || in_array($found, ['gems', 'wallet', 'xp', 'level', 'achievement', 'quest', 'profile', 'account', 'progress', 'points', 'star', 'friend', 'team', 'pass_points', 'supporter_card', 'streak'], true);
                }
            } elseif (! isset(self::$stop[$token]) && mb_strlen($token) >= 3 && ! isset(self::$negators[$token])) {
                $residual[] = $token;
            }
        }

        // وجه السؤال.
        $facets = $this->facets($tokens);

        // النفي المباشر + «عدم القدرة».
        $negated = [];
        $conceptByIndex = [];

        foreach ($positions as [$concept, $index]) {
            $conceptByIndex[$index] = $concept;
        }

        foreach ($tokens as $i => $token) {
            if (! isset(self::$negators[$token])) {
                continue;
            }

            foreach ([$i + 1, $i + 2] as $j) {
                if (isset($conceptByIndex[$j])) {
                    $negated[$conceptByIndex[$j]] = true;

                    break;
                }
            }

            $next = $tokens[$i + 1] ?? '';

            if (in_array($next, ['اقدر', 'استطيع', 'يمكنني', 'يمكن', 'ينفع', 'اعرف', 'قادر'], true)) {
                $concepts['cannot'] = 1;
            }
        }

        // الملكية.
        $personal = ($strongPersonal || isset($concepts['me_did'])) ? 2 : 0;

        foreach ($tokens as $i => $token) {
            if ($token === 'بي' && in_array($tokens[$i - 1] ?? '', ['الخاص', 'الخاصه', 'خاص', 'خاصه', 'تبعي'], true)) {
                $personal = 2;
            }
        }

        foreach ($tokens as $token) {
            if (isset(self::$strong[$token])) {
                $personal = 2;

                break;
            }

            if (isset(self::$weak[$token])) {
                $personal = max($personal, 1);
            }
        }

        return new Analysis($folded, $tokens, $concepts, $negated, $facets, $personal, $corrections, $residual);
    }

    /** @param array<string, string> $singles */
    private function known(string $token, array $singles): bool
    {
        if (preg_match('/^[\p{Arabic}]+$/u', $token) !== 1) {
            return true;      // لاتيني/رقم: لا يُصحَّح
        }

        $phraseTokens = Lexicon::phraseTokens();

        foreach (Morphology::candidates($token) as [$form]) {
            $key = Lexicon::stripAl($form);

            if (isset($singles[$key]) || isset(Lexicon::facetSingles()[$key]) || isset($phraseTokens[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * مفاتيح عبارة محتملة لمقطع من الكلمات: كل كلمة بصيغتها وبصيغة بعد تجريد السوابق (بلا «ال»).
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function phraseKeys(array $words): array
    {
        $options = [];

        foreach ($words as $word) {
            $forms = [];

            foreach (Morphology::bases($word) as $base) {
                $forms[Lexicon::stripAl($base)] = true;
            }

            $options[] = array_keys($forms);
        }

        $keys = [''];

        foreach ($options as $forms) {
            $next = [];

            foreach ($keys as $prefix) {
                foreach ($forms as $form) {
                    $next[] = $prefix === '' ? $form : $prefix.' '.$form;
                }
            }

            $keys = array_slice($next, 0, 24);
        }

        return $keys;
    }

    /**
     * أوجه السؤال المرصودة بترتيب الأسبقية.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function facets(array $tokens): array
    {
        $found = [];
        $singles = Lexicon::facetSingles();
        $phrases = Lexicon::facetPhrases();
        $max = Lexicon::maxFacetPhraseLength();
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $matched = false;

            for ($len = min($max, $n - $i); $len >= 2; $len--) {
                foreach ($this->phraseKeys(array_slice($tokens, $i, $len)) as $key) {
                    if (isset($phrases[$key])) {
                        $found[$phrases[$key]] = true;
                        $i += $len - 1;
                        $matched = true;

                        break 2;
                    }
                }
            }

            if (! $matched) {
                $key = Lexicon::stripAl($tokens[$i]);

                if (isset($singles[$key])) {
                    if ($singles[$key] !== 'diff' || $key !== 'فرق' || $this->differenceSense($tokens, $i)) {
                        $found[$singles[$key]] = true;
                    }
                } else {
                    foreach (Morphology::bases($tokens[$i]) as $base) {
                        $b = Lexicon::stripAl($base);

                        if ($b !== $key && isset($singles[$b]) && mb_strlen($b) >= 3) {
                            $found[$singles[$b]] = true;

                            break;
                        }
                    }
                }
            }
        }

        $order = ['diff', 'why', 'howmuch', 'how', 'where', 'when', 'who', 'can', 'what', 'which'];

        return array_values(array_filter($order, fn ($f) => isset($found[$f])));
    }

    /**
     * «الفرق» تعني الاختلاف أو الأندية. تُعدّ اختلافًا إذا جاءت في أول الجملة أو بعد أداة استفهام/طلب شرح أو قبل «بين»؛
     * أما في الإضافة بعد اسم («بطولات الفرق»، «ترتيب الفرق») فهي الأندية فلا تُنتج وجه «الفرق بين».
     *
     * @param  list<string>  $tokens
     */
    private function differenceSense(array $tokens, int $i): bool
    {
        if ($i === 0 || (isset($tokens[$i + 1]) && $tokens[$i + 1] === 'بين')) {
            return true;
        }

        return in_array($tokens[$i - 1], self::DIFF_PRECEDERS, true);
    }

    private function boot(): void
    {
        if (self::$stop !== null) {
            return;
        }

        $fold = fn (array $words) => array_fill_keys(array_map(fn ($w) => TextFold::fold($w), $words), true);
        self::$stop = $fold(Lexicon::STOPWORDS);
        self::$strong = $fold(Lexicon::PERSONAL_STRONG);
        self::$weak = $fold(Lexicon::PERSONAL_WEAK);
        self::$negators = $fold(array_diff(Lexicon::NEGATORS, ['ما']));
    }
}
