<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E38 - اعتراضات المستخدمين على الإجراءات.
 *
 *  - المؤهَّل: صاحب الإجراء المعنيّ فقط، على إجراء ظاهر له وقابل للاعتراض وضمن المهلة (config) وغير مُعكَّس. نص عادي فقط، بلا مرفقات.
 *  - اعتراض فعّال واحد لكل إجراء (active_marker + unique بالقاعدة). بعد قرار نهائي لا اعتراض ثانٍ (allow_reappeal=false) فلا يتحوّل المسار إلى مضايقة للمشرفين.
 *  - المراجِع: لا يراجع اعتراضًا على إجراء نفّذه بنفسه إلا المشرف الأعلى (يُوسَم conflict_flag ويُسجَّل)، ولا يراجع من هو صاحب الحالة/المبلِّغ فيها، ولا يراجع اعتراضه الخاص أبدًا.
 *  - القبول يعكس الإجراء **عبر خدمة المجال الرسمية** ويُبقي التاريخ كاملًا (الأصل يُوسَم معكوسًا + إجراء عاكس جديد). الرفض يترك الإجراء كما هو.
 *  - حجز المراجعة ذرّي (UPDATE مشروط) فلا يراجع مشرفان الاعتراض نفسه.
 */
class AppealService
{
    public function __construct(
        protected CaseService $cases,
        protected EnforcementService $enforcement,
        protected ModerationNotifier $notifier,
        protected OperationalAuditService $audit,
    ) {}

    // ───────────────────────── جهة المستخدم ─────────────────────────

    /** هل يستطيع هذا المستخدم الاعتراض الآن؟ يعيد null إن نعم، وإلا سبب مقروء. */
    public function ineligibleReason(User $user, ModerationAction $action): ?string
    {
        if ((int) $action->target_user_id !== (int) $user->id || ! $action->user_visible) {
            return 'هذا الإجراء لا يخصّ حسابك.';
        }

        if (! (bool) config('trust_safety.actions.'.$action->action.'.appealable', false)) {
            return 'هذا الإجراء غير قابل للاعتراض.';
        }

        if ($action->isReversed()) {
            return 'أُلغي هذا الإجراء بالفعل.';
        }

        if ($action->appealable_until === null || $action->appealable_until->isPast()) {
            return 'انتهت مهلة الاعتراض على هذا الإجراء.';
        }

        $appeals = ModerationAppeal::query()->where('moderation_action_id', $action->id)->get(['status']);

        if ($appeals->contains(fn ($a) => in_array($a->status, ModerationAppeal::ACTIVE_STATUSES, true))) {
            return 'لديك اعتراض قيد المراجعة على هذا الإجراء.';
        }

        if (! (bool) config('trust_safety.appeals.allow_reappeal') && $appeals->contains(fn ($a) => in_array($a->status, [ModerationAppeal::STATUS_ACCEPTED, ModerationAppeal::STATUS_REJECTED], true))) {
            return 'صدر قرار نهائي في اعتراضك على هذا الإجراء.';
        }

        return null;
    }

    public function file(User $user, ModerationAction $action, string $reason): ModerationAppeal
    {
        $reason = PlainText::clean($reason, (int) config('trust_safety.appeals.reason_min'), (int) config('trust_safety.appeals.reason_max'), 'نص الاعتراض');

        try {
            $appeal = DB::transaction(function () use ($user, $action, $reason) {
                $locked = ModerationAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();

                if (($why = $this->ineligibleReason($user, $locked)) !== null) {
                    throw new ModerationException($why);
                }

                $appeal = new ModerationAppeal;
                $appeal->forceFill([
                    'moderation_action_id' => $locked->id, 'appellant_id' => $user->id, 'reason' => $reason,
                    'status' => ModerationAppeal::STATUS_PENDING, 'active_marker' => 1,
                ])->save();

                $case = ModerationCase::query()->findOrFail($locked->moderation_case_id);
                $this->cases->recordExternal($case, $user, 'appeal_filed', null, ModerationAppeal::STATUS_PENDING, null, ['appeal' => $appeal->public_id, 'action' => $locked->public_id]);
                $this->audit->log('moderation_appeal_filed', $appeal, ['action' => $locked->public_id, 'case' => $case->public_id], $user);

                return $appeal;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ModerationException('لديك اعتراض قيد المراجعة على هذا الإجراء.');
        }

        return $appeal;
    }

    public function withdraw(User $user, ModerationAppeal $appeal): ModerationAppeal
    {
        return DB::transaction(function () use ($user, $appeal) {
            $locked = ModerationAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->appellant_id !== (int) $user->id) {
                throw new ModerationException('هذا الاعتراض ليس لك.');
            }

            if ($locked->status !== ModerationAppeal::STATUS_PENDING) {
                throw new ModerationException('لا يمكن سحب الاعتراض بعد بدء مراجعته.');
            }

            $locked->forceFill(['status' => ModerationAppeal::STATUS_WITHDRAWN, 'active_marker' => null, 'decided_at' => now()])->save();
            $action = ModerationAction::query()->findOrFail($locked->moderation_action_id);
            $case = ModerationCase::query()->findOrFail($action->moderation_case_id);
            $this->cases->recordExternal($case, $user, 'appeal_withdrawn', ModerationAppeal::STATUS_PENDING, ModerationAppeal::STATUS_WITHDRAWN, null, ['appeal' => $locked->public_id]);

            return $locked;
        });
    }

    // ───────────────────────── جهة المراجعة ─────────────────────────

    /** حجز ذرّي للمراجعة. @return ModerationAppeal */
    public function claim(ModerationAppeal $appeal, User $reviewer): ModerationAppeal
    {
        abort_unless($reviewer->can('trust-safety.review-appeals'), 403);

        $fresh = ModerationAppeal::query()->findOrFail($appeal->id);
        $action = ModerationAction::query()->findOrFail($fresh->moderation_action_id);
        $conflict = $this->assertReviewer($fresh, $action, $reviewer);

        if ((int) $fresh->reviewer_id === (int) $reviewer->id && $fresh->status === ModerationAppeal::STATUS_IN_REVIEW) {
            return $fresh;
        }

        $now = now()->toDateTimeString();
        $affected = ModerationAppeal::query()
            ->whereKey($fresh->id)->where('status', ModerationAppeal::STATUS_PENDING)->whereNull('reviewer_id')
            ->update(['status' => ModerationAppeal::STATUS_IN_REVIEW, 'reviewer_id' => $reviewer->id, 'claimed_at' => $now, 'conflict_flag' => $conflict, 'updated_at' => $now]);

        if ($affected !== 1) {
            throw new ModerationException('حُجز هذا الاعتراض من مراجع آخر أو لم يعد معلّقًا.');
        }

        $claimed = ModerationAppeal::query()->findOrFail($fresh->id);
        $case = ModerationCase::query()->findOrFail($action->moderation_case_id);
        $this->cases->recordExternal($case, $reviewer, 'appeal_claimed', ModerationAppeal::STATUS_PENDING, ModerationAppeal::STATUS_IN_REVIEW, null, ['appeal' => $claimed->public_id, 'conflict_override' => $conflict]);

        if ($conflict) {
            $this->audit->log('moderation_appeal_conflict_override', $claimed, ['appeal' => $claimed->public_id, 'action' => $action->public_id], $reviewer);
        }

        return $claimed;
    }

    /**
     * قرار الاعتراض. message = ما يراه المستخدم (إلزامي)، note = داخلية للمشرفين (اختيارية).
     * القبول يعكس الإجراء عبر خدمة المجال بنفس المعاملة (فشل العكس يُرجع القرار كله فلا يبقى «مقبول» بلا أثر).
     */
    public function decide(ModerationAppeal $appeal, User $reviewer, bool $accept, string $message, ?string $note = null): ModerationAppeal
    {
        abort_unless($reviewer->can('trust-safety.review-appeals'), 403);

        $message = PlainText::clean($message, (int) config('trust_safety.appeals.decision_min'), (int) config('trust_safety.appeals.decision_max'), 'رسالة القرار');
        $note = PlainText::optional($note, (int) config('trust_safety.appeals.decision_max'), 'الملاحظة الداخلية');

        $reversal = null;
        $original = null;

        $decided = DB::transaction(function () use ($appeal, $reviewer, $accept, $message, $note, &$reversal, &$original) {
            $locked = ModerationAppeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new ModerationException('تم البتّ في هذا الاعتراض بالفعل.');
            }

            $original = ModerationAction::query()->whereKey($locked->moderation_action_id)->lockForUpdate()->firstOrFail();
            $conflict = $this->assertReviewer($locked, $original, $reviewer);

            if ($locked->status === ModerationAppeal::STATUS_PENDING) {
                $locked->forceFill(['status' => ModerationAppeal::STATUS_IN_REVIEW, 'reviewer_id' => $reviewer->id, 'claimed_at' => now(), 'conflict_flag' => $conflict])->save();
            } elseif ((int) $locked->reviewer_id !== (int) $reviewer->id && ! $reviewer->can('trust-safety.supervise')) {
                throw new ModerationException('هذا الاعتراض محجوز لمراجع آخر.');
            }

            if ($accept) {
                $reversal = $this->enforcement->reverseForAppeal($original, $reviewer, $locked);
            }

            $locked->forceFill([
                'status' => $accept ? ModerationAppeal::STATUS_ACCEPTED : ModerationAppeal::STATUS_REJECTED,
                'active_marker' => null, 'decided_at' => now(), 'reviewer_id' => $locked->reviewer_id ?? $reviewer->id,
                'conflict_flag' => $locked->conflict_flag || $conflict, 'decision_message' => $message, 'decision_note' => $note,
            ])->save();

            $case = ModerationCase::query()->findOrFail($original->moderation_case_id);
            $this->cases->recordExternal($case, $reviewer, 'appeal_decided', ModerationAppeal::STATUS_IN_REVIEW, $locked->status, $note, ['appeal' => $locked->public_id, 'action' => $original->public_id]);
            $this->audit->log('moderation_appeal_decided', $locked, ['appeal' => $locked->public_id, 'action' => $original->public_id, 'decision' => $locked->status, 'conflict' => (bool) $locked->conflict_flag], $reviewer);

            return $locked;
        });

        if ($accept) {
            $this->enforcement->afterReversal($reversal, $original);
        }

        $this->notifier->appealDecided($decided);

        return $decided;
    }

    /**
     * قواعد تضارب المراجِع. تعيد true إن كان استثناءً موسومًا (مشرف أعلى يراجع إجراءه).
     * الممنوع مطلقًا: صاحب الاعتراض، صاحب الحالة، المبلِّغ فيها.
     */
    public function assertReviewer(ModerationAppeal $appeal, ModerationAction $action, User $reviewer): bool
    {
        if ((int) $appeal->appellant_id === (int) $reviewer->id) {
            throw new ModerationException('لا يمكنك مراجعة اعتراضك الخاص.');
        }

        $case = ModerationCase::query()->findOrFail($action->moderation_case_id);
        $this->cases->assertCanWork($case, $reviewer);

        if ((int) $action->performed_by === (int) $reviewer->id) {
            if (! $reviewer->can('trust-safety.supervise')) {
                throw new ModerationException('لا يمكنك مراجعة اعتراض على إجراء نفّذته بنفسك؛ يحوَّل لمراجع آخر.');
            }

            return true;
        }

        return false;
    }

    // ───────────────────────── القراءة ─────────────────────────

    /** @return LengthAwarePaginator<int, ModerationAppeal> */
    public function queue(User $actor, array $input): LengthAwarePaginator
    {
        abort_unless($actor->can('trust-safety.review-appeals') || $actor->can('trust-safety.view'), 403);

        $view = in_array($input['view'] ?? 'active', ['active', 'pending', 'in_review', 'mine', 'decided', 'all'], true) ? ($input['view'] ?? 'active') : 'active';
        $perPage = max(1, min((int) ($input['per_page'] ?? config('trust_safety.lists.appeals_per_page')), 100));

        $query = ModerationAppeal::query()->with(['action.moderationCase:id,public_id,case_number,category', 'appellant:id,name', 'reviewer:id,name']);

        match ($view) {
            'pending' => $query->where('status', ModerationAppeal::STATUS_PENDING),
            'in_review' => $query->where('status', ModerationAppeal::STATUS_IN_REVIEW),
            'mine' => $query->where('reviewer_id', $actor->id)->whereIn('status', ModerationAppeal::ACTIVE_STATUSES),
            'decided' => $query->whereIn('status', [ModerationAppeal::STATUS_ACCEPTED, ModerationAppeal::STATUS_REJECTED, ModerationAppeal::STATUS_WITHDRAWN]),
            'all' => null,
            default => $query->whereIn('status', ModerationAppeal::ACTIVE_STATUSES),
        };

        return $query->orderBy('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', max(1, (int) ($input['page'] ?? 1)));
    }

    /** @return array{pending:int,in_review:int,mine:int} */
    public function counts(User $actor): array
    {
        $row = ModerationAppeal::query()->whereIn('status', ModerationAppeal::ACTIVE_STATUSES)->selectRaw(
            "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_total, SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) as review_total, SUM(CASE WHEN reviewer_id = ? THEN 1 ELSE 0 END) as mine_total",
            [$actor->id]
        )->first();

        return ['pending' => (int) ($row->pending_total ?? 0), 'in_review' => (int) ($row->review_total ?? 0), 'mine' => (int) ($row->mine_total ?? 0)];
    }
}
