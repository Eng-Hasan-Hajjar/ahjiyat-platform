<?php

namespace App\Services\Competitive;

use App\Events\FriendChallengeAccepted;
use App\Events\FriendChallengeCompleted;
use App\Events\FriendChallengeCreated;
use App\GameEngine\Support\AttemptContext;
use App\Models\FriendChallenge;
use App\Models\FriendChallengeResult;
use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Social\FriendRelation;
use App\Services\Social\FriendshipService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تحدّيات الأصدقاء (E17-A): أحجية واحدة، محاولة واحدة لكل طرف، نتيجة بالسيرفر فقط. الطرف الحالي دائمًا المستخدم المصادَق.
 *
 * - أصدقاء مقبولون فقط وبلا حظر: كل إجراء يعيد فحص ذلك (relationBetween). حظر/إزالة صداقة يلغي النشط فورًا (FriendChallenge::cancelActiveBetween
 *   داخل معاملتَي BlockService/FriendshipService) وأي إجراء لاحق على تحدٍّ لم يعد صالحًا يلغيه ويرفض.
 * - تحدٍّ نشط واحد لكل زوج (active_pair_key UNIQUE). expires_at: 48 ساعة للقبول، ثم 48 ساعة للعب بعد القبول؛ المنتهي مشتق من الوقت
 *   (effectiveStatus) ويُجسَّد بأمر دوري Idempotent.
 * - قبول ذري Idempotent (UPDATE ... WHERE status='pending' AND opponent_id=?). رفض/إلغاء بلا إشعار.
 * - النتيجة: النقاط الأعلى تفوز، تساوي النقاط = تعادل (لا فائز بالقوة). نتيجة الخصم لا تُعرض قبل اكتمال الطرفين (عرض).
 * - لا مكافأة اقتصادية ولا XP ولا أي تأثير على التقدّم: لا اعتماد على أي خدمة مكافآت هنا.
 */
class FriendChallengeService
{
    public function __construct(
        protected FriendshipService $friends,
        protected CompetitiveEligibility $eligibility,
        protected CompetitiveRunService $runs,
    ) {}

    public function ttlHours(): int
    {
        return max(1, (int) config('competitive.challenge_ttl_hours', 48));
    }

    /** أصدقاء مقبولون الآن وبلا حظر بأي اتجاه (relationBetween يفحص الحظر أولًا). */
    public function canChallenge(User $challenger, User $opponent): bool
    {
        return ! $challenger->is($opponent) && $this->friends->relationBetween($challenger, $opponent) === FriendRelation::Friends;
    }

    public function create(User $challenger, User $opponent, Puzzle $puzzle): FriendChallenge
    {
        if ($challenger->is($opponent)) {
            throw new CompetitiveException('لا يمكنك تحدّي نفسك.');
        }

        if (! $this->canChallenge($challenger, $opponent)) {
            throw new CompetitiveException('يمكنك تحدّي أصدقائك فقط.'); // رسالة واحدة لغير الصديق والمحظور (لا نكشف الحظر)
        }

        if (($reason = $this->eligibility->reasonIfIneligible($puzzle)) !== null) {
            throw new CompetitiveException($reason);
        }

        try {
            return DB::transaction(function () use ($challenger, $opponent, $puzzle) {
                $this->expireStaleBetween($challenger->getKey(), $opponent->getKey()); // يحرّر مفتاح تحدٍّ منتهٍ

                $challenge = FriendChallenge::create([
                    'challenger_id' => $challenger->getKey(),
                    'opponent_id' => $opponent->getKey(),
                    'puzzle_id' => $puzzle->getKey(),
                    'status' => FriendChallenge::STATUS_PENDING,
                    'active_pair_key' => FriendChallenge::pairKey($challenger->getKey(), $opponent->getKey()),
                    'expires_at' => now()->addHours($this->ttlHours()),
                ]);

                $this->afterCommit(fn () => event(new FriendChallengeCreated($challenge->getKey())));

                return $challenge;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CompetitiveException('يوجد تحدٍّ نشط بينكما بالفعل.');
        }
    }

    public function accept(User $actor, FriendChallenge $challenge): FriendChallenge
    {
        $this->assertOpponent($actor, $challenge);

        if ($challenge->status === FriendChallenge::STATUS_ACCEPTED && ! $challenge->isExpired()) {
            return $challenge; // إعادة القبول: Idempotent
        }

        $this->assertStillValid($challenge);

        $affected = FriendChallenge::query()->whereKey($challenge->getKey())
            ->where('status', FriendChallenge::STATUS_PENDING)->where('opponent_id', $actor->getKey())->where('expires_at', '>', now())
            ->update(['status' => FriendChallenge::STATUS_ACCEPTED, 'accepted_at' => now(), 'expires_at' => now()->addHours($this->ttlHours())]);

        if ($affected === 1) {
            $this->afterCommit(fn () => event(new FriendChallengeAccepted($challenge->getKey())));
        }

        return $challenge->refresh();
    }

    public function decline(User $actor, FriendChallenge $challenge): FriendChallenge
    {
        $this->assertOpponent($actor, $challenge);

        $affected = FriendChallenge::query()->whereKey($challenge->getKey())
            ->where('status', FriendChallenge::STATUS_PENDING)->where('opponent_id', $actor->getKey())
            ->update(['status' => FriendChallenge::STATUS_DECLINED, 'active_pair_key' => null]); // بلا إشعار

        if ($affected === 0) {
            throw new CompetitiveException('لا يمكن رفض هذا التحدي.');
        }

        return $challenge->refresh();
    }

    public function cancel(User $actor, FriendChallenge $challenge): FriendChallenge
    {
        if ($challenge->challenger_id !== $actor->getKey()) {
            throw new CompetitiveException('لا يمكنك تنفيذ هذا الإجراء.');
        }

        $affected = FriendChallenge::query()->whereKey($challenge->getKey())
            ->where('status', FriendChallenge::STATUS_PENDING)->where('challenger_id', $actor->getKey())
            ->update(['status' => FriendChallenge::STATUS_CANCELLED, 'active_pair_key' => null]);

        if ($affected === 0) {
            throw new CompetitiveException('لا يمكن إلغاء هذا التحدي (المُلغى فقط ما كان قيد الانتظار).');
        }

        return $challenge->refresh();
    }

    public function start(User $actor, FriendChallenge $challenge): GameSession
    {
        $this->assertParticipant($actor, $challenge);
        $this->assertPlayable($actor, $challenge);

        return $this->runs->start($actor, $challenge->puzzle, AttemptContext::friendChallenge($challenge->getKey()));
    }

    /** @param  array<string, mixed>  $input  الإجابة فقط (answer/submission): غيرها يُتجاهل */
    public function submit(User $actor, FriendChallenge $challenge, array $input): CompetitiveOutcome
    {
        $this->assertParticipant($actor, $challenge);
        $this->assertPlayable($actor, $challenge);

        $context = AttemptContext::friendChallenge($challenge->getKey());
        $session = $this->runs->activeSession($actor, $context) ?? throw new CompetitiveException('ابدأ المحاولة أولًا.');

        return DB::transaction(function () use ($actor, $challenge, $input, $context, $session) {
            $locked = FriendChallenge::query()->lockForUpdate()->findOrFail($challenge->getKey());

            if ($locked->status !== FriendChallenge::STATUS_ACCEPTED || $locked->expires_at->lessThanOrEqualTo(now())) {
                throw new CompetitiveException('انتهى هذا التحدي.');
            }

            $outcome = $this->runs->finish($actor, $session, $locked->puzzle, $context, $input);

            try {
                FriendChallengeResult::create([
                    'friend_challenge_id' => $locked->getKey(),
                    'user_id' => $actor->getKey(),
                    'game_session_id' => $outcome->session->getKey(),
                    'is_correct' => $outcome->correct,
                    'duration_ms' => $outcome->durationMs,
                    'score' => $outcome->score,
                    'completed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new CompetitiveException('سُجّلت نتيجتك بالفعل.');
            }

            $this->completeIfBothDone($locked);

            return $outcome;
        });
    }

    /** الجلسة النشطة لهذا الطرف (للصفحة) أو null. لا تُنشئ شيئًا. */
    public function activeRun(User $actor, FriendChallenge $challenge): ?GameSession
    {
        return $this->runs->activeSession($actor, AttemptContext::friendChallenge($challenge->getKey()));
    }

    /** يُجسِّد انتهاء المنتهية بالوقت ويحرّر مفتاحها. Idempotent. @return int عدد ما أُجسِّد */
    public function expireStale(): int
    {
        return FriendChallenge::query()->whereIn('status', FriendChallenge::ACTIVE_STATUSES)->where('expires_at', '<=', now())
            ->update(['status' => FriendChallenge::STATUS_EXPIRED, 'active_pair_key' => null]);
    }

    /** يُلغي النشط الذي لم يعد بين صديقين أو بينهما حظر (شبكة أمان للأمر الدوري). @return int */
    public function cancelInvalid(): int
    {
        $cancelled = 0;

        FriendChallenge::query()->whereIn('status', FriendChallenge::ACTIVE_STATUSES)->with(['challenger', 'opponent'])->chunkById(200, function ($rows) use (&$cancelled) {
            foreach ($rows as $c) {
                if (! $this->canChallenge($c->challenger, $c->opponent)) {
                    $cancelled += FriendChallenge::cancelActiveBetween($c->challenger_id, $c->opponent_id);
                }
            }
        });

        return $cancelled;
    }

    // ---------------------------------------------------------------- داخلي

    protected function expireStaleBetween(int $a, int $b): void
    {
        FriendChallenge::query()->activeBetween($a, $b)->where('expires_at', '<=', now())
            ->update(['status' => FriendChallenge::STATUS_EXPIRED, 'active_pair_key' => null]);
    }

    protected function assertParticipant(User $actor, FriendChallenge $challenge): void
    {
        if (! $challenge->involves($actor)) {
            throw new CompetitiveException('لا يمكنك تنفيذ هذا الإجراء.');
        }
    }

    protected function assertOpponent(User $actor, FriendChallenge $challenge): void
    {
        if ($challenge->opponent_id !== $actor->getKey()) {
            throw new CompetitiveException('لا يمكنك تنفيذ هذا الإجراء.');
        }
    }

    /** يُجسِّد الانتهاء/الإلغاء خارج أي معاملة ثم يرفض (لو رُمي الاستثناء داخل معاملة لتراجع التجسيد). */
    protected function assertStillValid(FriendChallenge $challenge): void
    {
        if (! in_array($challenge->status, FriendChallenge::ACTIVE_STATUSES, true)) {
            throw new CompetitiveException('هذا التحدي لم يعد قائمًا.');
        }

        if ($challenge->expires_at->lessThanOrEqualTo(now())) {
            $this->expireStale();

            throw new CompetitiveException('انتهت مهلة هذا التحدي.');
        }

        if (! $this->canChallenge($challenge->challenger, $challenge->opponent)) {
            FriendChallenge::cancelActiveBetween($challenge->challenger_id, $challenge->opponent_id);

            throw new CompetitiveException('لم يعد هذا التحدي متاحًا.');
        }
    }

    protected function assertPlayable(User $actor, FriendChallenge $challenge): void
    {
        $this->assertStillValid($challenge);

        if ($challenge->status !== FriendChallenge::STATUS_ACCEPTED) {
            throw new CompetitiveException('يجب قبول التحدي أولًا.');
        }

        if ($challenge->results()->where('user_id', $actor->getKey())->exists()) {
            throw new CompetitiveException('سجّلت نتيجتك بالفعل.');
        }
    }

    protected function completeIfBothDone(FriendChallenge $challenge): void
    {
        $results = $challenge->results()->get();

        if ($results->count() < 2) {
            return;
        }

        $top = $results->sortByDesc('score')->values();
        $isDraw = $top[0]->score === $top[1]->score;

        $affected = FriendChallenge::query()->whereKey($challenge->getKey())->where('status', FriendChallenge::STATUS_ACCEPTED)
            ->update([
                'status' => FriendChallenge::STATUS_COMPLETED,
                'completed_at' => now(),
                'winner_user_id' => $isDraw ? null : $top[0]->user_id,
                'is_draw' => $isDraw,
                'active_pair_key' => null,
            ]);

        if ($affected === 1) {
            $this->afterCommit(fn () => event(new FriendChallengeCompleted($challenge->getKey())));
        }
    }

    protected function afterCommit(\Closure $callback): void
    {
        DB::afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e); // الإشعارات ثانوية: فشلها لا يمسّ التحدي ولا نتيجته.
            }
        });
    }
}
