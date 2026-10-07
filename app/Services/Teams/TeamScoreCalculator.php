<?php

namespace App\Services\Teams;

/**
 * **الصيغة الوحيدة لنقاط الفريق** (E19 + E20): مجموع أفضل N نتيجة صحيحة لأعضاء الفريق (N = teams.ranking_top_n)، فلا أفضلية للحجم الأكبر. تُستعمل بنفس الكود لترتيب الفرق
 * بالأحداث (TeamCompetitiveRankingService) ولمباريات الفرق (TeamChallengeFinalizer): لا نسخ ولا صيغة ثانية. فريق بأقل من N نتائج يُجمع له ما لديه (لا درجات وهمية).
 * ترتيب الأفراد/الفرق: نقاط أعلى ← مجموع مدد أقل ← أعضاء محتسَبون أكثر ← (للترتيب المتسلسل فقط) معرّف فريق أصغر. المباراة الثنائية لا تستعمل معرّف الفريق: تعادل حقيقي بدل فائز تعسفي.
 */
class TeamScoreCalculator
{
    public function topN(): int
    {
        return max(1, (int) config('teams.ranking_top_n', 3));
    }

    /**
     * يجمع صفوفًا مرتّبة (فريق، ثم درجة تنازليًا، ثم مدة تصاعديًا) إلى مجموع أفضل N لكل فريق.
     *
     * @param  iterable<object>  $rows  كائنات بحقول team_id وscore وduration
     * @return array<int, array{team_id: int, score: int, counted: int, duration: int, rank: int}>
     */
    public function aggregate(iterable $rows): array
    {
        $topN = $this->topN();
        $teams = [];

        foreach ($rows as $row) {
            $t = &$teams[$row->team_id];
            $t ??= ['team_id' => (int) $row->team_id, 'score' => 0, 'counted' => 0, 'duration' => 0, 'rank' => 0];

            if ($t['counted'] < $topN) {
                $t['score'] += (int) $row->score;
                $t['duration'] += (int) $row->duration;
                $t['counted']++;
            }

            unset($t);
        }

        return $teams;
    }

    /** ترتيب متسلسل حتمي (1..K) بالأولوية أعلاه ومعرّف الفريق الأصغر أخيرًا. */
    public function ranked(array $teams): array
    {
        $list = array_values($teams);
        usort($list, fn ($a, $b) => [$b['score'], $a['duration'], $b['counted'], $a['team_id']] <=> [$a['score'], $b['duration'], $a['counted'], $b['team_id']]);

        foreach ($list as $i => &$row) {
            $row['rank'] = $i + 1;
        }

        return $list;
    }

    /** مواجهة ثنائية: سالب = الأول يفوز، موجب = الثاني، صفر = تعادل حقيقي (نقاط، ثم مدة أقل، ثم أعضاء أكثر؛ لا فائز عشوائي ولا بمعرّف). */
    public function headToHead(array $a, array $b): int
    {
        return [$b['score'], $a['duration'], $b['counted']] <=> [$a['score'], $b['duration'], $a['counted']];
    }
}
