<?php

namespace App\Services\Reputation;

use App\Models\CommunityReputationSnapshot;

/**
 * E37 - شرح مفهوم لدرجات مستخدم من لقطته (للإدارة، ويصلح لاحقًا لشاشة المبدع نفسه): لكل بُعد النسبة (0..100%) ومساهمة مكوّناته ونقص الأدلة الذي يمنع الأهلية.
 * قراءة فقط من اللقطة المخزَّنة (لا استعلام سلطة) فيطابق ما تعرضه الترتيبات. لا يعرض أعلام احتيال ولا سجل إشراف ولا أي إشارة مالية (غير موجودة بالصيغة أصلًا).
 */
class ReputationExplainer
{
    public function __construct(protected ReputationSettings $settings) {}

    /** @return array<string, mixed>|null null إن لم تكن للمستخدم لقطة */
    public function forSnapshot(?CommunityReputationSnapshot $s): ?array
    {
        if ($s === null) {
            return null;
        }

        $pct = fn (int $score) => round($score * 100 / $this->settings->scale(), 1);

        $creatorWeights = $this->settings->group('creator.weights');

        return [
            'calculated_at' => $s->calculated_at?->toDateTimeString(),
            'formula_version' => (int) $s->formula_version,
            'current_formula' => $this->settings->formulaVersion(),
            'creator' => [
                'score' => $pct((int) $s->creator_score),
                'eligible' => (bool) $s->creator_eligible,
                'components' => [
                    ['label' => 'جودة التقييم (بايزي)', 'pct' => $pct((int) $s->creator_rating_score), 'weight' => $creatorWeights['rating'] ?? 0],
                    ['label' => 'تفاعل اللاعبين (مفضلات/لاعبون/تقييمات)', 'pct' => $pct((int) $s->creator_engagement_score), 'weight' => $creatorWeights['engagement'] ?? 0],
                    ['label' => 'الاستمرارية (أعمال مقيَّمة/مدى/حداثة/سلاسل)', 'pct' => $pct((int) $s->creator_consistency_score), 'weight' => $creatorWeights['consistency'] ?? 0],
                    ['label' => 'الوصول (متابعون)', 'pct' => $pct((int) $s->creator_reach_score), 'weight' => $creatorWeights['reach'] ?? 0],
                ],
                'gaps' => $this->creatorGaps($s),
            ],
            'rising' => ['score' => $pct((int) $s->rising_score), 'eligible' => (bool) $s->rising_eligible, 'gaps' => $this->risingGaps($s)],
            'curator' => ['score' => $pct((int) $s->curator_score), 'eligible' => (bool) $s->curator_eligible, 'gaps' => $this->curatorGaps($s)],
            'helpful' => ['score' => $pct((int) $s->helpfulness_score), 'eligible' => (bool) $s->helpful_eligible, 'gaps' => $this->helpfulGaps($s)],
            'sources' => [
                'أعمال ظاهرة' => (int) $s->works_count, 'أعمال بعيّنة تقييم كافية' => (int) $s->rated_works_count, 'سلاسل ظاهرة' => (int) $s->series_count,
                'تقييمات (بلا ذاتي)' => (int) $s->ratings_count, 'مقيِّمون مميَّزون' => (int) $s->raters_count, 'مفضّلات فريدة' => (int) $s->favorites_count,
                'لاعبون فريدون' => (int) $s->players_count, 'متابعون' => (int) $s->followers_count,
                'تقييمات حديثة' => (int) $s->recent_ratings_count, 'مفضّلات حديثة' => (int) $s->recent_favorites_count, 'لاعبون حديثون' => (int) $s->recent_players_count, 'متابعون جدد' => (int) $s->recent_followers_count,
                'مجموعات عامة مُدرَجة' => (int) $s->curator_collections_count, 'حافظون مميَّزون' => (int) $s->curator_savers_count, 'أعمال بالمجموعات' => (int) $s->curator_items_count,
                'تعليقات حيّة' => (int) $s->helpful_comments_count, 'معجِبون مميَّزون' => (int) $s->helpful_likers_count,
            ],
        ];
    }

    /** @return list<string> */
    protected function creatorGaps(CommunityReputationSnapshot $s): array
    {
        return $this->gaps([
            ['أعمال ظاهرة', (int) $s->works_count, $this->settings->int('creator.eligibility.min_works', 2)],
            ['تقييمات من آخرين', (int) $s->ratings_count, $this->settings->int('creator.eligibility.min_ratings', 8)],
            ['مقيِّمون مختلفون', (int) $s->raters_count, $this->settings->int('creator.eligibility.min_raters', 5)],
        ]);
    }

    /** @return list<string> */
    protected function risingGaps(CommunityReputationSnapshot $s): array
    {
        $gaps = $this->gaps([
            ['أعمال ظاهرة', (int) $s->works_count, $this->settings->int('rising.eligibility.min_works', 1)],
            ['تقييمات خلال النافذة', (int) $s->recent_ratings_count, $this->settings->int('rising.eligibility.min_recent_ratings', 3)],
        ]);

        if (! $s->rising_eligible && (int) $s->recent_ratings_count >= $this->settings->int('rising.eligibility.min_recent_ratings', 3)) {
            $gaps[] = 'جودة التقييمات الحديثة دون الحدّ الأدنى ('.$this->settings->float('rising.eligibility.min_quality', 3.8).').';
        }

        return $gaps;
    }

    /** @return list<string> */
    protected function curatorGaps(CommunityReputationSnapshot $s): array
    {
        return $this->gaps([
            ['مجموعات عامة مُدرَجة', (int) $s->curator_collections_count, $this->settings->int('curator.eligibility.min_collections', 2)],
            ['حافظون مختلفون', (int) $s->curator_savers_count, $this->settings->int('curator.eligibility.min_savers', 5)],
        ]);
    }

    /** @return list<string> */
    protected function helpfulGaps(CommunityReputationSnapshot $s): array
    {
        return $this->gaps([
            ['تعليقات حيّة', (int) $s->helpful_comments_count, $this->settings->int('helpfulness.eligibility.min_comments', 5)],
            ['معجِبون مختلفون', (int) $s->helpful_likers_count, $this->settings->int('helpfulness.eligibility.min_likers', 10)],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $needs
     * @return list<string>
     */
    protected function gaps(array $needs): array
    {
        $out = [];

        foreach ($needs as [$label, $have, $min]) {
            if ($have < $min) {
                $out[] = "{$label}: {$have} من {$min} المطلوبة";
            }
        }

        return $out;
    }
}
