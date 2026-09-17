<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
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
        // E2: مواسم "قريباً"/"انتهى" تبقى ظاهرة بالقائمة العامة (تسويقياً)
        // بدل الاختفاء الكامل - is_published فقط يتحكم بالظهور من الأساس.
        // كل موسم يحمل حالته الخاصة (state) بدل فلترة ثنائية قديمة كانت
        // تُخفي أي موسم "قريباً" بالكامل.
        $seasons = Season::with('campaign')
            ->where('is_published', true)
            ->get()
            ->map(fn (Season $season) => (object) [
                'model' => $season,
                'state' => $this->availabilityState($season->campaign),
            ])
            ->sortByDesc(fn ($item) => $item->model->is_featured)
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
        $currentStepMissionNumber = $currentStep ? $this->progress->missionNumberFor($campaign, $currentStep) : null;

        $stagesView = $campaign->stages->sortBy('sort_order')->map(function ($stage) use ($user, $isAdmin) {
            return (object) [
                'model' => $stage,
                'unlocked' => $user && $this->progress->isStageUnlocked($user, $stage),
                'gates' => $stage->gates->sortBy('sort_order')->map(function ($gate) use ($user, $isAdmin) {
                    return (object) [
                        'model' => $gate,
                        'state' => $user ? $this->progress->gateState($user, $gate) : \App\Services\CampaignProgressService::STATE_LOCKED,
                        // Rank فقط إن كان المستخدم مؤهَّلاً فعلياً - null غير ذلك (E2 بند 17).
                        'qualifiedRank' => $user ? $this->progress->qualifiedRankFor($user, $gate) : null,
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

        $availabilityLabel = $this->availabilityLabel($campaign);

        return view('seasons.show', compact(
            'season', 'campaign', 'stagesView', 'currentStep', 'currentStepMissionNumber', 'percentage',
            'campaignAvailable', 'availabilityLabel', 'isAdmin'
        ));
    }

    /** @return 'live'|'upcoming'|'finished'|'inactive' */
    protected function availabilityState(Campaign $campaign): string
    {
        return match (true) {
            ! $campaign->is_active => 'inactive',
            $campaign->starts_at?->isFuture() => 'upcoming',
            $campaign->ends_at?->isPast() => 'finished',
            default => 'live',
        };
    }

    protected function availabilityLabel(Campaign $campaign): string
    {
        return match ($this->availabilityState($campaign)) {
            'inactive' => 'غير متاحة حالياً',
            'upcoming' => 'قريباً',
            'finished' => 'انتهى الموسم',
            default => 'مباشر الآن',
        };
    }
}