<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Services\CampaignProgressService;
use App\Services\PlatformSettingsService;
use App\Services\Progression\LevelService;

class HomeController extends Controller
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected PlatformSettingsService $settings,
        protected LevelService $levels,
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

        if (auth()->check()) {
            $myProgression = $this->levels->progressionFor(auth()->user());
            $myCurrentLevel = $this->levels->currentLevelFor(auth()->user());
            $myNextLevel = $this->levels->nextLevelFor(auth()->user());
            $myProgressPercent = $this->levels->progressPercentFor(auth()->user());
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

                $featuredSeasonCurrentStep = $this->progress->currentStepFor($user, $campaign);

                $totalSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(fn ($g) => $g->steps->count()));
                $completedSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(
                    fn ($g) => $g->steps->filter(fn ($step) => $this->progress->isStepCompleted($user, $step))->count()
                ));
                $featuredSeasonPercentage = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;
            }
        }

        return view('home', compact(
            'dailyPuzzle', 'categories', 'featuredSeason', 'featuredSeasonCurrentStep', 'featuredSeasonPercentage', 'home',
            'myProgression', 'myCurrentLevel', 'myNextLevel', 'myProgressPercent',
        ));
    }
}