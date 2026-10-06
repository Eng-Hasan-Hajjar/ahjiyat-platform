<?php

namespace App\Services\Competitive;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\Notifications\ReEngagementPolicy;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * الدورة الزمنية للمنافسات (E17-E): أمر ساعي واحد بمنطق مشترك (لا أمر لكل إشعار). بالترتيب: تجسيد انتهاء/إلغاء التحديات، إشعار "بدأت" للمسجَّلين
 * قبل البدء، إشعار "تنتهي قريبًا" لمن لم يُرسل نتيجته (بميزانية E15 اليومية)، ثم اعتماد النتائج للمنتهية. كله Idempotent: المفاتيح الدلالية
 * وقيد UNIQUE بالإشعارات والمنفِّذ المتجدّد الأمان. يكتب إشعارات فقط (قراءة لأي حالة لعب)؛ لا مكافأة ولا تقدّم.
 */
class CompetitiveLifecycleService
{
    public function __construct(
        protected FriendChallengeService $challenges,
        protected CompetitiveEventFinalizer $finalizer,
        protected ReEngagementPolicy $policy,
    ) {}

    public function windowHours(): int
    {
        return max(1, (int) config('player_notifications.ending_soon_window_hours', 24));
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $stats = [
            'challenges_expired' => $this->challenges->expireStale(),
            'challenges_cancelled' => $this->challenges->cancelInvalid(),
            'counters_fixed' => $this->reconcileParticipantCounters(),
            'started' => $this->blank(),
            'ending_soon' => $this->blank(),
            'finalized' => 0,
        ];

        $now = now();

        // بدأت (خلال آخر 24 ساعة فقط: لا إشعار "بدأت" متأخر جدًا)
        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('starts_at', '<=', $now)->where('starts_at', '>=', $now->copy()->subHours(24))->where('ends_at', '>', $now)
            ->chunkById(50, function ($events) use (&$stats) {
                foreach ($events as $event) {
                    $this->remind($event, NotificationType::CompetitiveEventStarted, 'competitive-event-started', [], true, false, $stats['started']);
                }
            });

        // تنتهي قريبًا
        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->where('ends_at', '<=', $now->copy()->addHours($this->windowHours()))
            ->chunkById(50, function ($events) use (&$stats) {
                foreach ($events as $event) {
                    $this->remind($event, NotificationType::CompetitiveEventEndingSoon, 'competitive-event-ending', ['hours' => $this->windowHours()], false, true, $stats['ending_soon']);
                }
            });

        // اعتماد النتائج للمنتهية (إشعار النتيجة يخرج من حدث الاعتماد)
        CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('ends_at', '<=', $now)
            ->chunkById(50, function ($events) use (&$stats) {
                foreach ($events as $event) {
                    $stats['finalized'] += $this->finalizer->finalize($event) ? 1 : 0;
                }
            });

        return $stats;
    }

    /**
     * عدّاد participants_count يحمي السعة ذريًا، لكن حذف مستخدم (cascade) يحذف صف مشاركته دون إنقاص العدّاد. تصحيح ذاتي Idempotent: يعيد ضبط
     * العدّاد حيث يخالف العدّ الفعلي فقط (استعلام واحد، لا يمسّ شيئًا غيره). @return int عدد الأحداث المصحَّحة
     */
    public function reconcileParticipantCounters(): int
    {
        $actual = '(select count(*) from competitive_event_participants p where p.competitive_event_id = competitive_events.id)';

        return DB::table('competitive_events')->whereRaw("participants_count != {$actual}")->update(['participants_count' => DB::raw($actual)]);
    }

    /** @param  array<string, int>  $stats */
    protected function remind(CompetitiveEvent $event, NotificationType $type, string $prefix, array $params, bool $registeredBeforeStart, bool $budgeted, array &$stats): void
    {
        $max = $this->policy->maxPerDay();

        CompetitiveEventParticipant::query()->where('competitive_event_id', $event->id)
            ->where('status', CompetitiveEventParticipant::STATUS_REGISTERED) // من لم يُرسل نتيجته بعد
            ->when($registeredBeforeStart, fn ($q) => $q->where('registered_at', '<', $event->starts_at))
            ->with('user')
            ->chunkById(max(1, (int) config('player_notifications.chunk_size', 200)), function ($participants) use ($event, $type, $prefix, $params, $budgeted, $max, &$stats) {
                $users = $participants->pluck('user')->filter(fn (?User $u) => $u !== null && ! $u->is_frozen && $u->email_verified_at !== null);
                $ids = $users->pluck('id')->all();

                $sent = array_flip(DatabaseNotification::query()
                    ->where('notifiable_type', (new User)->getMorphClass())->where('type_key', $type->value)
                    ->whereIn('idempotency_key', array_map(fn ($id) => "{$prefix}:{$event->id}:{$id}", $ids))
                    ->pluck('notifiable_id')->all());

                $used = $budgeted ? $this->policy->usedToday($ids, $type) : [];

                foreach ($users as $user) {
                    $stats['candidates']++;

                    if (isset($sent[$user->id])) {
                        $stats['already']++;
                    } elseif ($budgeted && ($used[$user->id] ?? 0) >= $max) {
                        $stats['over_budget']++;
                    } else {
                        $this->send($user, $event, $type, $prefix, $params, $stats);
                    }
                }
            });
    }

    protected function send(User $user, CompetitiveEvent $event, NotificationType $type, string $prefix, array $params, array &$stats): void
    {
        try {
            $result = app(NotificationDispatcher::class)->dispatch(
                $user,
                $type,
                ['title' => $event->title] + $params,
                "{$prefix}:{$event->id}:{$user->id}",
                ['competitive_event_id' => $event->id],
                ['event' => $event->slug],
                'competitions.show',
            );

            match ($result) {
                DispatchResult::Created => $stats['created']++,
                DispatchResult::Suppressed => $stats['suppressed']++,
                DispatchResult::Duplicate => $stats['already']++,
                DispatchResult::Failed => $stats['failed']++,
            };
        } catch (\Throwable $e) {
            report($e);
            $stats['failed']++;
        }
    }

    /** @return array<string, int> */
    protected function blank(): array
    {
        return ['candidates' => 0, 'already' => 0, 'over_budget' => 0, 'created' => 0, 'suppressed' => 0, 'failed' => 0];
    }
}
