<?php

namespace App\Services\Competitive;

use App\GameEngine\Support\AttemptContext;
use App\Models\CompetitiveEvent;
use App\Models\GameSession;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * إجراءات دورة حياة المنافسة بيد الإدارة (E17-B12): نشر/إلغاء/اعتماد النتائج كإجراءات Domain مفوَّضة بسياسة (لا تعديل خام للحالة) ومدقَّقة
 * بـOperationalAuditService. لا تعديل لأي نتيجة يدويًا (Baseline: لا تصحيح يدوي للنقاط). الإلغاء يوقف قبول النتائج دون محو التاريخ.
 */
class CompetitiveEventAdminService
{
    public function __construct(
        protected CompetitiveEligibility $eligibility,
        protected CompetitiveEventFinalizer $finalizer,
        protected OperationalAuditService $audit,
    ) {}

    public function publish(CompetitiveEvent $event, User $actor): CompetitiveEvent
    {
        abort_unless($actor->can('publish', $event), 403);

        if ($event->status !== CompetitiveEvent::STATUS_DRAFT) {
            throw new CompetitiveException('يمكن نشر المسوّدات فقط.');
        }

        if ($event->ends_at->lessThanOrEqualTo(now())) {
            throw new CompetitiveException('لا يمكن نشر منافسة انتهى وقتها.');
        }

        if (($reason = $this->eligibility->reasonIfIneligible($event->puzzle, requireActive: false)) !== null) {
            throw new CompetitiveException($reason);
        }

        $published = CompetitiveEvent::query()->whereKey($event->getKey())->where('status', CompetitiveEvent::STATUS_DRAFT)
            ->update(['status' => CompetitiveEvent::STATUS_PUBLISHED, 'published_at' => now()]);

        if ($published === 1) {
            $this->audit->log('competitive_event_published', $event, ['title' => $event->title, 'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String()], $actor);
        }

        return $event->refresh();
    }

    public function cancel(CompetitiveEvent $event, User $actor): CompetitiveEvent
    {
        abort_unless($actor->can('cancel', $event), 403);

        if (! in_array($event->status, [CompetitiveEvent::STATUS_DRAFT, CompetitiveEvent::STATUS_PUBLISHED], true)) {
            throw new CompetitiveException('لا يمكن إلغاء منافسة مُعتمدة أو مُلغاة.');
        }

        DB::transaction(function () use ($event, $actor) {
            $cancelled = CompetitiveEvent::query()->whereKey($event->getKey())->whereIn('status', [CompetitiveEvent::STATUS_DRAFT, CompetitiveEvent::STATUS_PUBLISHED])
                ->update(['status' => CompetitiveEvent::STATUS_CANCELLED]);

            if ($cancelled !== 1) {
                return;
            }

            GameSession::query()->where('context_type', AttemptContext::TYPE_COMPETITIVE_EVENT)->where('context_id', $event->getKey())
                ->where('status', GameSession::STATUS_ACTIVE)->update(['status' => GameSession::STATUS_EXPIRED, 'completed_at' => now()]);

            // أعداد حديثة من القاعدة (نسخة النموذج بالذاكرة قد تكون قديمة)
            $this->audit->log('competitive_event_cancelled', $event, ['participants' => $event->participants()->count(), 'results' => $event->results()->count()], $actor);
        });

        return $event->refresh();
    }

    /** اعتماد نتائج منافسة انتهى وقتها (يدويًا بدل انتظار الأمر الدوري). نفس المنفِّذ الحتمي. */
    public function finalize(CompetitiveEvent $event, User $actor): bool
    {
        abort_unless($actor->can('finalize', $event), 403);

        return $this->finalizer->finalize($event, $actor);
    }
}
