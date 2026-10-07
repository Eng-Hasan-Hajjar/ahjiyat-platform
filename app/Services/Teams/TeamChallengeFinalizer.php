<?php

namespace App\Services\Teams;

use App\Events\TeamChallengeCompleted;
use App\GameEngine\Support\AttemptContext;
use App\Models\GameSession;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Services\Competitive\CompetitiveRunService;
use Illuminate\Support\Facades\DB;

/**
 * اعتماد نتيجة المباراة (E20-C). **الخادم يحسب كل شيء** من مقاعد الروستر المقفل (لا العضوية الحالية، ولا شيء من العميل): نقاط كل فريق بـ**الصيغة المشتركة نفسها** (TeamScoreCalculator:
 * مجموع أفضل N نتيجة صحيحة) لكلا الفريقين، والفائز بنقاط أعلى ثم مدة أقل ثم أعضاء أكثر، وإلا **تعادل حقيقي** (winner_team_id = NULL). فريق بأقل من min_valid_results نتائج صحيحة
 * يُحتسب بلا نقاط (لا درجات وهمية). يُعتمد عند انتهاء مهلة اللعب أو حين يكمل كل المقاعد (مبكرًا، بنفس النتيجة). Idempotent: النتيجة تُكتب مرة واحدة (UNIQUE) والحالة تنتقل مرة.
 * **لا اقتصاد**: لا عملة ولا XP ولا عناصر ولا مكافآت E18؛ تعرُّف فقط.
 */
class TeamChallengeFinalizer
{
    public function __construct(protected TeamScoreCalculator $calculator, protected CompetitiveRunService $runs) {}

    /** @return bool هل اعتُمد الآن (false: غير مستحق، أو معتمَد سابقًا) */
    public function finalize(TeamChallenge $challenge): bool
    {
        return DB::transaction(function () use ($challenge) {
            $locked = TeamChallenge::query()->lockForUpdate()->find($challenge->getKey());

            if ($locked === null || $locked->status !== TeamChallenge::STATUS_ACCEPTED) {
                return false;
            }

            $open = TeamChallengeParticipant::query()->where('team_challenge_id', $locked->getKey())->whereNull('completed_at')->count();

            if ($open > 0 && $locked->play_ends_at->greaterThan(now())) {
                return false;                                    // لم تنتهِ المهلة ولم يكتمل الروستران
            }

            $this->closeMissing($locked);

            $rows = TeamChallengeParticipant::query()->where('team_challenge_id', $locked->getKey())->where('is_correct', true)->whereNotNull('completed_at')
                ->orderBy('team_id')->orderByDesc('score')->orderBy('duration_ms')->orderBy('completed_at')->orderBy('id')
                ->select('team_id', 'score', 'duration_ms as duration')->get();

            $teams = $this->calculator->aggregate($rows);
            $min = (int) config('teams.challenges.min_valid_results', 1);
            $a = $this->side($teams, $locked->challenger_team_id, $min);
            $b = $this->side($teams, $locked->opponent_team_id, $min);

            $cmp = $this->calculator->headToHead($a, $b);
            $winner = $cmp < 0 ? $a['team_id'] : ($cmp > 0 ? $b['team_id'] : null);

            foreach ([$a, $b] as $side) {
                DB::table('team_challenge_results')->insertOrIgnore([[
                    'team_challenge_id' => $locked->getKey(), 'team_id' => $side['team_id'], 'score' => $side['score'], 'eligible_results_count' => $side['counted'],
                    'total_duration_ms' => $side['duration'], 'rank' => $winner === null || $winner === $side['team_id'] ? 1 : 2, 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]]);
            }

            $locked->forceFill(['status' => TeamChallenge::STATUS_COMPLETED, 'completed_at' => now(), 'winner_team_id' => $winner, 'is_draw' => $winner === null, 'active_key' => null])->save();

            DB::afterCommit(function () use ($locked) {
                try {
                    event(new TeamChallengeCompleted($locked->getKey()));
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return true;
        });
    }

    /** مجموع الفريق (أو صفر): أقل من الحد الأدنى للنتائج الصحيحة = بلا نقاط. */
    protected function side(array $teams, int $teamId, int $min): array
    {
        $side = $teams[$teamId] ?? ['team_id' => $teamId, 'score' => 0, 'counted' => 0, 'duration' => 0, 'rank' => 0];

        return $side['counted'] < $min ? ['team_id' => $teamId, 'score' => 0, 'counted' => 0, 'duration' => 0, 'rank' => 0] : $side;
    }

    /** من لم يرسل: مقعده "missed"، وجلسته المفتوحة تُنهى (لا استئناف بعد الاعتماد). */
    protected function closeMissing(TeamChallenge $challenge): void
    {
        foreach (TeamChallengeParticipant::query()->where('team_challenge_id', $challenge->getKey())->whereNull('completed_at')->get() as $p) {
            $session = GameSession::query()->where('user_id', $p->user_id)->where('context_type', AttemptContext::TYPE_TEAM_CHALLENGE)->where('context_id', $challenge->getKey())->first();

            if ($session !== null) {
                $this->runs->expire($session);
            }

            TeamChallengeParticipant::query()->whereKey($p->getKey())->update(['status' => TeamChallengeParticipant::STATUS_MISSED]);
        }
    }
}
