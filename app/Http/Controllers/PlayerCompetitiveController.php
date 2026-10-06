<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Competitive\CompetitiveStatsService;
use Illuminate\Http\Request;

/**
 * سجل المنافسات واللاعب (إحصاءات + خزانة جوائز + تاريخ مرقَّم). قراءة فقط، مشتق من نتائج معتمَدة؛ يتبع رؤية الملف الحالية (E11): ما لا يُرى ملفه 404.
 * وصف الجوائز الممنوحة وإحصاءات التحدّيات للمالك وحده؛ لا معرّفات سجل ولا مبالغ محافظ ولا بيانات أمنية.
 */
class PlayerCompetitiveController extends Controller
{
    public function show(Request $request, User $user, CompetitiveStatsService $stats)
    {
        $viewer = $request->user();
        abort_unless($user->profileViewableBy($viewer), 404);

        $isOwner = $viewer !== null && $viewer->getKey() === $user->getKey();

        return view('players.competitive', [
            'player' => $user,
            'isOwner' => $isOwner,
            'stats' => $stats->eventStats($user),
            'challenges' => $isOwner ? $stats->challengeStats($user) : null,
            'trophies' => $stats->trophies($user, withRewards: $isOwner),
            'history' => $stats->history($user),
        ]);
    }
}
