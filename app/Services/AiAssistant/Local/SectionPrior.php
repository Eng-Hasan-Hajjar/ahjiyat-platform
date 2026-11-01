<?php

namespace App\Services\AiAssistant\Local;

use App\Services\AiAssistant\Knowledge\KnowledgeIndex;
use App\Services\AiAssistant\Local\Language\Analysis;
use App\Services\AiAssistant\Local\Language\Lexicon;
use App\Services\Search\TextFold;

/**
 * TI-Q1 - ترجيح الأقسام (Section Prior): تشابه جيب التمام بين n-gram حرفية (ثلاثية) لكلمات السؤال وملف تعريف كل نية معرفية،
 * ويتألف الملف من (1) نص القسم المنشور الذي تُستخرج منه الإجابة، (2) عنوان المقال، (3) صيغ المعجم لمفاهيم النية الموزونة بإيجاب.
 * غرضه: التقاط صياغات مرادفة أو مشوّهة إملائيًا لا يغطيها المعجم حرفيًا، دون توليد نص ولا نموذج لغوي ولا شبكة.
 *
 * ضمانات: الإشارة **مساعدة** فقط - تُضاف نُدفةً إلى درجة نية لها دليل مفاهيمي، أو تنقذ حالة «لا نية» بشروط صارمة (درجة عليا وفارق عن الثانية)؛
 * لا تُستعمل للمواضيع المحجوبة/خارج النطاق/الغائبة (تُحسم قبلها في IntentClassifier)، ولا تُنتج أي نص: الإجابة تبقى استخراجًا من المعرفة المنشورة.
 * يُبنى النموذج من الفهرس المنشور (يتغير بالنشر) ويُخزَّن في الذاكرة بحسب توقيع المقاطع فلا يُعاد بناؤه لكل سؤال.
 */
final class SectionPrior
{
    /** @var array<string, array{profiles: array<string, array<string, true>>, idf: array<string, float>, norm: array<string, float>}|null> */
    private static array $cache = [];

    /** @var array<string, true>|null */
    private static ?array $skip = null;

    public function __construct(
        private readonly KnowledgeIndex $index,
        private readonly AnswerComposer $composer,
    ) {}

    /**
     * جيب التمام لكل نية معرفية بحسب كلمات المحتوى في السؤال.
     *
     * @return array<string, float> نية ⇒ [0..1]
     */
    public function scores(Analysis $a, bool $member): array
    {
        $model = $this->model($member);

        if ($model === null) {
            return [];
        }

        $query = self::grams(self::contentTokens($a->tokens));

        if ($query === []) {
            return [];
        }

        $qnorm = 0.0;

        foreach ($query as $gram => $_) {
            $qnorm += ($model['idf'][$gram] ?? 1.0) ** 2;
        }

        if ($qnorm <= 0) {
            return [];
        }

        $scores = [];

        foreach ($model['profiles'] as $intent => $profile) {
            $dot = 0.0;

            foreach ($query as $gram => $_) {
                if (isset($profile[$gram])) {
                    $dot += ($model['idf'][$gram] ?? 1.0) ** 2;
                }
            }

            if ($dot > 0 && $model['norm'][$intent] > 0) {
                $scores[$intent] = $dot / (sqrt($qnorm) * $model['norm'][$intent]);
            }
        }

        arsort($scores);

        return $scores;
    }

    /** @return array{profiles: array<string, array<string, true>>, idf: array<string, float>, norm: array<string, float>}|null */
    private function model(bool $member): ?array
    {
        $entries = $this->index->entries($member);

        if ($entries === []) {
            return null;
        }

        $signature = ($member ? 'm' : 'p').':'.count($entries).':'.crc32(implode(',', array_map(fn (array $e) => $e['chunk_id'].'v'.crc32((string) $e['text']), $entries)));

        if (array_key_exists($signature, self::$cache)) {
            return self::$cache[$signature];
        }

        $profiles = [];

        foreach (IntentCatalog::knowledge() as $intent => $def) {
            $slugEntries = array_values(array_filter($entries, fn (array $e) => $e['slug'] === ($def['a'] ?? '')));

            if ($slugEntries === []) {
                continue;
            }

            $words = [(string) $slugEntries[0]['title']];

            foreach ($this->composer->sectionLines($slugEntries, $def['s']) as $line) {
                $words[] = $line;
            }

            foreach ($def['w'] as $concept => $weight) {
                if ($weight >= 3) {
                    foreach (Lexicon::surfaces($concept) as $surface) {
                        $words[] = $surface;
                    }
                }
            }

            $grams = self::grams(self::contentTokens(self::split(implode(' ', $words))));

            if ($grams !== []) {
                $profiles[$intent] = $grams;
            }
        }

        if ($profiles === []) {
            return self::$cache[$signature] = null;
        }

        $df = [];

        foreach ($profiles as $grams) {
            foreach ($grams as $gram => $_) {
                $df[$gram] = ($df[$gram] ?? 0) + 1;
            }
        }

        $n = count($profiles);
        $idf = [];

        foreach ($df as $gram => $count) {
            $idf[$gram] = log(1 + $n / $count);
        }

        $norm = [];

        foreach ($profiles as $intent => $grams) {
            $sum = 0.0;

            foreach ($grams as $gram => $_) {
                $sum += $idf[$gram] ** 2;
            }

            $norm[$intent] = sqrt($sum);
        }

        if (count(self::$cache) > 8) {
            self::$cache = [];
        }

        return self::$cache[$signature] = ['profiles' => $profiles, 'idf' => $idf, 'norm' => $norm];
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $folded = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', TextFold::fold($text)) ?? '';

        return array_values(array_filter(preg_split('/\s+/u', trim($folded)) ?: [], fn ($w) => $w !== ''));
    }

    /**
     * كلمات المحتوى فقط: بلا كلمات وقف ولا أدوات استفهام ولا نفي ولا كلمات أقصر من ثلاثة أحرف.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function contentTokens(array $tokens): array
    {
        if (self::$skip === null) {
            self::$skip = [];

            foreach (array_merge(Lexicon::STOPWORDS, Lexicon::NEGATORS, Lexicon::PERSONAL_WEAK) as $word) {
                self::$skip[Lexicon::stripAl(TextFold::fold($word))] = true;
            }

            foreach (array_keys(Lexicon::facetSingles()) as $word) {
                self::$skip[$word] = true;
            }
        }

        $out = [];

        foreach ($tokens as $token) {
            $word = Lexicon::stripAl($token);

            if (mb_strlen($word) >= 3 && ! isset(self::$skip[$word]) && preg_match('/^[\p{Arabic}\p{L}]+$/u', $word) === 1) {
                $out[] = $word;
            }
        }

        return $out;
    }

    /**
     * n-gram حرفية ثلاثية بحدود كلمة، مع علامة للكلمة الكاملة (تُرجّح التطابق التام).
     *
     * @param  list<string>  $words
     * @return array<string, true>
     */
    private static function grams(array $words): array
    {
        $grams = [];

        foreach ($words as $word) {
            $padded = '#'.$word.'#';
            $chars = mb_str_split($padded);

            for ($i = 0, $n = count($chars) - 2; $i < $n; $i++) {
                $grams[$chars[$i].$chars[$i + 1].$chars[$i + 2]] = true;
            }

            $grams['w:'.$word] = true;
        }

        return $grams;
    }
}
