<?php

namespace App\Services\Competitive;

use App\GameEngine\Support\AttemptContext;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * مشاركة المستخدمين بالمنافسات (E17-B/C). الطرف الحالي دائمًا المستخدم المصادَق. بلا رسوم دخول ولا أي أفضلية مشتراة.
 *
 * - تسجيل: منشورة + ضمن نافذة التسجيل + مؤهَّل (موثَّق وغير مجمَّد) + السعة. التسجيل المزدوج Idempotent (UNIQUE(event, user)).
 * - السعة بحماية ذرية في قاعدة البيانات: UPDATE participants_count ... WHERE participants_count < max_participants بجملة واحدة (لا count ثم insert).
 * - انسحاب: قبل بدء الحدث وقبل أي نتيجة فقط؛ لا حذف يمحو تاريخ منافسة.
 * - لعب: مشارك مسجَّل فقط، داخل [starts_at, ends_at)، محاولة واحدة، والنتيجة (صحة/مدة/نقاط) بالسيرفر من CompetitiveRunService؛ نتيجة
 *   متأخرة أو بلا تسجيل أو بجلسة غير صالحة أو لمستخدم آخر تُرفض. أي score/winner/rank من العميل لا يُقرأ أصلًا.
 */
class CompetitiveEventService
{
    public function __construct(protected CompetitiveRunService $runs) {}

    public function register(User $user, CompetitiveEvent $event): CompetitiveEventParticipant
    {
        $this->assertEligibleAccount($user);

        return DB::transaction(function () use ($user, $event) {
            $locked = $this->lockedEvent($event);

            $existing = CompetitiveEventParticipant::query()->where('competitive_event_id', $locked->getKey())->where('user_id', $user->getKey())->first();

            if ($existing !== null) {
                return $existing; // التسجيل المزدوج: Idempotent
            }

            $this->assertRegistrationOpen($locked);

            // سعة ذرية: UPDATE شرطي واحد هو الحَكَم (لا count ثم insert). نسخة $locked قد تكون قديمة؛ القاعدة لا.
            $reserved = CompetitiveEvent::query()->whereKey($locked->getKey())->where('status', CompetitiveEvent::STATUS_PUBLISHED)
                ->where(fn ($q) => $q->whereNull('max_participants')->orWhereColumn('participants_count', '<', 'max_participants'))
                ->increment('participants_count');

            if ($reserved === 0) {
                throw new CompetitiveException('اكتمل عدد المشاركين في هذه المنافسة.');
            }

            try {
                return CompetitiveEventParticipant::create([
                    'competitive_event_id' => $locked->getKey(),
                    'user_id' => $user->getKey(),
                    // E19: لقطة فريق اللاعب (المفعَّل) لحظة التسجيل. ترتيب الفرق يقرأ هذه اللقطة لا العضوية الحالية؛ بلا فريق = NULL.
                    'team_id_snapshot' => app(\App\Services\Teams\TeamMembershipService::class)->teamIdFor($user),
                    'status' => CompetitiveEventParticipant::STATUS_REGISTERED,
                    'registered_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new CompetitiveException('أنت مسجَّل بالفعل.'); // يتراجع معه العدّاد
            }
        });
    }

    public function leave(User $user, CompetitiveEvent $event): void
    {
        DB::transaction(function () use ($user, $event) {
            $locked = $this->lockedEvent($event);

            if ($locked->phase() !== CompetitiveEvent::PHASE_UPCOMING) {
                throw new CompetitiveException('لا يمكن الانسحاب بعد بدء المنافسة.');
            }

            $deleted = CompetitiveEventParticipant::query()->where('competitive_event_id', $locked->getKey())->where('user_id', $user->getKey())
                ->where('status', CompetitiveEventParticipant::STATUS_REGISTERED)->delete();

            if ($deleted === 0) {
                throw new CompetitiveException('لست مسجَّلًا في هذه المنافسة.');
            }

            CompetitiveEvent::query()->whereKey($locked->getKey())->where('participants_count', '>', 0)->decrement('participants_count');
        });
    }

    public function start(User $user, CompetitiveEvent $event): GameSession
    {
        $this->assertCanPlay($user, $event);

        return $this->runs->start($user, $event->puzzle, AttemptContext::competitiveEvent($event->getKey()), requireActivePuzzle: false);
    }

    /** @param  array<string, mixed>  $input  الإجابة فقط (answer/submission): غيرها يُتجاهل */
    public function submit(User $user, CompetitiveEvent $event, array $input): CompetitiveOutcome
    {
        $this->assertCanPlay($user, $event);

        $context = AttemptContext::competitiveEvent($event->getKey());
        $session = $this->runs->activeSession($user, $context) ?? throw new CompetitiveException('ابدأ المحاولة أولًا.');

        return DB::transaction(function () use ($user, $event, $input, $context, $session) {
            $locked = $this->lockedEvent($event);

            if ($locked->status !== CompetitiveEvent::STATUS_PUBLISHED || ! $locked->isLive()) {
                throw new CompetitiveException('انتهت المنافسة أو لم تعد تقبل نتائج.'); // نتيجة متأخرة/بعد الإلغاء
            }

            $outcome = $this->runs->finish($user, $session, $locked->puzzle, $context, $input);

            try {
                CompetitiveEventResult::create([
                    'competitive_event_id' => $locked->getKey(),
                    'user_id' => $user->getKey(),
                    'game_session_id' => $outcome->session->getKey(),
                    'is_correct' => $outcome->correct,
                    'score' => $outcome->score,
                    'duration_ms' => $outcome->durationMs,
                    'completed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new CompetitiveException('سُجّلت نتيجتك بالفعل.');
            }

            CompetitiveEventParticipant::query()->where('competitive_event_id', $locked->getKey())->where('user_id', $user->getKey())
                ->update(['status' => CompetitiveEventParticipant::STATUS_COMPLETED, 'completed_at' => now()]);

            return $outcome;
        });
    }

    /** مفتاح حالة زر الانضمام لعرض الصفحة: guest|cancelled|ended|completed|full|register|registration_closed|registered|play|resume. */
    public function joinState(?User $user, CompetitiveEvent $event): string
    {
        $phase = $event->phase();

        if ($phase === CompetitiveEvent::PHASE_CANCELLED) {
            return 'cancelled';
        }

        if ($user !== null && $this->result($user, $event) !== null) {
            return 'completed';
        }

        if (in_array($phase, [CompetitiveEvent::PHASE_ENDED, CompetitiveEvent::PHASE_COMPLETED], true)) {
            return 'ended';
        }

        if ($user === null) {
            return 'guest';
        }

        if ($this->participant($user, $event) !== null) {
            return $phase === CompetitiveEvent::PHASE_UPCOMING ? 'registered'
                : ($this->runs->activeSession($user, AttemptContext::competitiveEvent($event->getKey())) !== null ? 'resume' : 'play');
        }

        if ($event->isFull()) {
            return 'full';
        }

        return $event->registrationOpen() ? 'register' : 'registration_closed';
    }

    /** الجلسة النشطة لمشارك مؤهَّل داخل نافذة اللعب (للصفحة)، أو استثناء برسالة. */
    public function activeRun(User $user, CompetitiveEvent $event): GameSession
    {
        $this->assertCanPlay($user, $event);

        return $this->runs->activeSession($user, AttemptContext::competitiveEvent($event->getKey())) ?? throw new CompetitiveException('ابدأ المحاولة أولًا.');
    }

    public function participant(User $user, CompetitiveEvent $event): ?CompetitiveEventParticipant
    {
        return CompetitiveEventParticipant::query()->where('competitive_event_id', $event->getKey())->where('user_id', $user->getKey())->first();
    }

    public function result(User $user, CompetitiveEvent $event): ?CompetitiveEventResult
    {
        return CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())->where('user_id', $user->getKey())->first();
    }

    // ---------------------------------------------------------------- داخلي

    /** قراءة الحدث داخل المعاملة مع قفل (معزولة لتُحاكى بها فجوة السباق في الاختبار). */
    protected function lockedEvent(CompetitiveEvent $event): CompetitiveEvent
    {
        return CompetitiveEvent::query()->lockForUpdate()->findOrFail($event->getKey());
    }

    protected function assertEligibleAccount(User $user): void
    {
        if (! $user->hasVerifiedEmail() || $user->is_frozen) {
            throw new CompetitiveException('حسابك غير مؤهَّل للمشاركة.');
        }
    }

    protected function assertRegistrationOpen(CompetitiveEvent $event): void
    {
        $now = now();

        if (! in_array($event->status, [CompetitiveEvent::STATUS_PUBLISHED], true)) {
            throw new CompetitiveException('هذه المنافسة غير متاحة للتسجيل.');
        }

        if ($event->registration_starts_at !== null && $now->lessThan($event->registration_starts_at)) {
            throw new CompetitiveException('لم يبدأ التسجيل بعد.');
        }

        if (! $event->registrationOpen($now)) {
            throw new CompetitiveException('انتهى التسجيل في هذه المنافسة.');
        }
    }

    /** مشارك مسجَّل، حساب مؤهَّل، منشورة وقيد التشغيل، ولا نتيجة سابقة. */
    protected function assertCanPlay(User $user, CompetitiveEvent $event): void
    {
        $this->assertEligibleAccount($user);

        $fresh = CompetitiveEvent::query()->findOrFail($event->getKey());

        if ($fresh->status !== CompetitiveEvent::STATUS_PUBLISHED) {
            throw new CompetitiveException('هذه المنافسة غير متاحة للعب.');
        }

        $now = now();

        if ($now->lessThan($fresh->starts_at)) {
            throw new CompetitiveException('لم تبدأ المنافسة بعد.');
        }

        if ($now->greaterThanOrEqualTo($fresh->ends_at)) {
            throw new CompetitiveException('انتهت المنافسة.');
        }

        if ($this->participant($user, $fresh) === null) {
            throw new CompetitiveException('يجب التسجيل في المنافسة أولًا.');
        }

        if ($this->result($user, $fresh) !== null) {
            throw new CompetitiveException('سجّلت نتيجتك بالفعل.');
        }
    }
}
