<?php

namespace App\Services\Sponsorship;

use App\Models\CompetitiveEvent;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipProgram;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E30 - قفل خطة تعزيز منافسة **قبل بدئها** (مبدأ CompetitiveEvent::rewardsEditable()): يُسجَّل تمويل البرنامج ونسبته لحظة القفل، وتقرأ الجوائز النسبة المقفلة لا التمويل الحيّ.
 * مساهمة بعد القفل (أو استرداد) لا تغيّر جوائز المنافسة. منافسة بلا خطة مقفلة وقت التوزيع ⇒ بلا تعزيز (فشل مغلق). القفل نهائي (لا تعديل ولا إعادة قفل).
 * الأهداف: برامج هدفها هذه المنافسة نفسها حصرًا (برنامج المنصة/الموسم/الحملة لا يعزّز المنافسات). يقفل يدويًا مدير بصلاحية reward_plan أو آليًا الأمر المجدول.
 */
class EventRewardPlanService
{
    public function __construct(protected OperationalAuditService $audit, protected SupportTotals $totals) {}

    public function planFor(CompetitiveEvent $event): ?SponsorshipEventRewardPlan
    {
        return SponsorshipEventRewardPlan::query()->where('competitive_event_id', $event->getKey())->first();
    }

    /**
     * @throws SponsorshipException
     */
    public function lock(CompetitiveEvent $event, ?User $actor = null): SponsorshipEventRewardPlan
    {
        if ($actor !== null) {
            abort_unless($actor->can('sponsorships.reward_plan'), 403);
        }

        $plan = DB::transaction(function () use ($event, $actor) {
            $locked = CompetitiveEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            if (($existing = $this->planFor($locked)) !== null) {
                return $existing;
            }

            if (! $locked->rewardsEditable()) {
                throw new SponsorshipException('بدأت المنافسة أو انتهت؛ لا تُقفل خطة تعزيزها الآن (لا تعزيز لها).');
            }

            $programs = SponsorshipProgram::query()
                ->where('target_type', SponsorshipProgram::TARGET_COMPETITIVE_EVENT)->where('target_id', $locked->getKey())
                ->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true)->where('funding_step_minor', '>', 0)
                ->orderBy('id')->get();

            $funding = $this->totals->programFundingMany($programs->pluck('id')->all());
            $best = null;
            $bestBps = 0;

            foreach ($programs as $program) {
                $bps = SponsorshipRewardService::computeBps($funding[$program->id] ?? 0, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps);

                if ($bps > $bestBps) {
                    $best = $program;
                    $bestBps = $bps;
                }
            }

            $plan = new SponsorshipEventRewardPlan;
            $plan->forceFill([
                'competitive_event_id' => $locked->getKey(),
                'sponsorship_program_id' => $best?->id,
                'funding_snapshot_minor' => $best !== null ? (int) ($funding[$best->id] ?? 0) : 0,
                'funding_step_minor' => $best !== null ? (int) $best->funding_step_minor : 0,
                'boost_bps' => $bestBps,
                'locked_at' => now(),
                'locked_by' => $actor?->id,
            ])->save();

            foreach ($programs as $program) {
                $program->forceFill(['reward_plan_locked_at' => now(), 'reward_plan_locked_by' => $actor?->id])->save();
                $this->audit->log('sponsorship_reward_plan_locked', $program, ['event' => $locked->slug], $actor);
            }

            $this->audit->log('sponsorship_event_reward_plan_locked', $locked, [
                'program' => $best?->slug, 'boost_bps' => $bestBps, 'funding_snapshot_minor' => (int) $plan->funding_snapshot_minor,
            ], $actor);

            return $plan;
        });

        SponsorshipCache::bust();

        return $plan;
    }

    /**
     * الأمر المجدول: يقفل خطط المنافسات التي تبدأ خلال المدة المضبوطة (أو لا تزال قابلة للتعديل) ولها برنامج تعزيز نشط ولا خطة لها. يعيد العدد.
     */
    public function lockUpcoming(): int
    {
        $lead = max(1, (int) config('sponsorship.events.lock_lead_minutes', 60));
        $count = 0;

        CompetitiveEvent::query()
            ->whereIn('id', fn ($q) => $q->select('target_id')->from('sponsorship_programs')
                ->where('target_type', SponsorshipProgram::TARGET_COMPETITIVE_EVENT)->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true))
            ->whereNotIn('id', fn ($q) => $q->select('competitive_event_id')->from('sponsorship_event_reward_plans'))
            ->where('status', CompetitiveEvent::STATUS_PUBLISHED)->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes($lead))
            ->orderBy('id')->each(function (CompetitiveEvent $event) use (&$count) {
                try {
                    $this->lock($event);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
