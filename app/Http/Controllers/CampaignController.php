<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\Auth;

/**
 * القراءة العامة (index/show) - Guest يستطيع رؤية حملة متاحة (C8.2، تسويقياً
 * مستقبلاً). كل الـWrite (complete/attempt/session) تبقى بالكامل داخل
 * auth+verified عبر CampaignStepController (C3/C4) - صفر تغيير هون.
 */
class CampaignController extends Controller
{
    public function __construct(protected CampaignProgressService $progress) {}

    public function index()
    {
        $campaigns = Campaign::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest()
            ->paginate(12);

        return view('campaigns.index', compact('campaigns'));
    }

    public function show(Campaign $campaign)
    {
        abort_unless($this->progress->isCampaignAvailable($campaign), 404);

        // Eager load واحد للهرم كاملاً (C8.16) - CampaignProgressService يستفيد
        // منه تلقائياً (يقرأ خاصية العلاقة لا يستعلمها من جديد).
        $campaign->load('stages.gates.steps');

        $user = Auth::user();
        $currentStep = $user ? $this->progress->currentStepFor($user, $campaign) : null;

        $stagesView = $campaign->stages->sortBy('sort_order')->map(function ($stage) use ($user) {
            return (object) [
                'model' => $stage,
                'unlocked' => $user && $this->progress->isStageUnlocked($user, $stage),
                'gates' => $stage->gates->sortBy('sort_order')->map(function ($gate) use ($user) {
                    return (object) [
                        'model' => $gate,
                        'state' => $user ? $this->progress->gateState($user, $gate) : CampaignProgressService::STATE_LOCKED,
                        'steps' => $gate->steps->sortBy('sort_order')->map(function ($step) use ($user) {
                            return (object) [
                                'model' => $step,
                                'state' => $user ? $this->progress->stepState($user, $step) : CampaignProgressService::STATE_LOCKED,
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

        return view('campaigns.show', compact('campaign', 'stagesView', 'currentStep', 'percentage'));
    }
}