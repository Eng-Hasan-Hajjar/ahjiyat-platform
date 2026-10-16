<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E28 - بلاغات أحجيات المجتمع. الأسباب مغلقة (CommunityPuzzleReport::REASONS). بلاغ مفتوح واحد لكل مستخدم/عمل (قيد قاعدة بيانات عبر open_marker)، ولا بلاغ على أحجيتك.
 * المعالجة (creators.review): رفض البلاغ / إجراء (اتُّخذ إجراء) / إجراء مع إخفاء المنشور فورًا (ويُغلق كل بلاغات العمل المفتوحة). كلها مدقَّقة.
 */
class CommunityReportService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected CreatorVisibilityService $visibility,
    ) {}

    public function report(User $reporter, CreatorPuzzle $work, string $reason, ?string $details): CommunityPuzzleReport
    {
        if (! array_key_exists($reason, CommunityPuzzleReport::REASONS)) {
            throw new CreatorException('سبب البلاغ غير صالح.');
        }

        if (! $work->is_visible) {
            throw new CreatorException('هذه الأحجية غير متاحة.');
        }

        $work->loadMissing('creator');

        if ($work->creator->user_id === $reporter->id) {
            throw new CreatorException('لا يمكنك الإبلاغ عن أحجيتك.');
        }

        $details = $details === null ? null : trim(preg_replace('/\s+/u', ' ', $details) ?? '');
        $details = $details === '' ? null : $details;

        if ($details !== null && mb_strlen($details) > (int) config('creators.limits.report_details_max')) {
            throw new CreatorException('تفاصيل البلاغ أطول من المسموح.');
        }

        $exists = CommunityPuzzleReport::query()->where('creator_puzzle_id', $work->id)->where('reporter_id', $reporter->id)->where('status', CommunityPuzzleReport::STATUS_OPEN)->exists();

        if ($exists) {
            throw new CreatorException('لديك بلاغ مفتوح على هذه الأحجية بالفعل.');
        }

        try {
            $report = new CommunityPuzzleReport;
            $report->forceFill([
                'creator_puzzle_id' => $work->id,
                'puzzle_id' => $work->published_puzzle_id,
                'reporter_id' => $reporter->id,
                'reason' => $reason,
                'details' => $details,
                'status' => CommunityPuzzleReport::STATUS_OPEN,
                'open_marker' => 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new CreatorException('لديك بلاغ مفتوح على هذه الأحجية بالفعل.');
        }

        return $report;
    }

    public function dismiss(CommunityPuzzleReport $report, User $actor, ?string $note = null): CommunityPuzzleReport
    {
        return $this->close($report, $actor, CommunityPuzzleReport::STATUS_DISMISSED, $note, 'community_report_dismissed');
    }

    public function action(CommunityPuzzleReport $report, User $actor, ?string $note = null): CommunityPuzzleReport
    {
        return $this->close($report, $actor, CommunityPuzzleReport::STATUS_ACTIONED, $note, 'community_report_actioned');
    }

    /** إجراء + إخفاء المنشور فورًا؛ يُغلق كل البلاغات المفتوحة على العمل كمُعالَجة. الإخفاء أولًا (معاملته وإشعاره بعد commit الخاص) ثم إغلاق البلاغ. */
    public function actionAndHide(CommunityPuzzleReport $report, User $actor, string $note): CommunityPuzzleReport
    {
        abort_unless($actor->can('creators.review'), 403);

        $note = trim($note);

        if (mb_strlen($note) < (int) config('creators.limits.note_min')) {
            throw new CreatorException('اكتب سببًا واضحًا للإخفاء.');
        }

        if ($report->fresh()?->status !== CommunityPuzzleReport::STATUS_OPEN) {
            throw new CreatorException('عولج هذا البلاغ بالفعل.');
        }

        $work = CreatorPuzzle::query()->findOrFail($report->creator_puzzle_id);

        if (! ($work->isHidden() && $work->hidden_reason === CreatorPuzzle::HIDDEN_MODERATION)) {
            $this->visibility->hide($work, $actor, $note);
        }

        $closed = $this->close($report, $actor, CommunityPuzzleReport::STATUS_ACTIONED, $note, 'community_report_actioned', ['hide' => true]);

        // بقية البلاغات المفتوحة على العمل نفسه تُغلق تباعًا (الإخفاء عالجها جميعًا).
        CommunityPuzzleReport::query()
            ->where('creator_puzzle_id', $work->id)->where('status', CommunityPuzzleReport::STATUS_OPEN)
            ->update(['status' => CommunityPuzzleReport::STATUS_ACTIONED, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => 'أُغلق مع إخفاء المنشور.']);

        return $closed;
    }

    protected function close(CommunityPuzzleReport $report, User $actor, string $status, ?string $note, string $auditAction, array $extra = []): CommunityPuzzleReport
    {
        abort_unless($actor->can('creators.review'), 403);

        $note = filled($note) ? trim((string) $note) : null;

        return DB::transaction(function () use ($report, $actor, $status, $note, $auditAction, $extra) {
            $locked = CommunityPuzzleReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($locked->status !== CommunityPuzzleReport::STATUS_OPEN) {
                throw new CreatorException('عولج هذا البلاغ بالفعل.');
            }

            $locked->forceFill([
                'status' => $status,
                'open_marker' => null,
                'handled_by' => $actor->id,
                'handled_at' => now(),
                'handler_note' => $note,
            ])->save();

            $this->audit->log($auditAction, $locked, ['work_id' => $locked->creator_puzzle_id, 'reason' => $locked->reason, 'note' => $note] + $extra, $actor);

            return $locked;
        });
    }
}
