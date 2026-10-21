<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Services\Engagement\EngagementException;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E35 - الإشراف على المجموعات (صلاحية collections.moderate، بالصلاحيات لا بأسماء الأدوار). كل إجراء: معاملة بقفل + تدقيق (OperationalAuditService) + إشعار المالك بعد الـcommit (للإخفاء/الاستعادة فقط).
 *
 *  - **لا يعيد المشرف كتابة النص**: العنوان والوصف لا يُعدَّلان هنا أبدًا. الإخفاء علَم مستقل (is_moderation_hidden) + سبب نصي عادي يراه المالك فقط.
 *  - المالك لا يستطيع رفع الإخفاء ولا إعادة نشرها (CollectionVisibilityService)؛ يستطيع تعديلها وحذفها وجعلها خاصة.
 *  - الإخفاء يخرج المجموعة فورًا من كل سطح عام (CollectionAccess حيّ بلا كاش يتجاوزه)، ويُغلق كل بلاغاتها المفتوحة كمعالَجة.
 *  - الاستعادة تعيدها إلى visibility التي اختارها المالك (لا تغيّر الظهور).
 *  - الأحداث: collection_hidden, collection_restored, collection_report_dismissed, collection_report_actioned.
 */
class CollectionModerationService
{
    public function __construct(protected OperationalAuditService $audit, protected CollectionNotifier $notifier) {}

    public function hide(UserCollection $collection, User $actor, string $reason): UserCollection
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        $hidden = DB::transaction(function () use ($collection, $actor, $reason) {
            $locked = UserCollection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_moderation_hidden) {
                throw new EngagementException('هذه المجموعة مخفية بالفعل.');
            }

            $locked->forceFill([
                'is_moderation_hidden' => true,
                'moderation_reason' => $reason,
                'moderation_hidden_at' => now(),
                'moderation_hidden_by' => $actor->id,
            ])->save();

            // الإخفاء عالج كل البلاغات المفتوحة عليها.
            UserCollectionReport::query()->where('user_collection_id', $locked->id)->where('status', UserCollectionReport::STATUS_OPEN)
                ->update(['status' => UserCollectionReport::STATUS_ACTIONED, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => 'أُغلق مع إخفاء المجموعة.']);

            $this->audit->log('collection_hidden', $locked, ['collection' => $locked->public_id, 'reason' => $reason], $actor);

            return $locked;
        });

        CollectionDiscovery::flush();
        CollectionAnalytics::flush();
        $this->notifier->hidden($hidden);

        return $hidden;
    }

    public function restore(UserCollection $collection, User $actor): UserCollection
    {
        $this->authorize($actor);

        $restored = DB::transaction(function () use ($collection, $actor) {
            $locked = UserCollection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_moderation_hidden) {
                throw new EngagementException('هذه المجموعة غير مخفية.');
            }

            $locked->forceFill(['is_moderation_hidden' => false, 'moderation_reason' => null, 'moderation_hidden_at' => null, 'moderation_hidden_by' => null])->save();
            $this->audit->log('collection_restored', $locked, ['collection' => $locked->public_id], $actor);

            return $locked;
        });

        CollectionDiscovery::flush();
        CollectionAnalytics::flush();
        $this->notifier->restored($restored);

        return $restored;
    }

    public function dismiss(UserCollectionReport $report, User $actor, ?string $note = null): UserCollectionReport
    {
        return $this->close($report, $actor, UserCollectionReport::STATUS_DISMISSED, $note, 'collection_report_dismissed');
    }

    public function action(UserCollectionReport $report, User $actor, ?string $note = null): UserCollectionReport
    {
        return $this->close($report, $actor, UserCollectionReport::STATUS_ACTIONED, $note, 'collection_report_actioned');
    }

    /** إجراء + إخفاء المجموعة فورًا (يُغلق كل بلاغاتها المفتوحة). الإخفاء أولًا (معاملته وإشعاره بعد commit الخاص) ثم حسم هذا البلاغ. */
    public function actionAndHide(UserCollectionReport $report, User $actor, string $reason): UserCollectionReport
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        if ($report->fresh()?->status !== UserCollectionReport::STATUS_OPEN) {
            throw new EngagementException('عولج هذا البلاغ بالفعل.');
        }

        $collection = UserCollection::query()->findOrFail($report->user_collection_id);

        if (! $collection->is_moderation_hidden) {
            $this->hide($collection, $actor, $reason);
        }

        $closed = UserCollectionReport::query()->findOrFail($report->id);

        // hide() أغلق كل البلاغات المفتوحة (ومنها هذا) فنسجّل حدث حسمه بالتدقيق؛ وإن بقي مفتوحًا (مجموعة مخفية سابقًا) فيُحسم الآن.
        if ($closed->status === UserCollectionReport::STATUS_OPEN) {
            return $this->close($closed, $actor, UserCollectionReport::STATUS_ACTIONED, $reason, 'collection_report_actioned');
        }

        $this->audit->log('collection_report_actioned', $closed, ['collection' => $collection->public_id, 'reason' => $closed->reason, 'note' => $reason, 'hide' => true], $actor);

        return $closed;
    }

    protected function close(UserCollectionReport $report, User $actor, string $status, ?string $note, string $auditAction): UserCollectionReport
    {
        $this->authorize($actor);
        $note = filled($note) ? mb_substr(trim((string) $note), 0, (int) config('collections.reports.note_max')) : null;

        return DB::transaction(function () use ($report, $actor, $status, $note, $auditAction) {
            $locked = UserCollectionReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($locked->status !== UserCollectionReport::STATUS_OPEN) {
                throw new EngagementException('عولج هذا البلاغ بالفعل.');
            }

            $locked->forceFill(['status' => $status, 'open_marker' => null, 'handled_by' => $actor->id, 'handled_at' => now(), 'handler_note' => $note])->save();
            $this->audit->log($auditAction, $locked, ['collection_id' => $locked->user_collection_id, 'reason' => $locked->reason, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('collections.moderate'), 403);
    }

    protected function requireReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $min = (int) config('collections.reports.note_min');
        $max = (int) config('collections.reports.note_max');

        if (mb_strlen($reason) < $min || mb_strlen($reason) > $max) {
            throw new EngagementException('اكتب سببًا واضحًا ('.$min.'–'.$max.' حرفًا).');
        }

        if ($reason !== strip_tags($reason)) {
            throw new EngagementException('السبب نص عادي فقط.');
        }

        return $reason;
    }
}
