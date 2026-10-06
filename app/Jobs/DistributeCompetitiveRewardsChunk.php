<?php

namespace App\Jobs;

use App\Models\CompetitiveEvent;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use App\Services\OperationalAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * يوزّع جوائز حدث معتمَد على دفعات بمؤشر معرّف النتيجة (لا آلاف المشاركين بطلب واحد ولا بذاكرة واحدة). آمن لإعادة المحاولة: السجل UNIQUE والادّعاء ذري،
 * فتكرار الوظيفة أو تشغيل عاملين بالتوازي لا يمنح مرتين. تدقيق بداية الاكتمال فقط (دفتر المحفظة يسجّل الأصل نفسه).
 */
class DistributeCompetitiveRewardsChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId, public int $afterResultId = 0) {}

    public function handle(CompetitiveRewardDistributionService $distribution, OperationalAuditService $audit): void
    {
        $event = CompetitiveEvent::query()->find($this->eventId);

        if ($event === null || ! $distribution->isDistributable($event)) {
            return;
        }

        if ($this->afterResultId === 0) {
            $audit->log('competitive_rewards_distribution_started', $event, ['rules' => $distribution->activeRules($event)->count()]);
        }

        $stats = $distribution->distributeChunk($event, $this->afterResultId);

        if (! $stats['done']) {
            self::dispatch($this->eventId, $stats['last_id']);

            return;
        }

        $audit->log('competitive_rewards_distribution_completed', $event, $distribution->counts($event));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
