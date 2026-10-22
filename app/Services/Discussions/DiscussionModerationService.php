<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\Engagement\EngagementCounters;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E36 - الإشراف على النقاشات (صلاحية discussions.moderate، بالصلاحيات لا بأسماء الأدوار). كل إجراء: معاملة بقفل + تدقيق (OperationalAuditService) + إشعار الكاتب عند الإخفاء.
 *
 *  - **مالك المحتوى لا يملك أيًّا من هذا**: لا إخفاء ولا حذف ولا قفل لتعليق ينتقده (القاعدة 10/11). يبلّغ فقط والمنصة تقرّر. المحرّك الوحيد هذه الخدمة بصلاحية المنصة.
 *  - الإخفاء بـ**سبب مهيكل** من القائمة المغلقة (DiscussionCommentReport::REASONS) + ملاحظة اختيارية للتدقيق فقط. المشرف **لا يعيد كتابة** نص التعليق أبدًا.
 *  - الإخفاء حالة (status) لا حذف: النص يبقى للإشراف فقط ولا يخرج إلا من DiscussionReader كلحد محايد. الإخفاء يُنقص comments_count ويُغلق بلاغات التعليق المفتوحة كمعالَجة.
 *  - الاستعادة تعيد التعليق ظاهرًا وتزيد العدّاد، ولا تعيد فتح بلاغات أُغلقت (سياسة L3: الإغلاق نهائي؛ بلاغ جديد يُفتح إن لزم).
 *  - القفل على النقاش: القراءة تبقى، والتعليق/الرد/الإعجاب الجديد يُمنع (يُفحص تحت قفل صف النقاش). صاحب المحتوى لا يقفل ما لم تكن لديه صلاحية الإشراف بنفسه.
 *  - الأحداث: comment_hidden, comment_restored, thread_locked, thread_unlocked, comment_report_dismissed, comment_report_actioned.
 */
class DiscussionModerationService
{
    public function __construct(protected OperationalAuditService $audit, protected DiscussionNotifier $notifier) {}

    public function hide(DiscussionComment $comment, User $actor, string $reason, ?string $note = null): DiscussionComment
    {
        $this->authorize($actor);
        $this->assertReason($reason);
        $note = $this->cleanNote($note);

        $hidden = DB::transaction(function () use ($comment, $actor, $reason, $note) {
            $locked = DiscussionComment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isDeleted()) {
                throw new DiscussionException('حذف صاحبه هذا التعليق بالفعل.');
            }

            if ($locked->isHidden()) {
                throw new DiscussionException('هذا التعليق مخفي بالفعل.');
            }

            $locked->forceFill([
                'status' => DiscussionComment::STATUS_HIDDEN,
                'moderated_at' => now(),
                'moderated_by' => $actor->id,
                'moderation_reason' => $reason,
            ])->save();

            EngagementCounters::adjust('discussion_threads', (int) $locked->discussion_thread_id, ['comments_count' => -1]);

            // الإخفاء عالج كل بلاغات التعليق المفتوحة.
            DiscussionCommentReport::query()->where('discussion_comment_id', $locked->id)->where('status', DiscussionCommentReport::STATUS_OPEN)
                ->update(['status' => DiscussionCommentReport::STATUS_ACTIONED, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => 'أُغلق مع إخفاء التعليق.']);

            $this->audit->log('comment_hidden', $locked, ['comment' => $locked->public_id, 'thread_id' => $locked->discussion_thread_id, 'reason' => $reason, 'note' => $note], $actor);

            return $locked;
        });

        $this->notifier->hidden($hidden);

        return $hidden;
    }

    public function restore(DiscussionComment $comment, User $actor): DiscussionComment
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($comment, $actor) {
            $locked = DiscussionComment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isDeleted()) {
                throw new DiscussionException('حذف صاحبه هذا التعليق ولا يمكن استعادته.');
            }

            if (! $locked->isHidden()) {
                throw new DiscussionException('هذا التعليق غير مخفي.');
            }

            $locked->forceFill(['status' => DiscussionComment::STATUS_VISIBLE, 'moderated_at' => null, 'moderated_by' => null, 'moderation_reason' => null])->save();
            EngagementCounters::adjust('discussion_threads', (int) $locked->discussion_thread_id, ['comments_count' => 1]);
            $this->audit->log('comment_restored', $locked, ['comment' => $locked->public_id, 'thread_id' => $locked->discussion_thread_id], $actor);

            return $locked;
        });
    }

    public function lockThread(DiscussionThread $thread, User $actor, ?string $note = null): DiscussionThread
    {
        $this->authorize($actor);
        $note = $this->cleanNote($note);

        return DB::transaction(function () use ($thread, $actor, $note) {
            $locked = DiscussionThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_locked) {
                throw new DiscussionException('النقاش مقفل بالفعل.');
            }

            $locked->forceFill(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $actor->id, 'lock_reason' => $note])->save();
            $this->audit->log('thread_locked', $locked, ['subject_type' => $locked->subject_type, 'subject_id' => $locked->subject_id, 'note' => $note], $actor);

            return $locked;
        });
    }

    public function unlockThread(DiscussionThread $thread, User $actor): DiscussionThread
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($thread, $actor) {
            $locked = DiscussionThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_locked) {
                throw new DiscussionException('النقاش غير مقفل.');
            }

            $locked->forceFill(['is_locked' => false, 'locked_at' => null, 'locked_by' => null, 'lock_reason' => null])->save();
            $this->audit->log('thread_unlocked', $locked, ['subject_type' => $locked->subject_type, 'subject_id' => $locked->subject_id], $actor);

            return $locked;
        });
    }

    public function dismiss(DiscussionCommentReport $report, User $actor, ?string $note = null): DiscussionCommentReport
    {
        return $this->close($report, $actor, DiscussionCommentReport::STATUS_DISMISSED, $note, 'comment_report_dismissed');
    }

    public function action(DiscussionCommentReport $report, User $actor, ?string $note = null): DiscussionCommentReport
    {
        return $this->close($report, $actor, DiscussionCommentReport::STATUS_ACTIONED, $note, 'comment_report_actioned');
    }

    /** إجراء + إخفاء التعليق فورًا بسبب البلاغ نفسه (الإخفاء أولًا فيُغلق كل بلاغات التعليق المفتوحة، ثم يُسجَّل حسم هذا البلاغ). */
    public function actionAndHide(DiscussionCommentReport $report, User $actor, ?string $note = null): DiscussionCommentReport
    {
        $this->authorize($actor);

        if ($report->fresh()?->status !== DiscussionCommentReport::STATUS_OPEN) {
            throw new DiscussionException('عولج هذا البلاغ بالفعل.');
        }

        $comment = DiscussionComment::query()->findOrFail($report->discussion_comment_id);

        if ($comment->isLive()) {
            $this->hide($comment, $actor, (string) $report->reason, $note);
        }

        $closed = DiscussionCommentReport::query()->findOrFail($report->id);

        if ($closed->status === DiscussionCommentReport::STATUS_OPEN) {
            return $this->close($closed, $actor, DiscussionCommentReport::STATUS_ACTIONED, $note, 'comment_report_actioned');
        }

        $this->audit->log('comment_report_actioned', $closed, ['comment' => $comment->public_id, 'reason' => $closed->reason, 'note' => $this->cleanNote($note), 'hide' => true], $actor);

        return $closed;
    }

    protected function close(DiscussionCommentReport $report, User $actor, string $status, ?string $note, string $auditAction): DiscussionCommentReport
    {
        $this->authorize($actor);
        $note = $this->cleanNote($note);

        return DB::transaction(function () use ($report, $actor, $status, $note, $auditAction) {
            $locked = DiscussionCommentReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($locked->status !== DiscussionCommentReport::STATUS_OPEN) {
                throw new DiscussionException('عولج هذا البلاغ بالفعل.');
            }

            $locked->forceFill(['status' => $status, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => $note])->save();
            $this->audit->log($auditAction, $locked, ['comment_id' => $locked->discussion_comment_id, 'reason' => $locked->reason, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('discussions.moderate'), 403);
    }

    protected function assertReason(string $reason): void
    {
        if (! array_key_exists($reason, DiscussionCommentReport::REASONS)) {
            throw new DiscussionException('اختر سببًا من القائمة.');
        }
    }

    /** ملاحظة اختيارية نص عادي قصير (للتدقيق/ملاحظة المعالج). تُرفض الوسوم والمحارف الخاصة. */
    protected function cleanNote(?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note) ?? '');

        if ($note === '') {
            return null;
        }

        $max = (int) config('discussions.moderation.note_max');

        if (mb_strlen($note) > $max) {
            throw new DiscussionException('الملاحظة طويلة (الحدّ '.$max.' حرفًا).');
        }

        $bad = false;
        (new NoMarkup)->validate('note', $note, function () use (&$bad) {
            $bad = true;
        });

        if ($bad) {
            throw new DiscussionException('الملاحظة نص عادي فقط.');
        }

        return $note;
    }
}
