<?php

namespace App\Services\Teams;

use App\Events\TeamChallengeAccepted;
use App\Events\TeamChallengeCreated;
use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChallengeParticipant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Competitive\CompetitiveEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تحدّي فريق ضد فريق (E20-A/B). كل هوية/دور من **قاعدة البيانات** (دور المستخدم الفعلي بفريقه)، لا من الطلب: الفريق المتحدّي = فريق المنفِّذ، والخصم يُحدَّد بمعرّفه العام (slug).
 * - الإنشاء: مالك/مشرف فقط، فريقان **مفعَّلان** مختلفان، أحجية تنافسية صالحة (قواعد E17 نفسها: بلا تلميح...)، وروستر مختار من أعضاء الفريق (حدّان أدنى/أقصى).
 * - **القبول يقفل الروستر**: مالك/مشرف الفريق الهدف فقط، يختار روستره ويُعاد فحص روستر المتحدّي (أعضاء فعليون غير مجمَّدين)، ثم بمعاملة واحدة بقفل صف التحدّي: إدراج مقاعد الخصم،
 *   قفل كل المقاعد (locked_at + لقطة الدور)، وبدء مهلة اللعب. بعد القفل: لا إضافة ولا حذف ولا تبديل (حارس النموذج). قبول مرتين: الثاني يُرفض.
 * - تحدّيان نشطان لنفس الفريقين والأحجية (A→B وB→A) مستحيلان بقيد UNIQUE(active_key).
 * - الرفض والإلغاء والانتهاء بلا إشعار. **لا مكافآت**: لا اقتصاد هنا. الحظر بين مستخدمين لا يمنع تنافس فريقين (الكيان فريق↔فريق، لا تفاعل شخصي).
 */
class TeamChallengeService
{
    public function __construct(protected TeamMembershipService $members, protected CompetitiveEligibility $eligibility) {}

    public function managerMembership(User $actor): TeamMembership
    {
        $m = $this->members->membershipOf($actor);

        if ($m === null || ! $m->isManager()) {
            throw new TeamException('تحدّيات الفرق لمالك الفريق أو مشرفيه فقط.');
        }

        return $m;
    }

    /** @param  list<string>  $rosterPublicIds  معرّفات اللاعبين العامة (ULID) المختارين من أعضاء فريقك */
    public function create(User $actor, Team $opponent, Puzzle $puzzle, array $rosterPublicIds): TeamChallenge
    {
        $mine = $this->managerMembership($actor)->team;

        if ($mine->is($opponent)) {
            throw new TeamException('لا يمكن لفريق أن يتحدّى نفسه.');
        }

        $this->assertActive($mine);
        $this->assertActive($opponent);
        $this->assertPuzzle($puzzle);
        $roster = $this->resolveRoster($mine, $rosterPublicIds);

        try {
            return DB::transaction(function () use ($actor, $mine, $opponent, $puzzle, $roster) {
                $challenge = new TeamChallenge(['challenger_team_id' => $mine->getKey(), 'opponent_team_id' => $opponent->getKey(), 'created_by_user_id' => $actor->getKey(), 'puzzle_id' => $puzzle->getKey(),
                    'expires_at' => now()->addHours(max(1, (int) config('teams.challenges.acceptance_hours', 72)))]);
                $challenge->forceFill(['status' => TeamChallenge::STATUS_PENDING, 'active_key' => TeamChallenge::activeKey($mine->getKey(), $opponent->getKey(), $puzzle->getKey())])->save();

                $this->insertRoster($challenge, $mine, $roster);
                $this->afterCommit(fn () => event(new TeamChallengeCreated($challenge->getKey())));

                return $challenge;
            });
        } catch (UniqueConstraintViolationException) {
            throw new TeamException('يوجد تحدٍّ نشط بين الفريقين على هذه الأحجية بالفعل.');
        }
    }

    /** تعديل روستر الفريق المتحدّي أثناء كون التحدّي معلّقًا فقط (قبل القفل). */
    public function setRoster(User $actor, TeamChallenge $challenge, array $rosterPublicIds): TeamChallenge
    {
        $mine = $this->managerMembership($actor)->team;

        if ($challenge->challenger_team_id !== $mine->getKey()) {
            throw new TeamException('روستر الفريق المتحدّي يعدّله مشرفوه فقط.');
        }

        $roster = $this->resolveRoster($mine, $rosterPublicIds);

        return DB::transaction(function () use ($challenge, $mine, $roster) {
            $locked = TeamChallenge::query()->lockForUpdate()->findOrFail($challenge->getKey());

            if ($locked->status !== TeamChallenge::STATUS_PENDING || $locked->expires_at->lessThanOrEqualTo(now())) {
                throw new TeamException('الروستر مقفل أو التحدّي لم يعد معلّقًا.');
            }

            foreach (TeamChallengeParticipant::query()->where('team_challenge_id', $locked->getKey())->where('team_id', $mine->getKey())->get() as $old) {
                $old->delete();
            }

            $this->insertRoster($locked, $mine, $roster);

            return $locked;
        });
    }

    public function accept(User $actor, TeamChallenge $challenge, array $rosterPublicIds): TeamChallenge
    {
        $mine = $this->managerMembership($actor)->team;

        if ($challenge->opponent_team_id !== $mine->getKey()) {
            throw new TeamException('قبول التحدّي لمالك الفريق المستهدف أو مشرفيه فقط.');
        }

        $opponentRoster = $this->resolveRoster($mine, $rosterPublicIds);

        return DB::transaction(function () use ($challenge, $mine, $opponentRoster) {
            $locked = TeamChallenge::query()->lockForUpdate()->findOrFail($challenge->getKey());

            if ($locked->status !== TeamChallenge::STATUS_PENDING) {
                throw new TeamException('لم يعد هذا التحدّي معلّقًا.');
            }

            if ($locked->expires_at->lessThanOrEqualTo(now())) {
                throw new TeamException('انتهت مهلة قبول هذا التحدّي.');
            }

            $challenger = Team::query()->findOrFail($locked->challenger_team_id);
            $this->assertActive($challenger);
            $this->assertActive($mine);
            $this->assertPuzzle($locked->puzzle);

            // روستر المتحدّي: أعضاء فعليون الآن وغير مجمَّدين وبالحدّ الأدنى، وإلا يُطلَب منه تحديثه (لا حذف صامت).
            $theirs = TeamChallengeParticipant::query()->where('team_challenge_id', $locked->getKey())->where('team_id', $challenger->getKey())->pluck('user_id');
            $valid = TeamMembership::query()->where('team_id', $challenger->getKey())->whereIn('user_id', $theirs)
                ->whereHas('user', fn ($q) => $q->whereNotNull('email_verified_at')->where('is_frozen', false))->count();

            if ($theirs->count() < (int) config('teams.challenges.roster_min', 1) || $valid !== $theirs->count()) {
                throw new TeamException('روستر الفريق المتحدّي لم يعد صالحًا: على فريقه تحديثه قبل القبول.');
            }

            $this->insertRoster($locked, $mine, $opponentRoster);
            $this->lockRosters($locked);

            $locked->forceFill(['status' => TeamChallenge::STATUS_ACCEPTED, 'accepted_at' => now(), 'play_ends_at' => now()->addHours(max(1, (int) config('teams.challenges.play_hours', 48)))])->save();
            $this->afterCommit(fn () => event(new TeamChallengeAccepted($locked->getKey())));

            return $locked;
        });
    }

    public function decline(User $actor, TeamChallenge $challenge): TeamChallenge
    {
        return $this->closePending($actor, $challenge, opponentSide: true, to: TeamChallenge::STATUS_DECLINED); // بلا إشعار للطرف الآخر
    }

    public function cancel(User $actor, TeamChallenge $challenge): TeamChallenge
    {
        return $this->closePending($actor, $challenge, opponentSide: false, to: TeamChallenge::STATUS_CANCELLED);
    }

    /** يجسّد انتهاء المعلّقة التي تجاوزت مهلتها ويحرّر مفتاحها. Idempotent. @return int */
    public function expirePending(): int
    {
        return TeamChallenge::query()->where('status', TeamChallenge::STATUS_PENDING)->where('expires_at', '<=', now())
            ->update(['status' => TeamChallenge::STATUS_EXPIRED, 'active_key' => null]);
    }

    protected function closePending(User $actor, TeamChallenge $challenge, bool $opponentSide, string $to): TeamChallenge
    {
        $mine = $this->managerMembership($actor)->team;
        $expected = $opponentSide ? $challenge->opponent_team_id : $challenge->challenger_team_id;

        if ($expected !== $mine->getKey()) {
            throw new TeamException($opponentSide ? 'رفض التحدّي للفريق المستهدف فقط.' : 'إلغاء التحدّي للفريق المتحدّي فقط.');
        }

        $affected = TeamChallenge::query()->whereKey($challenge->getKey())->where('status', TeamChallenge::STATUS_PENDING)->update(['status' => $to, 'active_key' => null]);

        if ($affected === 0) {
            throw new TeamException('لم يعد هذا التحدّي معلّقًا.');
        }

        return $challenge->refresh();
    }

    /** روستر من أعضاء الفريق الفعليين (غير مجمَّدين وموثَّقين)، بلا تكرار، بحدّي الحجم. @return Collection<int, TeamMembership> */
    protected function resolveRoster(Team $team, array $publicIds): Collection
    {
        $ids = array_values(array_filter(array_map('strval', $publicIds)));
        $min = (int) config('teams.challenges.roster_min', 1);
        $max = (int) config('teams.challenges.roster_max', 5);

        if (count($ids) !== count(array_unique($ids))) {
            throw new TeamException('لا يمكن تكرار لاعب بالروستر.');
        }

        if (count($ids) < $min || count($ids) > $max) {
            throw new TeamException("حجم الروستر بين {$min} و{$max} لاعبين.");
        }

        $memberships = TeamMembership::query()->where('team_id', $team->getKey())
            ->whereHas('user', fn ($q) => $q->whereIn('public_id', $ids)->whereNotNull('email_verified_at')->where('is_frozen', false))->with('user:id,public_id')->get();

        if ($memberships->count() !== count($ids)) {
            throw new TeamException('كل لاعب بالروستر يجب أن يكون عضوًا فعليًا بفريقك وحسابه نشط.');
        }

        return $memberships;
    }

    /** يدرج مقاعد فريق واحد (التحدّي معلّق: الحارس يشترط ذلك). لقطة الفريق والدور من العضوية وقت الاختيار. */
    protected function insertRoster(TeamChallenge $challenge, Team $team, Collection $memberships): void
    {
        foreach ($memberships as $m) {
            TeamChallengeParticipant::create(['team_challenge_id' => $challenge->getKey(), 'team_id' => $team->getKey(), 'user_id' => $m->user_id, 'role_snapshot' => $m->role])
                ->forceFill(['status' => TeamChallengeParticipant::STATUS_SELECTED])->save();
        }
    }

    /** القفل: كل المقاعد locked_at الآن بلقطة الدور الحالي. بعده لا يُعدَّل شيء. */
    protected function lockRosters(TeamChallenge $challenge): void
    {
        foreach (TeamChallengeParticipant::query()->where('team_challenge_id', $challenge->getKey())->get() as $p) {
            $role = TeamMembership::query()->where('team_id', $p->team_id)->where('user_id', $p->user_id)->value('role') ?? $p->role_snapshot;

            TeamChallengeParticipant::query()->whereKey($p->getKey())->update(['status' => TeamChallengeParticipant::STATUS_LOCKED, 'locked_at' => now(), 'role_snapshot' => $role]);
        }
    }

    protected function assertActive(Team $team): void
    {
        if (! Team::query()->whereKey($team->getKey())->where('is_active', true)->exists()) {
            throw new TeamException('كلا الفريقين يجب أن يكونا مفعَّلين.');
        }
    }

    protected function assertPuzzle(Puzzle $puzzle): void
    {
        if (($reason = $this->eligibility->reasonIfIneligible($puzzle)) !== null) {
            throw new TeamException($reason);
        }
    }

    protected function afterCommit(\Closure $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e); // الإشعارات ثانوية: فشلها لا يمسّ التحدّي
            }
        });
    }
}
