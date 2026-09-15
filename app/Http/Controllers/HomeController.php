<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\Season;
use App\Services\CampaignProgressService;

class HomeController extends Controller
{
    public function __construct(protected CampaignProgressService $progress) {}

    public function index()
    {
        $dailyPuzzle = Puzzle::query()
            ->where('is_daily_puzzle', true)
            ->whereDate('daily_puzzle_date', today())
            ->where('is_active', true)
            ->first();

        $categories = PuzzleCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->withCount('puzzles')
            ->get();

        $featuredSeason = Season::with('campaign.stages.gates.steps')
            ->where('is_published', true)
            ->where('is_featured', true)
            ->get()
            ->first(fn (Season $season) => $this->progress->isCampaignAvailable($season->campaign));

        $featuredSeasonCurrentStep = null;
        $featuredSeasonPercentage = 0;

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

        return view('home', compact(
            'dailyPuzzle', 'categories', 'featuredSeason', 'featuredSeasonCurrentStep', 'featuredSeasonPercentage'
        ));
    }
}