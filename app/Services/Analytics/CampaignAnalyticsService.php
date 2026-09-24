<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignGateQualification;
use App\Models\Season;
use App\Models\UserCampaignProgress;
use App\Support\AnalyticsCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignAnalyticsService
{
    public function forCampaign(Campaign $campaign): array
    {
        return Cache::remember(AnalyticsCache::key("campaign:{$campaign->id}"), now()->addMinutes(10), function () use ($campaign) {
            $stepIds = DB::table('campaign_steps')
                ->join('campaign_gates', 'campaign_gates.id', '=', 'campaign_steps.campaign_gate_id')
                ->join('campaign_stages', 'campaign_stages.id', '=', 'campaign_gates.campaign_stage_id')
                ->where('campaign_stages.campaign_id', $campaign->id)
                ->pluck('campaign_steps.id');

            if ($stepIds->isEmpty()) {
                return $this->emptyCampaignStats();
            }

            $lastStepId = DB::table('campaign_steps')
                ->join('campaign_gates', 'campaign_gates.id', '=', 'campaign_steps.campaign_gate_id')
                ->join('campaign_stages', 'campaign_stages.id', '=', 'campaign_gates.campaign_stage_id')
                ->where('campaign_stages.campaign_id', $campaign->id)
                ->orderByDesc('campaign_stages.sort_order')
                ->orderByDesc('campaign_gates.sort_order')
                ->orderByDesc('campaign_steps.sort_order')
                ->value('campaign_steps.id');

            $starters = UserCampaignProgress::whereIn('campaign_step_id', $stepIds)->distinct('user_id')->count('user_id');
            $completers = $lastStepId
                ? UserCampaignProgress::where('campaign_step_id', $lastStepId)->whereNotNull('completed_at')->distinct('user_id')->count('user_id')
                : 0;

            $activeParticipants = UserCampaignProgress::whereIn('campaign_step_id', $stepIds)
                ->whereNull('completed_at')
                ->distinct('user_id')->count('user_id');

            $completedStepsCount = UserCampaignProgress::whereIn('campaign_step_id', $stepIds)->whereNotNull('completed_at')->count();

            return [
                'participants' => $starters,
                'started' => $starters,
                'completed' => $completers,
                'completion_rate' => $starters > 0 ? round(($completers / $starters) * 100, 1) : 0.0,
                'active_participants' => $activeParticipants,
                'completed_steps_total' => $completedStepsCount,
                'funnel' => $this->funnel($campaign),
            ];
        });
    }

    protected function emptyCampaignStats(): array
    {
        return [
            'participants' => 0, 'started' => 0, 'completed' => 0, 'completion_rate' => 0.0,
            'active_participants' => 0, 'completed_steps_total' => 0, 'funnel' => [],
        ];
    }

    public function funnel(Campaign $campaign): array
    {
        $steps = DB::table('campaign_steps')
            ->join('campaign_gates', 'campaign_gates.id', '=', 'campaign_steps.campaign_gate_id')
            ->join('campaign_stages', 'campaign_stages.id', '=', 'campaign_gates.campaign_stage_id')
            ->where('campaign_stages.campaign_id', $campaign->id)
            ->orderBy('campaign_stages.sort_order')
            ->orderBy('campaign_gates.sort_order')
            ->orderBy('campaign_steps.sort_order')
            ->select('campaign_steps.id', 'campaign_steps.title as step_title', 'campaign_gates.title as gate_title', 'campaign_stages.title as stage_title')
            ->get();

        if ($steps->isEmpty()) {
            return [];
        }

        $reachedCounts = UserCampaignProgress::whereIn('campaign_step_id', $steps->pluck('id'))
            ->select('campaign_step_id', DB::raw('COUNT(DISTINCT user_id) as reached'))
            ->groupBy('campaign_step_id')
            ->pluck('reached', 'campaign_step_id');

        return $steps->map(fn ($step) => [
            'stage' => $step->stage_title,
            'gate' => $step->gate_title,
            'step' => $step->step_title,
            'reached' => (int) ($reachedCounts[$step->id] ?? 0),
        ])->all();
    }

    public function seasonOverview(Season $season): array
    {
        $campaignStats = $this->forCampaign($season->campaign);

        $qualifications = CampaignGateQualification::whereHas(
            'gate.stage',
            fn ($q) => $q->where('campaign_id', $season->campaign_id)
        )
            ->select('campaign_gate_id', DB::raw('COUNT(*) as qualified_count'))
            ->groupBy('campaign_gate_id')
            ->with('gate:id,title,qualification_config')
            ->get()
            ->map(fn ($row) => [
                'gate' => $row->gate?->title,
                'qualified_count' => $row->qualified_count,
                'limit' => $row->gate?->qualification_config['limit'] ?? null,
                'occupancy_percent' => (($limit = $row->gate?->qualification_config['limit'] ?? null) && $limit > 0)
                    ? round(($row->qualified_count / $limit) * 100, 1)
                    : null,
            ]);

        return $campaignStats + ['qualifications' => $qualifications->all()];
    }

    public function allCampaignsSummary(): array
    {
        return Campaign::all(['id', 'title'])->map(fn (Campaign $c) => [
            'id' => $c->id,
            'title' => $c->title,
        ] + $this->forCampaign($c))->all();
    }
}