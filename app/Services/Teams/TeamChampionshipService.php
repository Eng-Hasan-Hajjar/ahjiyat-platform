<?php

namespace App\Services\Teams;

use App\Events\TeamChampionshipFinalized;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventTeamResult;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * إجراءات مجال بطولات الفرق (E20-D/E). لا تعديل خام للحالة: النشر والإلغاء والاعتماد إجراءات بصلاحية (team_championships.publish) ومدقَّقة (OperationalAuditService)،
 * وإدارة البطولة/ربط الأحداث بصلاحية team_championships.manage وللمسودة وحدها. **النشر يقفل العدالة**: المواعيد ولقطة النقاط (وربط الأحداث) لا تتغير بعده. **الاعتماد** (بعد ends_at
 * وبعد أن تُعتمَد كل الأحداث المرتبطة غير الملغاة بترتيب فرق مخزَّن): ذري Idempotent، يكتب الترتيب النهائي والبطل مرة واحدة فلا يتغير أي شيء بعده. لا جوائز اقتصادية: تعرُّف فقط.
 * لا بطولات رجعية تلقائية ولا Backfill: الربط صريح بيد الإدارة.
 */
class TeamChampionshipService
{
    public function __construct(protected TeamChampionshipStandingsService $standings, protected OperationalAuditService $audit) {}

    public function create(User $actor, array $data): TeamChampionship
    {
        abort_unless($actor->can('team_championships.manage'), 403);

        $championship = new TeamChampionship($data);
        $championship->forceFill(['status' => TeamChampionship::STATUS_DRAFT])->save();

        return $championship;
    }

    /** المسودة: كل الحقول. بعد النشر: العنوان والوصف والتمييز فقط (المواعيد مقفلة بحارس النموذج). */
    public function update(User $actor, TeamChampionship $championship, array $data): TeamChampionship
    {
        abort_unless($actor->can('team_championships.manage'), 403);

        $allowed = $championship->isDraft() ? ['title', 'slug', 'description', 'starts_at', 'ends_at', 'is_featured'] : ['title', 'description', 'is_featured'];

        try {
            $championship->update(array_intersect_key($data, array_flip($allowed)));
        } catch (\InvalidArgumentException $e) {
            throw new TeamException($e->getMessage());
        }

        return $championship->refresh();
    }

    /** سبب عدم صلاحية الحدث للربط (أو null): لا مسودة ولا ملغى، والمعتمَد يحتاج ترتيب فرق فعليًا. */
    public function compatibilityError(CompetitiveEvent $event): ?string
    {
        return match (true) {
            in_array($event->status, [CompetitiveEvent::STATUS_DRAFT, CompetitiveEvent::STATUS_CANCELLED], true) => 'الحدث غير منشور أو ملغى: لا ينتج ترتيب فرق.',
            $event->status === CompetitiveEvent::STATUS_COMPLETED && ! CompetitiveEventTeamResult::query()->where('competitive_event_id', $event->getKey())->exists() => 'الحدث المعتمَد لا ترتيب فرق له (لا لقطات فرق).',
            default => null,
        };
    }

    public function linkEvent(User $actor, TeamChampionship $championship, CompetitiveEvent $event): void
    {
        abort_unless($actor->can('team_championships.manage'), 403);
        $this->assertDraft($championship);

        if (($reason = $this->compatibilityError($event)) !== null) {
            throw new TeamException($reason);
        }

        $exists = DB::table('team_championship_events')->where('team_championship_id', $championship->getKey())->where('competitive_event_id', $event->getKey())->exists();

        if ($exists) {
            throw new TeamException('هذا الحدث مرتبط بالبطولة بالفعل.');
        }

        DB::table('team_championship_events')->insert([
            'team_championship_id' => $championship->getKey(), 'competitive_event_id' => $event->getKey(),
            'sort_order' => (int) DB::table('team_championship_events')->where('team_championship_id', $championship->getKey())->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function unlinkEvent(User $actor, TeamChampionship $championship, CompetitiveEvent $event): void
    {
        abort_unless($actor->can('team_championships.manage'), 403);
        $this->assertDraft($championship);

        DB::table('team_championship_events')->where('team_championship_id', $championship->getKey())->where('competitive_event_id', $event->getKey())->delete();
    }

    public function publish(User $actor, TeamChampionship $championship): TeamChampionship
    {
        abort_unless($actor->can('team_championships.publish'), 403);

        return DB::transaction(function () use ($actor, $championship) {
            $locked = TeamChampionship::query()->lockForUpdate()->findOrFail($championship->getKey());

            if ($locked->status !== TeamChampionship::STATUS_DRAFT) {
                throw new TeamException('تُنشر المسودات فقط.');
            }

            if ($locked->ends_at->lessThanOrEqualTo(now())) {
                throw new TeamException('نهاية البطولة يجب أن تكون بالمستقبل لتُنشر.');
            }

            $events = $locked->events()->get();

            if ($events->isEmpty()) {
                throw new TeamException('اربط حدثًا واحدًا على الأقل قبل النشر.');
            }

            foreach ($events as $event) {
                if (($reason = $this->compatibilityError($event)) !== null) {
                    throw new TeamException("الحدث «{$event->title}»: {$reason}");
                }
            }

            // لقطة خريطة النقاط وقت النشر (هيكلية: أعداد صحيحة): تعديل الإعدادات لاحقًا لا يغيّر تاريخ البطولة.
            $locked->forceFill(['status' => TeamChampionship::STATUS_PUBLISHED, 'published_at' => now(), 'points_snapshot' => $locked->pointsMap()])->save();
            $this->audit->log('team_championship_published', $locked, ['events' => $events->count()], $actor);

            return $locked;
        });
    }

    public function cancel(User $actor, TeamChampionship $championship): TeamChampionship
    {
        abort_unless($actor->can('team_championships.publish'), 403);

        $affected = TeamChampionship::query()->whereKey($championship->getKey())->whereIn('status', [TeamChampionship::STATUS_DRAFT, TeamChampionship::STATUS_PUBLISHED])->update(['status' => TeamChampionship::STATUS_CANCELLED]);

        if ($affected === 0) {
            throw new TeamException('لا يمكن إلغاء بطولة معتمَدة أو ملغاة.');
        }

        $this->audit->log('team_championship_cancelled', $championship, [], $actor);

        return $championship->refresh();
    }

    /** سبب عدم إمكانية الاعتماد الآن (أو null). */
    public function finalizeBlocker(TeamChampionship $championship): ?string
    {
        if ($championship->status !== TeamChampionship::STATUS_PUBLISHED) {
            return 'تُعتمد البطولات المنشورة فقط.';
        }

        if ($championship->ends_at->greaterThan(now())) {
            return 'لم تنتهِ البطولة بعد.';
        }

        $pending = $championship->events()->where('competitive_events.status', '!=', CompetitiveEvent::STATUS_CANCELLED)
            ->where(fn ($q) => $q->where('competitive_events.status', '!=', CompetitiveEvent::STATUS_COMPLETED)->orWhereNull('competitive_events.team_rankings_finalized_at'))->count();

        return match (true) {
            $pending > 0 => 'ما زالت أحداث مرتبطة غير معتمَدة بترتيب فرق.',
            $this->standings->contributingEventIds($championship) === [] => 'لا حدث معتمَد يمنح نقاطًا.',
            default => null,
        };
    }

    /** @param  User|null  $actor  null = الأمر الدوري (نظام). @return bool هل اعتُمدت الآن؟ */
    public function finalize(?User $actor, TeamChampionship $championship): bool
    {
        if ($actor !== null) {
            abort_unless($actor->can('team_championships.publish'), 403);
        }

        return DB::transaction(function () use ($actor, $championship) {
            $locked = TeamChampionship::query()->lockForUpdate()->find($championship->getKey());

            if ($locked === null || $locked->status === TeamChampionship::STATUS_COMPLETED) {
                return false;                                                       // Idempotent
            }

            if (($reason = $this->finalizeBlocker($locked)) !== null) {
                if ($actor !== null) {
                    throw new TeamException($reason);
                }

                return false;
            }

            $rows = $this->standings->compute($locked);

            foreach ($rows as $row) {
                DB::table('team_championship_results')->insertOrIgnore([[
                    'team_championship_id' => $locked->getKey(), 'team_id' => $row['team_id'], 'points' => $row['points'], 'events_count' => $row['events_count'], 'event_wins' => $row['event_wins'],
                    'top3_count' => $row['top3_count'], 'best_rank' => $row['best_rank'], 'rank' => $row['rank'], 'created_at' => now(), 'updated_at' => now(),
                ]]);
            }

            $locked->forceFill(['status' => TeamChampionship::STATUS_COMPLETED, 'finalized_at' => now(), 'champion_team_id' => $rows[0]['team_id']])->save();
            $this->audit->log('team_championship_finalized', $locked, ['champion_team_id' => $rows[0]['team_id'], 'teams' => count($rows)], $actor);

            DB::afterCommit(function () use ($locked) {
                try {
                    event(new TeamChampionshipFinalized($locked->getKey()));
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            return true;
        });
    }

    protected function assertDraft(TeamChampionship $championship): void
    {
        if (! TeamChampionship::query()->whereKey($championship->getKey())->where('status', TeamChampionship::STATUS_DRAFT)->exists()) {
            throw new TeamException('أحداث البطولة وإعداداتها العادلة مقفلة بعد النشر.');
        }
    }
}
