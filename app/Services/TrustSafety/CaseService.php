<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationAction;
use App\Models\ModerationCase;
use App\Models\ModerationCaseEvent;
use App\Models\ModerationCaseSource;
use App\Models\ModerationEvidence;
use App\Models\User;
use App\Services\OperationalAuditService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E38 - دورة حياة حالة الإشراف. **تنسيق فقط**: لا تغيّر أي حالة مجال مباشرةً (الإجراءات في EnforcementService عبر خدمات المجال، وحسم البلاغات عبر ReportSyncService).
 *
 *  - فتح حالة من بلاغ/بلاغات (قائمة بيضاء؛ موضوع واحد مشترك؛ بلاغ مفتوح فقط؛ بلاغ واحد في حالة فعّالة واحدة بقيد DB).
 *  - تعيين بحجز ذرّي (UPDATE مشروط) فلا يحجز مشرفان الحالة نفسها؛ إعادة تعيين للمشرف الأعلى فقط.
 *  - حواجز تضارب: لا تعامل مع حالة تخصّك (أنت صاحب المحتوى/الحساب) ولا مع حالة أنت مبلِّغ فيها.
 *  - تاريخ إلحاقي: الأحداث والأدلة لا تُعدَّل ولا تُحذف؛ الحالات لا تُحذف. تدقيق عام عبر OperationalAuditService.
 */
class CaseService
{
    private const RANK = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    public function __construct(
        protected SubjectResolver $subjects,
        protected ReportSyncService $sync,
        protected OperationalAuditService $audit,
    ) {}

    // ───────────────────────── فتح الحالة وربط المصادر ─────────────────────────

    /** @param list<string|array> $refs "type:id" أو [type, id] — النوع من القائمة البيضاء فقط */
    public function open(User $actor, array $refs, ?string $summary = null, ?string $priority = null): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');
        $pairs = $this->parseRefs($refs);
        $rows = $this->loadReports($pairs);
        $subject = $this->commonSubject($rows);
        $this->assertAllOpen($rows);
        $this->assertNotTaken($pairs);

        $info = $this->subjects->one($subject[0], $subject[1]);
        $this->assertNoConflictFor($actor, $info['owner_id'], array_column($rows, 'reporter_id'));

        $summary = PlainText::optional($summary, (int) config('trust_safety.case.summary_max'), 'الملخص');
        [$category, $suggested] = $this->pickCategoryAndPriority($rows);

        if ($priority !== null && $priority !== '') {
            if (! array_key_exists($priority, ModerationCase::PRIORITIES)) {
                throw new ModerationException('أولوية غير صالحة.');
            }

            $suggested = $priority;
        }

        try {
            $case = DB::transaction(function () use ($actor, $pairs, $rows, $subject, $info, $summary, $category, $suggested) {
                $case = new ModerationCase;
                $case->forceFill([
                    'status' => ModerationCase::STATUS_OPEN, 'priority' => $suggested, 'category' => $category,
                    'subject_type' => $subject[0], 'subject_id' => $subject[1],
                    'subject_user_id' => $info['owner_id'], 'subject_label' => PlainText::excerpt($info['label'], (int) config('trust_safety.case.label_max')),
                    'summary' => $summary, 'opened_by' => $actor->id,
                ] + PolicyCatalog::slaDue($suggested, now()))->save();

                $case->forceFill(['case_number' => $this->numberFor($case)])->save();

                $this->attachRows($case, $rows, $actor);
                $this->snapshot($case, $rows, $info);

                $this->record($case, $actor, 'case_opened', null, ModerationCase::STATUS_OPEN, null, [
                    'sources' => array_map(fn ($p) => $p[0].':'.$p[1], $pairs), 'category' => $category, 'priority' => $suggested,
                ]);
                $this->audit->log('moderation_case_opened', $case, ['case' => $case->public_id, 'number' => $case->case_number, 'category' => $category, 'priority' => $suggested, 'sources' => count($pairs)], $actor);

                return $case;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ModerationException('أحد البلاغات مرتبط بحالة فعّالة بالفعل.');
        }

        return $case->fresh();
    }

    /** @param list<string|array> $refs */
    public function attach(ModerationCase $case, User $actor, array $refs): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');
        $pairs = $this->parseRefs($refs);
        $rows = $this->loadReports($pairs);
        $subject = $this->commonSubject($rows);
        $this->assertAllOpen($rows);
        $this->assertNotTaken($pairs);

        try {
            DB::transaction(function () use ($case, $actor, $rows, $subject, $pairs) {
                $locked = $this->lock($case);
                $this->assertActive($locked);
                $this->assertCanDrive($locked, $actor);
                $this->assertCanWork($locked, $actor);

                if ($subject[0] !== $locked->subject_type || $subject[1] !== (int) $locked->subject_id) {
                    throw new ModerationException('البلاغ لا يخصّ موضوع هذه الحالة.');
                }

                $this->assertNoConflictFor($actor, $locked->subject_user_id, array_column($rows, 'reporter_id'));
                $this->attachRows($locked, $rows, $actor);
                $this->record($locked, $actor, 'sources_attached', null, null, null, ['sources' => array_map(fn ($p) => $p[0].':'.$p[1], $pairs)]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ModerationException('أحد البلاغات مرتبط بحالة فعّالة بالفعل.');
        }

        return $case->fresh();
    }

    public function detach(ModerationCase $case, User $actor, int $sourceRowId): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');

        DB::transaction(function () use ($case, $actor, $sourceRowId) {
            $locked = $this->lock($case);
            $this->assertActive($locked);
            $this->assertCanDrive($locked, $actor);
            $this->assertCanWork($locked, $actor);

            $source = ModerationCaseSource::query()->where('moderation_case_id', $locked->id)->where('active_marker', 1)->whereKey($sourceRowId)->first();

            if ($source === null) {
                throw new ModerationException('المصدر غير موجود أو مفصول بالفعل.');
            }

            if (ModerationCaseSource::query()->where('moderation_case_id', $locked->id)->where('active_marker', 1)->count() < 2) {
                throw new ModerationException('لا يمكن فصل آخر مصدر؛ أغلق الحالة بدلًا من ذلك.');
            }

            $source->forceFill(['active_marker' => null, 'detached_at' => now(), 'detached_by' => $actor->id])->save();
            $this->record($locked, $actor, 'source_detached', null, null, null, ['source' => $source->source_type.':'.$source->source_id]);
        });

        return $case->fresh();
    }

    // ───────────────────────── التعيين ─────────────────────────

    /** حجز ذرّي: UPDATE مشروط واحد (assigned_to IS NULL ∧ حالة فعّالة). الفائز واحد فقط تحت التزامن. */
    public function claim(ModerationCase $case, User $actor): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');
        $fresh = ModerationCase::query()->findOrFail($case->id);
        $this->assertActive($fresh);
        $this->assertCanWork($fresh, $actor);

        if ((int) $fresh->assigned_to === (int) $actor->id) {
            return $fresh;
        }

        $now = now()->toDateTimeString();
        $affected = ModerationCase::query()
            ->whereKey($fresh->id)->whereNull('assigned_to')->whereIn('status', ModerationCase::ACTIVE_STATUSES)
            ->update([
                'assigned_to' => $actor->id, 'assigned_at' => $now, 'updated_at' => $now,
                'status' => DB::raw("CASE WHEN status = 'open' THEN 'investigating' ELSE status END"),
            ]);

        if ($affected !== 1) {
            throw new ModerationException('حُجزت هذه الحالة من مشرف آخر أو لم تعد فعّالة.');
        }

        // أول استجابة تُسجَّل مرة واحدة فقط: UPDATE مشروط idempotent بعد الفوز بالحجز (بلا تضمين نصي داخل SQL).
        ModerationCase::query()->whereKey($fresh->id)->whereNull('first_responded_at')->update(['first_responded_at' => $now]);

        $claimed = ModerationCase::query()->findOrFail($fresh->id);
        $this->record($claimed, $actor, 'claimed', null, null, null, ['assignee' => $actor->id]);
        $this->audit->log('moderation_case_claimed', $claimed, ['case' => $claimed->public_id], $actor);

        return $claimed;
    }

    /** يتخلّى المعيَّن عن حالته (تعود غير معيَّنة؛ investigating ← open). */
    public function release(ModerationCase $case, User $actor): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');

        return DB::transaction(function () use ($case, $actor) {
            $locked = $this->lock($case);
            $this->assertActive($locked);

            if ((int) $locked->assigned_to !== (int) $actor->id) {
                throw new ModerationException('الحالة ليست معيَّنة لك.');
            }

            $locked->forceFill([
                'assigned_to' => null, 'assigned_at' => null,
                'status' => $locked->status === ModerationCase::STATUS_INVESTIGATING ? ModerationCase::STATUS_OPEN : $locked->status,
            ])->save();
            $this->record($locked, $actor, 'released', null, null, null, ['assignee' => $actor->id]);

            return $locked;
        });
    }

    /** إعادة تعيين/إلغاء تعيين: للمشرف الأعلى فقط. المُعيَّن يجب أن يملك manage-cases وألّا يتضارب مع الحالة. */
    public function assign(ModerationCase $case, User $actor, ?User $assignee): ModerationCase
    {
        $this->gate($actor, 'trust-safety.supervise');

        return DB::transaction(function () use ($case, $actor, $assignee) {
            $locked = $this->lock($case);
            $this->assertActive($locked);

            if ($assignee !== null) {
                if (! $assignee->can('trust-safety.manage-cases')) {
                    throw new ModerationException('المشرف المختار لا يملك صلاحية إدارة الحالات.');
                }

                $this->assertCanWork($locked, $assignee);
            }

            $previous = $locked->assigned_to;
            $locked->forceFill([
                'assigned_to' => $assignee?->id, 'assigned_at' => $assignee !== null ? now() : null,
                'status' => $assignee === null && $locked->status === ModerationCase::STATUS_INVESTIGATING ? ModerationCase::STATUS_OPEN : ($assignee !== null && $locked->status === ModerationCase::STATUS_OPEN ? ModerationCase::STATUS_INVESTIGATING : $locked->status),
                'first_responded_at' => $assignee !== null ? ($locked->first_responded_at ?? now()) : $locked->first_responded_at,
            ])->save();

            $this->record($locked, $actor, $assignee === null ? 'unassigned' : 'reassigned', null, null, null, ['from' => $previous, 'to' => $assignee?->id]);
            $this->audit->log('moderation_case_assigned', $locked, ['case' => $locked->public_id, 'from' => $previous, 'to' => $assignee?->id], $actor);

            return $locked;
        });
    }

    // ───────────────────────── الحالة والأولوية والملاحظات ─────────────────────────

    public function changeStatus(ModerationCase $case, User $actor, string $to, ?string $note = null): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');

        if (! in_array($to, ModerationCase::ACTIVE_STATUSES, true)) {
            throw new ModerationException('انتقال الحالة غير مسموح؛ استخدم «حسم» أو «إغلاق».');
        }

        $note = PlainText::optional($note, (int) config('trust_safety.case.note_max'), 'الملاحظة');

        return DB::transaction(function () use ($case, $actor, $to, $note) {
            $locked = $this->lock($case);
            $this->assertActive($locked);
            $this->assertCanDrive($locked, $actor);
            $this->assertCanWork($locked, $actor);

            if ($locked->status === $to) {
                throw new ModerationException('الحالة هي نفسها بالفعل.');
            }

            $from = $locked->status;
            $locked->forceFill(['status' => $to, 'first_responded_at' => $locked->first_responded_at ?? now()])->save();
            $this->record($locked, $actor, 'status_changed', $from, $to, $note);

            return $locked;
        });
    }

    public function setPriority(ModerationCase $case, User $actor, string $priority, ?string $note = null): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');

        if (! array_key_exists($priority, ModerationCase::PRIORITIES)) {
            throw new ModerationException('أولوية غير صالحة.');
        }

        $note = PlainText::optional($note, (int) config('trust_safety.case.note_max'), 'الملاحظة');

        return DB::transaction(function () use ($case, $actor, $priority, $note) {
            $locked = $this->lock($case);
            $this->assertActive($locked);
            $this->assertCanDrive($locked, $actor);
            $this->assertCanWork($locked, $actor);

            if ($locked->priority === $priority) {
                throw new ModerationException('الأولوية هي نفسها بالفعل.');
            }

            $from = $locked->priority;
            $locked->forceFill(['priority' => $priority] + PolicyCatalog::slaDue($priority, $locked->created_at ?? now()))->save();
            $this->record($locked, $actor, 'priority_changed', $from, $priority, $note);

            return $locked;
        });
    }

    /** ملاحظة مشرف: دليل إلحاقي، نص عادي، للمشرفين فقط. */
    public function addNote(ModerationCase $case, User $actor, string $text): ModerationEvidence
    {
        $this->gate($actor, 'trust-safety.manage-cases');
        $text = PlainText::clean($text, 3, (int) config('trust_safety.case.note_max'), 'الملاحظة');

        return DB::transaction(function () use ($case, $actor, $text) {
            $locked = $this->lock($case);

            if ($locked->status === ModerationCase::STATUS_CLOSED) {
                throw new ModerationException('الحالة مغلقة.');
            }

            $this->assertCanWork($locked, $actor);

            $evidence = new ModerationEvidence;
            $evidence->forceFill(['moderation_case_id' => $locked->id, 'added_by' => $actor->id, 'kind' => ModerationEvidence::KIND_NOTE, 'body' => $text])->save();
            // الحدث لا يحمل نص الملاحظة (يبقى بالدليل فقط) — مرجع المعرّف يكفي.
            $this->record($locked, $actor, 'note_added', null, null, null, ['evidence' => $evidence->id]);

            return $evidence;
        });
    }

    // ───────────────────────── الحسم والإغلاق وإعادة الفتح ─────────────────────────

    /**
     * حسم الحالة: يزامن البلاغات المرتبطة عبر **خدمات المجال** (معالَج/مرفوض) ثم يحرّر المصادر. all-or-nothing بمعاملة واحدة.
     * «اتُّخذ إجراء» يتطلب إجراءً مسجَّلًا غير «لا إجراء» بهذه الحالة.
     */
    public function resolve(ModerationCase $case, User $actor, string $resolution, ?string $note = null): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');

        if (! array_key_exists($resolution, ModerationCase::RESOLUTIONS)) {
            throw new ModerationException('نتيجة حسم غير صالحة.');
        }

        $note = PlainText::optional($note, (int) config('trust_safety.case.note_max'), 'الملاحظة');

        // صلاحية المجال لكل مصدر فعّال (تفرضها خدمة المجال أيضًا): نفشل مبكرًا برسالة واضحة بدل إغلاق جزئي.
        foreach (ModerationCaseSource::query()->where('moderation_case_id', $case->id)->where('active_marker', 1)->get(['source_type']) as $source) {
            if (! $this->sync->canSync($actor, $source->source_type)) {
                throw new ModerationException('تحتاج صلاحية «'.$this->sync->requiredPermission($source->source_type).'» لحسم بلاغات هذا النوع.');
            }
        }

        $resolved = DB::transaction(function () use ($case, $actor, $resolution, $note) {
            $locked = $this->lock($case);
            $this->assertActive($locked);
            $this->assertCanDrive($locked, $actor);
            $this->assertCanWork($locked, $actor);

            if ($resolution === 'action_taken' && ! ModerationAction::query()->where('moderation_case_id', $locked->id)->where('action', '<>', 'no_action')->exists()) {
                throw new ModerationException('سجّل إجراءً أولًا أو اختر «لا مخالفة».');
            }

            foreach (ModerationCaseSource::query()->where('moderation_case_id', $locked->id)->where('active_marker', 1)->get() as $source) {
                $this->sync->close($source, $actor, $resolution === 'action_taken', (string) $locked->case_number);
            }

            $this->releaseSources($locked);
            $from = $locked->status;
            $locked->forceFill([
                'status' => ModerationCase::STATUS_RESOLVED, 'resolution' => $resolution,
                'resolved_at' => now(), 'first_responded_at' => $locked->first_responded_at ?? now(),
            ])->save();
            $this->record($locked, $actor, 'resolved', $from, ModerationCase::STATUS_RESOLVED, $note, ['resolution' => $resolution]);
            $this->audit->log('moderation_case_resolved', $locked, ['case' => $locked->public_id, 'number' => $locked->case_number, 'resolution' => $resolution], $actor);

            return $locked;
        });

        return $resolved->fresh();
    }

    /** إغلاق إداري (مثل: فتحت بالخطأ). لا يلمس البلاغات المجالية: تعود غير مرتبطة بالصندوق. السبب إلزامي. */
    public function close(ModerationCase $case, User $actor, string $note): ModerationCase
    {
        $this->gate($actor, 'trust-safety.manage-cases');
        $note = PlainText::clean($note, 8, (int) config('trust_safety.case.note_max'), 'سبب الإغلاق');

        return DB::transaction(function () use ($case, $actor, $note) {
            $locked = $this->lock($case);

            if ($locked->status === ModerationCase::STATUS_CLOSED) {
                throw new ModerationException('الحالة مغلقة بالفعل.');
            }

            $this->assertCanDrive($locked, $actor);
            $this->assertCanWork($locked, $actor);

            $this->releaseSources($locked);
            $from = $locked->status;
            $locked->forceFill(['status' => ModerationCase::STATUS_CLOSED, 'closed_at' => now()])->save();
            $this->record($locked, $actor, 'closed', $from, ModerationCase::STATUS_CLOSED, $note);
            $this->audit->log('moderation_case_closed', $locked, ['case' => $locked->public_id, 'number' => $locked->case_number, 'from' => $from], $actor);

            return $locked;
        })->fresh();
    }

    /** إعادة فتح حالة محسومة/مغلقة: للمشرف الأعلى فقط، ولا بد ألّا يكون أي مصدرها في حالة فعّالة أخرى. */
    public function reopen(ModerationCase $case, User $actor, string $note): ModerationCase
    {
        $this->gate($actor, 'trust-safety.supervise');
        $note = PlainText::clean($note, 8, (int) config('trust_safety.case.note_max'), 'سبب إعادة الفتح');

        try {
            return DB::transaction(function () use ($case, $actor, $note) {
                $locked = $this->lock($case);

                if ($locked->isActive()) {
                    throw new ModerationException('الحالة فعّالة بالفعل.');
                }

                $this->assertCanWork($locked, $actor);

                $sources = ModerationCaseSource::query()->where('moderation_case_id', $locked->id)->whereNull('detached_at')->get();

                if ($sources->isNotEmpty() && ModerationCaseSource::query()->where('active_marker', 1)->where('moderation_case_id', '<>', $locked->id)
                    ->where(function ($q) use ($sources) {
                        foreach ($sources as $s) {
                            $q->orWhere(fn ($x) => $x->where('source_type', $s->source_type)->where('source_id', $s->source_id));
                        }
                    })->exists()) {
                    throw new ModerationException('أحد بلاغات الحالة صار مرتبطًا بحالة فعّالة أخرى.');
                }

                ModerationCaseSource::query()->where('moderation_case_id', $locked->id)->whereNull('detached_at')->update(['active_marker' => 1]);

                $from = $locked->status;
                $locked->forceFill(['status' => ModerationCase::STATUS_OPEN, 'resolution' => null, 'resolved_at' => null, 'closed_at' => null])->save();
                $this->record($locked, $actor, 'reopened', $from, ModerationCase::STATUS_OPEN, $note);
                $this->audit->log('moderation_case_reopened', $locked, ['case' => $locked->public_id, 'number' => $locked->case_number, 'from' => $from], $actor);

                return $locked;
            })->fresh();
        } catch (UniqueConstraintViolationException) {
            throw new ModerationException('أحد بلاغات الحالة صار مرتبطًا بحالة فعّالة أخرى.');
        }
    }

    // ───────────────────────── القراءة ─────────────────────────

    /** @return LengthAwarePaginator<int, ModerationCase> */
    public function queue(User $actor, array $input): LengthAwarePaginator
    {
        abort_unless($actor->can('trust-safety.view'), 403);

        $view = in_array($input['view'] ?? 'open', ['open', 'mine', 'unassigned', 'urgent', 'overdue', 'resolved', 'all'], true) ? ($input['view'] ?? 'open') : 'open';
        $priority = array_key_exists($input['priority'] ?? '', ModerationCase::PRIORITIES) ? $input['priority'] : null;
        $category = PolicyCatalog::isCategory($input['category'] ?? null) ? $input['category'] : null;
        $perPage = max(1, min((int) ($input['per_page'] ?? config('trust_safety.lists.cases_per_page')), 100));

        $query = ModerationCase::query()
            ->with('assignee:id,name')
            ->withCount(['sources as active_sources_count' => fn ($q) => $q->where('active_marker', 1)]);

        match ($view) {
            'mine' => $query->whereIn('status', ModerationCase::ACTIVE_STATUSES)->where('assigned_to', $actor->id),
            'unassigned' => $query->whereIn('status', ModerationCase::ACTIVE_STATUSES)->whereNull('assigned_to'),
            'urgent' => $query->whereIn('status', ModerationCase::ACTIVE_STATUSES)->where('priority', 'urgent'),
            'overdue' => $query->whereIn('status', ModerationCase::ACTIVE_STATUSES)->where(fn ($q) => $q
                ->where('resolution_due_at', '<', now())
                ->orWhere(fn ($x) => $x->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))),
            'resolved' => $query->whereIn('status', [ModerationCase::STATUS_RESOLVED, ModerationCase::STATUS_CLOSED]),
            'all' => null,
            default => $query->whereIn('status', ModerationCase::ACTIVE_STATUSES),
        };

        if ($priority !== null) {
            $query->where('priority', $priority);
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('created_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($input['page'] ?? 1)));
    }

    /** عدّادات تبويبات طابور العمل باستعلام واحد. @return array{open:int,mine:int,unassigned:int,urgent:int,overdue:int} */
    public function queueCounts(User $actor): array
    {
        abort_unless($actor->can('trust-safety.view'), 403);

        $now = now()->toDateTimeString();
        $row = ModerationCase::query()->whereIn('status', ModerationCase::ACTIVE_STATUSES)->selectRaw(
            'COUNT(*) as open_total, '
            .'SUM(CASE WHEN assigned_to = ? THEN 1 ELSE 0 END) as mine_total, '
            .'SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned_total, '
            ."SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_total, "
            .'SUM(CASE WHEN resolution_due_at < ? OR (first_responded_at IS NULL AND first_response_due_at < ?) THEN 1 ELSE 0 END) as overdue_total',
            [$actor->id, $now, $now]
        )->first();

        return [
            'open' => (int) ($row->open_total ?? 0), 'mine' => (int) ($row->mine_total ?? 0), 'unassigned' => (int) ($row->unassigned_total ?? 0),
            'urgent' => (int) ($row->urgent_total ?? 0), 'overdue' => (int) ($row->overdue_total ?? 0),
        ];
    }

    /** حالة الـSLA الداخلية (اقتراح تشغيلي فقط): none | ok | due_soon | overdue. */
    public static function slaState(ModerationCase $case, ?CarbonInterface $now = null): string
    {
        if (! $case->isActive() || $case->resolution_due_at === null) {
            return 'none';
        }

        $now ??= now();
        $due = $case->first_responded_at === null && $case->first_response_due_at !== null && $case->first_response_due_at->lt($case->resolution_due_at)
            ? $case->first_response_due_at : $case->resolution_due_at;

        if ($due->lt($now)) {
            return 'overdue';
        }

        return $due->lte($now->copy()->addHours((int) config('trust_safety.sla.due_soon_hours'))) ? 'due_soon' : 'ok';
    }

    /** كل ما تحتاجه صفحة الحالة (للمشرفين فقط). استعلامات مجمّعة: لا N+1. @return array<string,mixed> */
    public function detail(ModerationCase $case): array
    {
        $limit = (int) config('trust_safety.lists.timeline_limit');
        $events = ModerationCaseEvent::query()->where('moderation_case_id', $case->id)->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        $evidence = ModerationEvidence::query()->where('moderation_case_id', $case->id)->orderBy('id')->limit((int) config('trust_safety.lists.evidence_limit'))->get();
        $actions = ModerationAction::query()->with('appeals')->where('moderation_case_id', $case->id)->orderBy('id')->get();
        $sources = ModerationCaseSource::query()->where('moderation_case_id', $case->id)->orderBy('id')->get();

        $userIds = $events->pluck('actor_id')->merge($evidence->pluck('added_by'))->merge($actions->pluck('performed_by'))->merge($sources->pluck('reporter_id'))
            ->push($case->assigned_to)->push($case->subject_user_id)->filter()->unique()->all();
        $names = $userIds === [] ? [] : User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all();

        return [
            'case' => $case,
            'subject' => $case->subject_type !== null ? $this->subjects->one($case->subject_type, (int) $case->subject_id) : null,
            'subject_user_name' => $case->subject_user_id !== null ? ($names[$case->subject_user_id] ?? null) : null,
            'assignee_name' => $case->assigned_to !== null ? ($names[$case->assigned_to] ?? null) : null,
            'sources' => $this->sourceRows($sources, $names),
            'events' => $events, 'evidence' => $evidence, 'actions' => $actions, 'names' => $names,
            'sla' => self::slaState($case),
        ];
    }

    // ───────────────────────── حواجز مشتركة ─────────────────────────

    /** لا تعامل مع حالة تخصّ المشرف نفسه (صاحب المحتوى/الحساب) ولا مع حالة هو مبلِّغ فيها (ولو فُصل المصدر لاحقًا). */
    public function assertCanWork(ModerationCase $case, User $user): void
    {
        $reporter = ModerationCaseSource::query()->where('moderation_case_id', $case->id)->where('reporter_id', $user->id)->exists();

        $this->assertNoConflictFor($user, $case->subject_user_id, $reporter ? [$user->id] : []);
    }

    /** المعيَّن للحالة أو المشرف الأعلى فقط يحرّكها. */
    public function assertCanDrive(ModerationCase $case, User $actor): void
    {
        if ((int) $case->assigned_to !== (int) $actor->id && ! $actor->can('trust-safety.supervise')) {
            throw new ModerationException('احجز الحالة أولًا (أو اطلب من المشرف الأعلى تعيينها لك).');
        }
    }

    public function assertActive(ModerationCase $case): void
    {
        if (! $case->isActive()) {
            throw new ModerationException('الحالة غير فعّالة.');
        }
    }

    // ───────────────────────── داخلي ─────────────────────────

    protected function gate(User $actor, string $permission): void
    {
        abort_unless($actor->can($permission), 403);
    }

    protected function lock(ModerationCase $case): ModerationCase
    {
        return ModerationCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
    }

    protected function record(ModerationCase $case, ?User $actor, string $type, ?string $from = null, ?string $to = null, ?string $note = null, array $meta = []): ModerationCaseEvent
    {
        $event = new ModerationCaseEvent;
        $event->forceFill([
            'moderation_case_id' => $case->id, 'actor_id' => $actor?->id, 'type' => $type, 'from_value' => $from, 'to_value' => $to,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'metadata' => $meta === [] ? null : $meta,
        ])->save();

        return $event;
    }

    /** يسجّل حدثًا من خدمات شقيقة (إجراءات/اعتراضات) بالتسلسل الزمني نفسه. */
    public function recordExternal(ModerationCase $case, ?User $actor, string $type, ?string $from = null, ?string $to = null, ?string $note = null, array $meta = []): ModerationCaseEvent
    {
        return $this->record($case, $actor, $type, $from, $to, $note, $meta);
    }

    protected function releaseSources(ModerationCase $case): void
    {
        ModerationCaseSource::query()->where('moderation_case_id', $case->id)->where('active_marker', 1)->update(['active_marker' => null]);
    }

    protected function numberFor(ModerationCase $case): string
    {
        return sprintf('%s-%s-%s', config('trust_safety.case.number_prefix'), $case->created_at?->format('Y') ?? now()->format('Y'), str_pad((string) $case->id, (int) config('trust_safety.case.number_pad'), '0', STR_PAD_LEFT));
    }

    /** @param list<string|array> $refs @return list<array{0:string,1:int}> */
    public function parseRefs(array $refs): array
    {
        $out = [];

        foreach ($refs as $ref) {
            if (is_string($ref)) {
                [$type, $id] = array_pad(explode(':', $ref, 2), 2, null);
            } elseif (is_array($ref)) {
                $type = $ref[0] ?? $ref['type'] ?? null;
                $id = $ref[1] ?? $ref['id'] ?? null;
            } else {
                throw new ModerationException('مرجع بلاغ غير صالح.');
            }

            if (! ReportSources::has(is_string($type) ? $type : null) || ! ctype_digit((string) $id) || (int) $id < 1) {
                throw new ModerationException('مرجع بلاغ غير صالح.');
            }

            $out[$type.':'.(int) $id] = [(string) $type, (int) $id];
        }

        if ($out === []) {
            throw new ModerationException('اختر بلاغًا واحدًا على الأقل.');
        }

        if (count($out) > 50) {
            throw new ModerationException('الحد الأقصى 50 بلاغًا في العملية الواحدة.');
        }

        return array_values($out);
    }

    /**
     * @param  list<array{0:string,1:int}>  $pairs
     * @return list<array{type:string,id:int,report:object,subject:array{0:string,1:int},reporter_id:?int,reason:string,severity:?string,category:string}>
     */
    protected function loadReports(array $pairs): array
    {
        $byType = [];

        foreach ($pairs as [$type, $id]) {
            $byType[$type][] = $id;
        }

        $out = [];

        foreach ($byType as $type => $ids) {
            $def = ReportSources::def($type);
            $models = $def['model']::query()->whereIn('id', $ids)->get()->keyBy('id');

            foreach ($ids as $id) {
                $report = $models->get($id) ?? throw new ModerationException('بلاغ غير موجود.');
                $severity = $def['system'] ? (string) $report->severity : null;
                $reason = (string) $report->{$def['reason_col']};

                $out[] = [
                    'type' => $type, 'id' => (int) $id, 'report' => $report,
                    'subject' => [$def['subject_type'], (int) $report->{$def['subject_col']}],
                    'reporter_id' => $def['reporter_col'] !== null && $report->{$def['reporter_col']} !== null ? (int) $report->{$def['reporter_col']} : null,
                    'reason' => $reason, 'severity' => $severity,
                    'category' => PolicyCatalog::normalizeCategory($type, $reason, $severity),
                ];
            }
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array{0:string,1:int} */
    protected function commonSubject(array $rows): array
    {
        $subjects = array_unique(array_map(fn ($r) => $r['subject'][0].':'.$r['subject'][1], $rows));

        if (count($subjects) !== 1) {
            throw new ModerationException('كل بلاغات الحالة يجب أن تخصّ الموضوع نفسه.');
        }

        return $rows[0]['subject'];
    }

    /** @param list<array<string,mixed>> $rows */
    protected function assertAllOpen(array $rows): void
    {
        foreach ($rows as $row) {
            if (! ReportSources::isOpen($row['report'], $row['type'])) {
                throw new ModerationException('يمكن ربط البلاغات المفتوحة فقط.');
            }
        }
    }

    /** @param list<array{0:string,1:int}> $pairs */
    protected function assertNotTaken(array $pairs): void
    {
        $taken = ModerationCaseSource::query()->where('active_marker', 1)->where(function ($q) use ($pairs) {
            foreach ($pairs as [$type, $id]) {
                $q->orWhere(fn ($x) => $x->where('source_type', $type)->where('source_id', $id));
            }
        })->exists();

        if ($taken) {
            throw new ModerationException('أحد البلاغات مرتبط بحالة فعّالة بالفعل.');
        }
    }

    /** @param list<?int> $reporterIds */
    protected function assertNoConflictFor(User $user, ?int $subjectUserId, array $reporterIds): void
    {
        if ($subjectUserId !== null && (int) $subjectUserId === (int) $user->id) {
            throw new ModerationException('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.');
        }

        if (in_array($user->id, array_map('intval', array_filter($reporterIds)), true)) {
            throw new ModerationException('لا يمكنك التعامل مع حالة أنت مبلِّغ فيها.');
        }
    }

    /** أعلى أولوية مقترحة بين المصادر (والتصنيف المرافق لها)؛ التساوي يحسمه ترتيب المصادر. @param list<array<string,mixed>> $rows @return array{0:string,1:string} */
    protected function pickCategoryAndPriority(array $rows): array
    {
        $best = null;

        foreach ($rows as $row) {
            $priority = PolicyCatalog::suggestPriority($row['category'], $row['severity']);

            if ($best === null || self::RANK[$priority] < self::RANK[$best[1]]) {
                $best = [$row['category'], $priority];
            }
        }

        return $best;
    }

    /** @param list<array<string,mixed>> $rows */
    protected function attachRows(ModerationCase $case, array $rows, User $actor): void
    {
        foreach ($rows as $row) {
            $source = new ModerationCaseSource;
            $source->forceFill([
                'moderation_case_id' => $case->id, 'source_type' => $row['type'], 'source_id' => $row['id'], 'reporter_id' => $row['reporter_id'],
                'reason_code' => mb_substr($row['reason'], 0, 32), 'category' => $row['category'], 'active_marker' => 1, 'added_by' => $actor->id,
            ])->save();
        }
    }

    /** لقطة نصية للموضوع لحظة الفتح (دليل للمشرفين فقط، إلحاقية، نص عادي) تبقى حتى لو عدّل صاحب المحتوى أو حذفه لاحقًا. @param list<array<string,mixed>> $rows @param array<string,mixed> $info */
    protected function snapshot(ModerationCase $case, array $rows, array $info): void
    {
        $text = $info['label'];
        $fromReport = collect($rows)->first(fn ($r) => $r['type'] === ModerationCaseSource::TYPE_DISCUSSION_REPORT && filled($r['report']->body_snapshot ?? null));
        $excerpt = $fromReport !== null ? (string) $fromReport['report']->body_snapshot : (string) ($info['excerpt'] ?? '');

        if ($excerpt !== '') {
            $text .= "\n".$excerpt;
        }

        $evidence = new ModerationEvidence;
        $evidence->forceFill([
            'moderation_case_id' => $case->id, 'added_by' => null, 'kind' => ModerationEvidence::KIND_SNAPSHOT,
            'body' => mb_substr($text, 0, (int) config('trust_safety.case.snapshot_max')),
            'source_type' => $rows[0]['type'], 'source_id' => $rows[0]['id'],
        ])->save();
    }

    /**
     * صفوف مصادر الحالة للعرض (المشرفون فقط): الحالة الحيّة من البلاغ الأصلي (لا نسخة)، واسم المبلِّغ (لا يصل للمستخدم المعني أبدًا).
     *
     * @param  \Illuminate\Support\Collection<int,ModerationCaseSource>  $sources
     * @param  array<int,string>  $names
     * @return list<array<string,mixed>>
     */
    protected function sourceRows($sources, array $names): array
    {
        $reports = [];

        foreach ($sources->groupBy('source_type') as $type => $group) {
            if (! ReportSources::has((string) $type)) {
                continue;
            }

            $reports[$type] = ReportSources::def((string) $type)['model']::query()->whereIn('id', $group->pluck('source_id')->all())->get()->keyBy('id');
        }

        return $sources->map(function (ModerationCaseSource $s) use ($reports, $names) {
            $report = $reports[$s->source_type][$s->source_id] ?? null;
            $normalized = $report === null ? 'deleted' : (ReportSources::isOpen($report, $s->source_type) ? 'open' : ($s->source_type === ModerationCaseSource::TYPE_FRAUD_FLAG ? 'resolved' : (string) $report->status));

            return [
                'id' => $s->id, 'type' => $s->source_type, 'label' => ReportSources::label($s->source_type), 'system' => ReportSources::isSystemSignal($s->source_type),
                'source_id' => $s->source_id, 'reporter_id' => $s->reporter_id, 'reporter_name' => $s->reporter_id !== null ? ($names[$s->reporter_id] ?? null) : null,
                'reason_code' => $s->reason_code, 'category' => $s->category, 'category_label' => PolicyCatalog::categoryLabel($s->category),
                'status' => $normalized, 'status_label' => $normalized === 'deleted' ? 'محذوف' : ReportSources::statusLabel($normalized),
                'details' => $report !== null ? PlainText::excerpt((string) ($report->details ?? ''), 500) : '',
                'active' => $s->active_marker === 1 || $s->active_marker === '1', 'detached' => $s->detached_at !== null, 'created_at' => $s->created_at,
            ];
        })->all();
    }
}
