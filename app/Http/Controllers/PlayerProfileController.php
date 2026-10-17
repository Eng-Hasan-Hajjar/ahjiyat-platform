<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\PlayerIdentity\PlayerProfileService;
use App\Services\Competitive\CompetitiveStatsService;
use App\Services\Social\FriendshipService;
use Illuminate\Support\Facades\Auth;

class PlayerProfileController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected PlayerProfileService $stats,
        protected FriendshipService $friendships,
        protected CompetitiveStatsService $competitive,
    ) {}

    public function show(User $user)
    {
        abort_unless($user->profileViewableBy(Auth::user()), 404);

        $loadout = $this->loadouts->loadoutFor($user);
        $stats = $this->stats->safeStatsFor($user);

        $viewer = Auth::user();
        $isOwner = $viewer !== null && $viewer->id === $user->id;

        return view('players.show', [
            'player' => $user,
            'loadout' => $loadout,
            'stats' => $stats,
            'isOwner' => $isOwner,
            // E16: حالة العلاقة تُعرض للمسجَّل الموثَّق فقط (وليست لصاحب الملف)؛ وعدد الأصدقاء رقم مجمَّع بلا هويات.
            'friendRelation' => ($viewer !== null && ! $isOwner && $viewer->hasVerifiedEmail()) ? $this->friendships->relationBetween($viewer, $user) : null,
            'friendsCount' => $this->friendships->friendsCount($user),
            // E18: ملخص تنافسي مشتق (أرقام مجمَّعة من نتائج معتمَدة فقط).
            'competitive' => $this->competitive->eventStats($user),
            // E19: شارة الفريق (فريق عام مفعَّل فقط؛ الخاص/المعطَّل لا يُكشف).
            'team' => app(\App\Services\Teams\TeamMembershipService::class)->publicTeamFor($user),
            // E30: شارة «داعم» مشتقّة (علنية فقط وغير مجهولة)؛ معزولة الفشل ولا تعطّل الملف.
            'supporter' => $this->supporterBadge($user),
        ]);
    }

    /** @return array{name: string, slug: string, tier: string, badge_key: string}|null */
    protected function supporterBadge(User $user): ?array
    {
        try {
            return app(\App\Services\Sponsorship\SupporterRecognitionService::class)->forUser($user);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
