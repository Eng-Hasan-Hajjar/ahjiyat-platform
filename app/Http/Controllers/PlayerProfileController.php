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
            // E44: تقدير الداعم الموسمي (نمط اسم + شارة «داعم الموسم #N») طبقة مشتقّة للقراءة فقط؛ معزولة الفشل ولا تعدّل أي عنصر تجميلي.
            'seasonSupporter' => $this->seasonSupporter($user),
            // E35: مجموعات المجتمع العامة لهذا اللاعب (المُدرَجة علنًا الآن فقط)؛ معزولة الفشل.
            'collections' => $this->curatedCollections($user, $viewer),
            // E37: اعتراف مجتمعي (منسّق/مساهم/مبدع) من الإنجازات المفتوحة فقط، بلا مكافآت وبلا درجات؛ معزول الفشل.
            'recognition' => $this->communityRecognition($user),
        ]);
    }

    /** @return list<string> أسماء اعتراف مجتمعي مفتوح (بحدّ أقصى 6) */
    protected function communityRecognition(User $user): array
    {
        if (! config('reputation.enabled', true)) {
            return [];
        }

        try {
            $recognition = app(\App\Services\Reputation\CommunityRecognitionPresenter::class)->forUser((int) $user->id);
            $badges = $recognition['badges'];

            // اعتراف «المبدع» يُعرض فقط لمبدع معتمَد الآن (المعلَّق لا يحمل شارة مبدع علنًا؛ الاعتراف التاريخي محفوظ ويعود بالاستعادة).
            if (! \App\Models\CreatorProfile::query()->where('user_id', $user->id)->where('status', \App\Models\CreatorProfile::STATUS_APPROVED)->exists()) {
                $badges = array_values(array_diff($badges, $recognition['groups']['creator'], $recognition['tier'] !== null ? [$recognition['tier']] : []));
            }

            return array_slice($badges, 0, 6);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    protected function curatedCollections(User $owner, ?User $viewer): \Illuminate\Support\Collection
    {
        try {
            return app(\App\Services\Collections\CuratorCollections::class)->forProfile($owner, $viewer);
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
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

    /** @return array{tier: string, tier_name: string, style: string, rank: ?int, badge: ?string}|null */
    protected function seasonSupporter(User $user): ?array
    {
        try {
            return app(\App\Services\SeasonalRecognition\SeasonSupporterIdentityResolver::class)->forUser((int) $user->id);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
