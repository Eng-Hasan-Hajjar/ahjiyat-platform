<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\QuestDefinition;
use App\Models\Season;
use App\Models\UserQuestProgress;
use App\Services\CampaignProgressService;
use App\Services\Dashboard\PlayerDashboardService;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\StreakService;
use App\Services\PlatformSettingsService;
use App\Services\Progression\LevelService;

class HomeController extends Controller
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected PlatformSettingsService $settings,
        protected LevelService $levels,
        protected QuestPeriodService $periods,
        protected StreakService $streaks,
        protected PlayerDashboardService $dashboardService,
    ) {}

    public function index()
    {
        $home = $this->settings->getGroup('home');

        $dailyPuzzle = Puzzle::query()
            ->where('is_daily_puzzle', true)
            ->whereDate('daily_puzzle_date', today())
            ->where('is_active', true)
            ->first();

        $categories = $home['show_categories']
            ? PuzzleCategory::where('is_active', true)->orderBy('sort_order')->withCount('puzzles')->get()
            : collect();

        $myProgression = null;
        $myCurrentLevel = null;
        $myNextLevel = null;
        $myProgressPercent = 0;

        $myQuestsCompletedToday = 0;
        $myQuestsTotalToday = 0;
        $myStreak = null;

        if (auth()->check()) {
            $myProgression = $this->levels->progressionFor(auth()->user());
            // E24: التقدّم يُقرأ مرة واحدة ويُمرَّر (كان يُعاد جلبه 6 مرات مع كل استدعاء).
            $myCurrentLevel = $this->levels->currentLevelFor(auth()->user(), $myProgression);
            $myNextLevel = $this->levels->nextLevelFor(auth()->user(), $myProgression);
            $myProgressPercent = $this->levels->progressPercentFor(auth()->user(), $myProgression);

            // E13 (بند 374): للعرض فقط - لا إنشاء صفوف تقدُّم جديدة من لوحة التحكم.
            $dailyPeriod = $this->periods->dailyContext();
            $myQuestsTotalToday = QuestDefinition::where('period_type', QuestDefinition::PERIOD_DAILY)->where('is_active', true)->count();
            $myQuestsCompletedToday = UserQuestProgress::where('user_id', auth()->id())
                ->where('period_key', $dailyPeriod->periodKey)
                ->whereNotNull('completed_at')
                ->count();
            $myStreak = $this->streaks->streakFor(auth()->user());
        }

        $featuredSeason = null;
        $featuredSeasonCurrentStep = null;
        $featuredSeasonPercentage = 0;

        if ($home['show_featured_season']) {
            $featuredSeason = Season::with('campaign.stages.gates.steps')
                ->where('is_published', true)
                ->where('is_featured', true)
                ->get()
                ->first(fn (Season $season) => $this->progress->isCampaignAvailable($season->campaign));

            if ($featuredSeason && auth()->check()) {
                $user = auth()->user();
                $campaign = $featuredSeason->campaign;

                // E24: صفحة قراءة. لقطة تقدّم واحدة بدل استعلام لكل خطوة (كانت أكبر مصدر لاستعلامات الرئيسية).
                $this->progress->preloadFor($user, $campaign);

                $featuredSeasonCurrentStep = $this->progress->currentStepFor($user, $campaign);

                $totalSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(fn ($g) => $g->steps->count()));
                $completedSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(
                    fn ($g) => $g->steps->filter(fn ($step) => $this->progress->isStepCompleted($user, $step))->count()
                ));
                $featuredSeasonPercentage = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;
            }
        }

        // E22: لوحة اللاعب (عرض فقط) للمصادَقين فقط؛ الضيف بلا أي استعلام إضافي.
        $dashboard = auth()->check() ? $this->dashboardService->forUser(auth()->user()) : null;

        return view('home', compact(
            'dailyPuzzle', 'categories', 'featuredSeason', 'featuredSeasonCurrentStep', 'featuredSeasonPercentage', 'home',
            'myProgression', 'myCurrentLevel', 'myNextLevel', 'myProgressPercent',
            'myQuestsCompletedToday', 'myQuestsTotalToday', 'myStreak', 'dashboard',
        ));
    }
}