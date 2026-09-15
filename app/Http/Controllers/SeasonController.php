<?php

namespace App\Http\Controllers;

use App\Models\Season;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\Auth;

/**
 * طبقة عرض (Presentation Layer) حول Campaign - لا منطق Progression/Reward/
 * Qualification هون إطلاقاً. اللعب الفعلي يبقى بالكامل عبر مسارات
 * campaigns.steps.* الموجودة - لا تكرار.
 */
class SeasonController extends Controller
{
    public function __construct(protected CampaignProgressService $progress) {}

    public function index()
    {
        $seasons = Season::with('campaign')
            ->where('is_published', true)
            ->get()
            ->filter(fn (Season $season) => $this->progress->isCampaignAvailable($season->campaign))
            ->sortByDesc('is_featured')
            ->values();

        return view('seasons.index', compact('seasons'));
    }

    public function show(Season $season)
    {
        $user = Auth::user();
        $isAdmin = $user?->isAdmin() ?? false;

        // Admin يستطيع معاينة موسم غير منشور - الجمهور العام لا يرى موسمًا
        // غير منشور مهما كان، Server-side دائمًا (لا اعتماد على إخفاء الرابط).
        abort_unless($season->is_published || $isAdmin, 404);

        $season->load('campaign.stages.gates.steps.puzzle');
        $campaign = $season->campaign;

        $campaignAvailable = $this->progress->isCampaignAvailable($campaign);
        $currentStep = $user ? $this->progress->currentStepFor($user, $campaign) : null;

        $stagesView = $campaign->stages->sortBy('sort_order')->map(function ($stage) use ($user, $isAdmin) {
            return (object) [
                'model' => $stage,
                'unlocked' => $user && $this->progress->isStageUnlocked($user, $stage),
                'gates' => $stage->gates->sortBy('sort_order')->map(function ($gate) use ($user, $isAdmin) {
                    return (object) [
                        'model' => $gate,
                        'state' => $user ? $this->progress->gateState($user, $gate) : \App\Services\CampaignProgressService::STATE_LOCKED,
                        'steps' => $gate->steps->sortBy('sort_order')->map(function ($step) use ($user, $isAdmin) {
                            return (object) [
                                'model' => $step,
                                'state' => $user ? $this->progress->stepState($user, $step) : \App\Services\CampaignProgressService::STATE_LOCKED,
                                // Metadata إدارية فقط - أبداً لا تُمرَّر أو تُعرض لغير Admin
                                'contentStatus' => $isAdmin ? $step->contentStatus() : null,
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values();

        $totalSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(fn ($g) => $g->steps->count()));
        $completedSteps = $user
            ? $campaign->stages->sum(fn ($s) => $s->gates->sum(
                fn ($g) => $g->steps->filter(fn ($step) => $this->progress->isStepCompleted($user, $step))->count()
            ))
            : 0;
        $percentage = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;

        $availabilityLabel = match (true) {
            ! $campaign->is_active => 'غير متاحة حالياً',
            $campaign->starts_at?->isFuture() => 'قريباً',
            $campaign->ends_at?->isPast() => 'انتهى الموسم',
            default => 'مباشر الآن',
        };

        return view('seasons.show', compact(
            'season', 'campaign', 'stagesView', 'currentStep', 'percentage',
            'campaignAvailable', 'availabilityLabel', 'isAdmin'
        ));
    }
}