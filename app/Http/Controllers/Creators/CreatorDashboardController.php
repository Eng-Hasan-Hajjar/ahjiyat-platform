<?php

namespace App\Http\Controllers\Creators;

use App\Services\Creators\CreatorAnalyticsService;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Http\Request;

/** E28 - لوحة المبدع: عدّادات الحالات (استعلام مجمَّع واحد) + تحليلات مخزَّنة قصيرًا + آخر الأعمال. بلا أرباح/محفظة. */
class CreatorDashboardController extends CreatorController
{
    public function __construct(protected CreatorPuzzleService $puzzles, protected CreatorAnalyticsService $analytics) {}

    public function index(Request $request)
    {
        $profile = $this->profile($request);

        return view('creator.dashboard', [
            'profile' => $profile,
            'counts' => $this->puzzles->bucketCounts($profile),
            'stats' => $this->analytics->forCreator($profile),
            'recent' => $this->puzzles->recent($profile, 5),
        ]);
    }
}
