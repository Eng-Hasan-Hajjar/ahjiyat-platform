<?php

namespace App\Services\AiAssistant\Knowledge;

use App\Services\AiAssistant\TatawwurSettings;
use App\Services\Search\TextFold;

/**
 * E45 - استرجاع معرفة معجمي مُوزَّن (لا بحث متجهي ولا دلالي: لا ادّعاء بغير ذلك). يطوي السؤال بـTextFold نفسه، يحذف كلمات الاستفهام والوصل،
 * ثم يعطي كل مقطع درجة: العنوان 6، الوسوم 5، عنوان القسم 3، النص 0.75 لكل ورود (حد 3)، + مكافأة تغطية حين تتطابق كلمتان فأكثر.
 * أدنى درجة اعتماد من الإعداد (retrieval.min_score)؛ وأقصى مقطعين للمقال الواحد لتنويع المصادر. الواجهة مستقرة ليُضاف لاحقًا استرجاع متجهي بلا تغيير المستهلكين.
 *
 * يرى الاسترجاع المقالات المنشورة وحدها (انظر KnowledgeIndex)، ومقالات «member» لا تُرجَع للضيف.
 *
 * دقة (منع الاستدلال بالضجيج): كلمات المنصة العامة (منصة/أحجيات) تظهر في عناوين كل المقالات تقريبًا، فلا تُحتسب وحدها دليلًا حين يحمل السؤال موضوعًا محددًا:
 * إن وُجدت في السؤال كلمة محددة فلا يُعتمد مقطع لم تطابق أيٌّ من الكلمات المحددة فيه (عندها يُجاب بالامتناع لا بمقطع عام). كما تُطابَق صيغ الجمع والتاء المربوطة وأفعال المتكلم
 * الشائعة (أصوّت/أحلّ/أشتري) وبعض المرادفات القليلة والثابتة (مسابقة⇐منافسة، نجمة⇐نجوم، كاتب⇐كتّاب) بجدول مغلق صغير؛ لا تعلّم آلي ولا استدلال دلالي.
 */
final class AiKnowledgeRetriever
{
    /** كلمات استفهام/وصل شائعة بعد الطي (فتسقط من المطابقة). */
    private const STOPWORDS = [
        'كيف', 'ما', 'ماذا', 'هل', 'لماذا', 'اين', 'متي', 'كم', 'اي', 'من', 'في', 'علي', 'الي', 'عن', 'مع', 'بين', 'قبل', 'بعد', 'هو', 'هي', 'هذا', 'هذه', 'ذلك', 'تلك',
        'هناك', 'هنا', 'التي', 'الذي', 'الذين', 'ان', 'اذا', 'او', 'ثم', 'لا', 'لم', 'لن', 'انا', 'لي', 'لدي', 'عندي', 'كل', 'يمكن', 'يمكنني', 'اريد', 'اعرف', 'اشرح', 'ايضا', 'فقط', 'به', 'بها', 'له', 'لها', 'منه', 'منها', 'ليس',
        'يا', 'لو', 'قد', 'كان', 'يكون', 'تطور', 'ساعدني', 'اخبرني', 'قل', 'لي', 'the', 'is', 'are', 'what', 'how', 'do', 'does', 'to', 'of', 'a', 'an', 'in', 'on', 'for', 'and', 'or',
    ];

    /** جذور كلمات المنصة العامة بعد الطي والتجذيع الخفيف. */
    private const GENERIC_STEMS = ['منص', 'احجي', 'ahjiyat'];

    /**
     * مرادفات مغلقة (مفتاح = جذع الكلمة بعد الطي؛ القيم = صيغ إضافية تُطابَق في الفهرس). الأصل يُطابَق دائمًا.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'اصوت' => ['تصويت', 'صوت'], 'صوت' => ['تصويت'], 'يصوت' => ['تصويت', 'صوت'], 'تصويت' => ['صوت'], 'نصوت' => ['تصويت', 'صوت'],
        'كاتب' => ['كتاب'], 'كتاب' => ['كاتب'],
        'مسابق' => ['منافس'], 'مسابقه' => ['منافس'], 'منافس' => ['مسابق'],
        'نجم' => ['نجوم'], 'نجمه' => ['نجوم'],
        'اشتري' => ['شراء', 'متجر'], 'اشتر' => ['شراء', 'متجر'], 'يشتري' => ['شراء', 'متجر'], 'شراء' => ['متجر'],
        'احل' => ['حل'], 'يحل' => ['حل'], 'احلها' => ['حل'],
        'ترتيب' => ['صداره'], 'ارتب' => ['ترتيب'],
        'اسجل' => ['تسجيل'], 'سجل' => ['تسجيل'],
        'اشارك' => ['مشاركه', 'انضمام'], 'انضم' => ['انضمام'], 'اشترك' => ['اشتراك', 'انضمام'],
        'اصبح' => ['طلب'], 'اطلب' => ['طلب'],
        'استبدل' => ['استبدال'], 'اسحب' => ['سحب', 'استبدال'], 'اصرف' => ['استبدال'], 'استبدالها' => ['استبدال'],
    ];

    public function __construct(protected KnowledgeIndex $index) {}

    /**
     * @param  ?string  $previous  نص آخر سؤال سابق للمستخدم (متابعات مثل «وكم سعره؟»): يُدمج فقط حين يقل عدد كلمات السؤال الحالي عن 3.
     * @return list<array{chunk_id: int, article_id: int, slug: string, title: string, area: string, section: ?string, text: string, url: ?string, label: string, score: float}>
     */
    public function retrieve(string $query, bool $member, ?int $limit = null, ?string $previous = null): array
    {
        $limit ??= TatawwurSettings::contextChunks();
        $terms = self::terms($query);

        if (count($terms) < 3 && $previous !== null && trim($previous) !== '') {
            $terms = array_values(array_unique(array_merge($terms, self::terms($previous))));
        }

        $terms = array_slice($terms, 0, max(1, (int) config('tatawwur_ai.retrieval.max_query_terms', 12)));

        if ($terms === []) {
            return [];
        }

        $min = TatawwurSettings::minRetrievalScore();
        $scored = [];

        $plan = $this->plan($terms);

        foreach ($this->index->entries($member) as $entry) {
            $score = $this->score($entry, $plan);

            if ($score >= $min) {
                $scored[] = $entry + ['score' => round($score, 3)];
            }
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['chunk_id']] <=> [$a['score'], $b['chunk_id']]);

        $perArticle = [];
        $out = [];

        foreach ($scored as $row) {
            if (($perArticle[$row['article_id']] ?? 0) >= 2) {
                continue;
            }

            $perArticle[$row['article_id']] = ($perArticle[$row['article_id']] ?? 0) + 1;
            $row['text'] = mb_substr($row['text'], 0, TatawwurSettings::maxChunkChars());
            unset($row['f_title'], $row['f_tags'], $row['f_section'], $row['f_body']);
            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * كلمات السؤال المفيدة (مطوية، بلا أل التعريف/الحروف الملصقة، بلا كلمات الاستفهام والوصل).
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $folded = TextFold::fold($text);
        $terms = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $folded) ?: [] as $token) {
            if ($token === '' || in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $prefix) {
                if (str_starts_with($token, $prefix) && mb_strlen($token) - mb_strlen($prefix) >= 2) {
                    $token = mb_substr($token, mb_strlen($prefix));
                    break;
                }
            }

            if (mb_strlen($token) >= 2 && ! in_array($token, self::STOPWORDS, true)) {
                $terms[$token] = $token;
            }
        }

        return array_values($terms);
    }

    /**
     * خطة المطابقة: لكل كلمة سؤال صيغها (الأصل + الجذع + المرادفات المغلقة) وهل هي كلمة منصة عامة.
     *
     * @param  list<string>  $terms
     * @return list<array{forms: list<string>, generic: bool}>
     */
    private function plan(array $terms): array
    {
        $plan = [];

        foreach ($terms as $term) {
            $stem = self::stem($term);
            $forms = [$term];

            if ($stem !== $term && mb_strlen($stem) >= 4) {   // جذع من 3 أحرف يطابق داخل كلمات غير ذات صلة (سري⇐تسري)
                $forms[] = $stem;
            }

            foreach ([$term, $stem] as $key) {
                foreach (self::ALIASES[$key] ?? [] as $alias) {
                    $forms[] = $alias;
                }
            }

            $plan[] = ['forms' => array_values(array_unique($forms)), 'generic' => in_array($stem, self::GENERIC_STEMS, true) || in_array($term, self::GENERIC_STEMS, true)];
        }

        return $plan;
    }

    /** تجذيع خفيف: جمع سالم/مؤنث، تاء مربوطة، ألف التنوين. */
    public static function stem(string $term): string
    {
        foreach (['ات', 'ون', 'ين'] as $suffix) {
            if (mb_strlen($term) - 2 >= 3 && str_ends_with($term, $suffix)) {
                $term = mb_substr($term, 0, -2);

                break;
            }
        }

        if (mb_strlen($term) >= 4 && (str_ends_with($term, 'ه') || str_ends_with($term, 'ا'))) {
            $term = mb_substr($term, 0, -1);
        } elseif (mb_strlen($term) >= 6 && str_ends_with($term, 'ي')) {
            $term = mb_substr($term, 0, -1);   // ياء المتكلم الملصقة (جواهري ⇐ جواهر)
        }

        return $term;
    }

    /** @param array<string, mixed> $entry @param list<array{forms: list<string>, generic: bool}> $plan */
    private function score(array $entry, array $plan): float
    {
        $specific = 0.0;
        $generic = 0.0;
        $matched = 0;
        $specificTerms = 0;
        $specificMatched = 0;

        foreach ($plan as $item) {
            $best = 0.0;

            foreach ($item['forms'] as $form) {
                $best = max($best, $this->formScore($entry, $form));
            }

            if ($item['generic']) {
                $generic += $best;
            } else {
                $specificTerms++;
                $specificMatched += $best > 0 ? 1 : 0;
                $specific += $best;
            }

            $matched += $best > 0 ? 1 : 0;
        }

        if ($specificTerms > 0 && $specific <= 0.0) {
            return 0.0;   // كلمات المنصة العامة وحدها ليست دليلًا على موضوع السؤال المحدد.
        }

        if ($specificTerms >= 3 && $specificMatched < 2) {
            return 0.0;   // سؤال بثلاث كلمات محددة فأكثر لا يكفيه تطابق كلمة واحدة عَرَضيّة (سرية⇐كلمة المرور سرية).
        }

        $score = $specific + $generic;

        if ($matched >= 2) {
            $score += 2 * ($matched / count($plan));
        }

        return $score;
    }

    /** @param array<string, mixed> $entry */
    private function formScore(array $entry, string $term): float
    {
        $score = 0.0;

        if ($this->has($entry['f_title'], $term)) {
            $score += 6;
        }

        if ($this->has($entry['f_tags'], $term)) {
            $score += 5;
        }

        if ($this->has($entry['f_section'], $term)) {
            $score += 3;
        }

        $count = $this->occurrences($entry['f_body'], $term);

        if ($count > 0) {
            $score += 0.75 * min(3, $count);
        }

        return $score;
    }

    private function has(string $haystack, string $term): bool
    {
        return $haystack !== '' && (mb_strlen($term) >= 3 ? str_contains($haystack, $term) : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1);
    }

    private function occurrences(string $haystack, string $term): int
    {
        if ($haystack === '') {
            return 0;
        }

        return mb_strlen($term) >= 3 ? substr_count($haystack, $term) : preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $haystack);
    }
}
