<?php

namespace App\Services\Competitive;

use App\Events\CompetitiveEventFinalized;
use App\GameEngine\Support\AttemptContext;
use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\GameSession;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * اعتماد النتائج النهائية (E17-C). حتمي ومتجدّد الأمان (Idempotent): الترتيب = النقاط تنازليًا، ثم المدة تصاعديًا، ثم وقت الإكمال، ثم المعرّف
 * (ترتيب كامل مستقر بلا تساوٍ). يُكتب final_rank مرة واحدة، وتُغلق الجلسات المفتوحة، وتنتقل الحالة published → completed بتحديث ذري، ولا يكتب
 * أحد الفائز يدويًا. لا جوائز اقتصادية. تشغيله ثانيةً لا يفعل شيئًا ولا يكرّر حدثًا أو إشعارًا. @return bool هل هذا الاستدعاء هو الذي اعتمد
 */
class CompetitiveEventFinalizer
{
    public function __construct(protected OperationalAuditService $audit) {}

    public function finalize(CompetitiveEvent $event, ?User $actor = null): bool
    {
        $done = DB::transaction(function () use ($event, $actor) {
            $locked = CompetitiveEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            // لا اعتماد قبل انتهاء الوقت، ولا إعادة اعتماد، ولا اعتماد منافسة ملغاة.
            if ($locked->status !== CompetitiveEvent::STATUS_PUBLISHED || $locked->phase() !== CompetitiveEvent::PHASE_ENDED) {
                return false;
            }

            $ids = CompetitiveEventResult::query()->where('competitive_event_id', $locked->getKey())
                ->orderByDesc('score')->orderBy('duration_ms')->orderBy('completed_at')->orderBy('id')->pluck('id');

            foreach ($ids as $position => $id) {
                CompetitiveEventResult::query()->whereKey($id)->update(['final_rank' => $position + 1]);
            }

            GameSession::query()->where('context_type', AttemptContext::TYPE_COMPETITIVE_EVENT)->where('context_id', $locked->getKey())
                ->where('status', GameSession::STATUS_ACTIVE)->update(['status' => GameSession::STATUS_EXPIRED, 'completed_at' => now()]);

            $updated = CompetitiveEvent::query()->whereKey($locked->getKey())->where('status', CompetitiveEvent::STATUS_PUBLISHED)
                ->update(['status' => CompetitiveEvent::STATUS_COMPLETED, 'finalized_at' => now()]);

            if ($updated !== 1) {
                return false;
            }

            $this->audit->log('competitive_event_finalized', $locked, ['participants' => $locked->participants_count, 'results' => $ids->count()], $actor);

            DB::afterCommit(function () use ($locked) {
                try {
                    event(new CompetitiveEventFinalized($locked->getKey()));
                } catch (\Throwable $e) {
                    report($e); // الإشعارات ثانوية: فشلها لا يمسّ النتائج المعتمدة
                }
            });

            return true;
        });

        return $done;
    }
}
