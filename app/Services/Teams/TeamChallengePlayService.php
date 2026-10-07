<?php

namespace App\Services\Teams;

use App\GameEngine\Support\AttemptContext;
use App\Models\GameSession;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\User;
use App\Services\Competitive\CompetitiveException;
use App\Services\Competitive\CompetitiveOutcome;
use App\Services\Competitive\CompetitiveRunService;
use Illuminate\Support\Facades\DB;

/**
 * لعب لاعب الروستر (E20-B14..B21): **لا منطق نقاط هنا** — يعيد استعمال CompetitiveRunService (E17) بسياق team_challenge: محاولة واحدة لكل لاعب، مدتها وصحتها ودرجتها من الخادم،
 * وأحجية المحاولة هي أحجية التحدّي فقط (finish يرفض غيرها). **الفريق يُستخرج من مقعد الروستر المقفل** لا من الطلب، والعضوية الحالية لا تُقرأ (من غادر بعد القفل يبقى ممثّلًا لفريقه).
 * النتيجة تُكتب مرة واحدة على مقعد اللاعب بتحديث شرطي (completed_at IS NULL). لا محاولات قبل القبول (الجلسة تُنشأ ببدء التحدّي ومربوطة بمعرّفه). لا اقتصاد.
 */
class TeamChallengePlayService
{
    public function __construct(protected CompetitiveRunService $runs, protected TeamChallengeFinalizer $finalizer) {}

    public function participantFor(User $user, TeamChallenge $challenge): ?TeamChallengeParticipant
    {
        return TeamChallengeParticipant::query()->where('team_challenge_id', $challenge->getKey())->where('user_id', $user->getKey())->whereNotNull('locked_at')->first();
    }

    public function start(User $actor, TeamChallenge $challenge): GameSession
    {
        $this->assertPlayable($actor, $challenge);

        // الأحجية مثبّتة منذ الإنشاء: تعطيلها لاحقًا لا يُسقط المباراة (الأهلية التنافسية تبقى مفحوصة).
        return $this->runs->start($actor, $challenge->puzzle, AttemptContext::teamChallenge($challenge->getKey()), requireActivePuzzle: false);
    }

    public function activeRun(User $actor, TeamChallenge $challenge): ?GameSession
    {
        return $this->runs->activeSession($actor, AttemptContext::teamChallenge($challenge->getKey()));
    }

    public function submit(User $actor, TeamChallenge $challenge, array $input): CompetitiveOutcome
    {
        $participant = $this->assertPlayable($actor, $challenge);
        $context = AttemptContext::teamChallenge($challenge->getKey());
        $session = $this->runs->activeSession($actor, $context) ?? throw new CompetitiveException('ابدأ المحاولة أولًا.');

        $outcome = DB::transaction(function () use ($actor, $challenge, $participant, $input, $context, $session) {
            $locked = TeamChallenge::query()->lockForUpdate()->findOrFail($challenge->getKey());

            if ($locked->status !== TeamChallenge::STATUS_ACCEPTED || ! $locked->isPlayable()) {
                throw new CompetitiveException('انتهت مهلة اللعب بهذا التحدّي.');
            }

            $outcome = $this->runs->finish($actor, $session, $locked->puzzle, $context, $input);

            $recorded = TeamChallengeParticipant::query()->whereKey($participant->getKey())->whereNull('completed_at')->update([
                'status' => TeamChallengeParticipant::STATUS_PLAYED, 'game_session_id' => $outcome->session->getKey(), 'is_correct' => $outcome->correct,
                'score' => $outcome->score, 'duration_ms' => $outcome->durationMs, 'completed_at' => now(),
            ]);

            if ($recorded !== 1) {
                throw new CompetitiveException('سُجّلت نتيجتك بالفعل.');   // يتراجع معه إنهاء الجلسة
            }

            return $outcome;
        });

        // اكتمل الروستران كاملين: اعتماد مبكر (حتمي ومتماثل مع الاعتماد عند انتهاء المهلة). فشله لا يمسّ النتيجة المسجَّلة.
        try {
            $this->finalizer->finalize($challenge->refresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return $outcome;
    }

    protected function assertPlayable(User $actor, TeamChallenge $challenge): TeamChallengeParticipant
    {
        $challenge->refresh();    // الحالة والمهلة طازجتان من القاعدة: نسخة قديمة ممرَّرة لا تُعتمد (لا بدء على تحدٍّ انتهى ولا رفض لتحدٍّ بدأ)

        $participant = $this->participantFor($actor, $challenge) ?? throw new CompetitiveException('لست ضمن روستر هذا التحدّي.');

        if ($actor->is_frozen === true) {
            throw new CompetitiveException('حسابك غير مؤهَّل للعب حاليًا.');
        }

        if (! $challenge->isPlayable()) {
            throw new CompetitiveException('هذا التحدّي غير متاح للعب.');
        }

        if ($participant->completed_at !== null) {
            throw new CompetitiveException('أنهيت محاولتك الوحيدة هنا.');
        }

        return $participant;
    }
}
