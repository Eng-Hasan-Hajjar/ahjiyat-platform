<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ChallengeController extends Controller
{
    public function index()
    {
        $now = now();

        // التحديات المفتوحة حالياً أولاً، ثم القادمة، ثم المنتهية مؤخراً (آخر 30 يوم) للأرشيف
        $challenges = Challenge::query()
            ->where('is_active', true)
            ->where('ends_at', '>=', $now->copy()->subDays(30))
            ->withCount('participants')
            ->orderByRaw("CASE WHEN starts_at <= ? AND ends_at >= ? THEN 0
                               WHEN starts_at > ? THEN 1
                               ELSE 2 END", [$now, $now, $now])
            ->orderBy('starts_at')
            ->paginate(9);

        return view('challenges.index', compact('challenges'));
    }

    public function show(Challenge $challenge)
    {
        $challenge->load(['puzzles.category']);

        $leaderboard = $challenge->participants()
            ->with('user:id,name')
            ->orderByDesc('score')
            ->limit(20)
            ->get();

        $user = Auth::user();
        $participation = $user
            ? $challenge->participants()->where('user_id', $user->id)->first()
            : null;

        return view('challenges.show', compact('challenge', 'leaderboard', 'participation'));
    }

    public function join(Challenge $challenge): RedirectResponse
    {
        $user = Auth::user();

        if (! $challenge->isOpen()) {
            return back()->with('error', 'هذا التحدي غير متاح للانضمام حالياً.');
        }

        $challenge->participants()->firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 0]
        );

        return redirect()
            ->route('challenges.show', $challenge)
            ->with('success', 'انضممت إلى التحدي! ابدأ بحل أحجياته لترتقي في لوحة صدارة التحدي.');
    }
}