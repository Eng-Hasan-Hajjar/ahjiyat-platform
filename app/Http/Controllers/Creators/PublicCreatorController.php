<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Services\Engagement\CommunityQualityService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementViewerState;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use Illuminate\Http\Request;

/**
 * E28 - صفحة المبدع العامة /creators/{slug}. عامة للضيوف لكن لمبدع معتمَد وحسابه غير مجمَّد فقط (غيره 404).
 * تعرض: الاسم، الصورة الرمزية (هوية اللاعب القائمة)، النبذة، تاريخ الانضمام كمبدع، عدد المنشور، إجمالي اللاعبين، والأحجيات الظاهرة فقط (مرقَّمة).
 * لا بريد ولا هاتف ولا حالة إدارية ولا علامات احتيال ولا أي بيانات لاعب خاصة.
 */
class PublicCreatorController extends Controller
{
    public function __construct(
        protected CosmeticLoadoutService $loadouts,
        protected CreatorFollowService $follows,
        protected CommunityQualityService $quality,
        protected EngagementViewerState $viewerState,
        protected CreatorSeriesVisibilityService $seriesVisibility,
    ) {}

    public function show(Request $request, CreatorProfile $creator)
    {
        $creator->loadMissing('user:id,name,is_frozen');

        abort_unless($creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen, 404);

        $totals = CreatorPuzzle::query()->visible()->where('creator_profile_id', $creator->id)
            ->selectRaw('COUNT(*) as published, COALESCE(SUM(plays_count), 0) as plays, COALESCE(SUM(ratings_count), 0) as rated, COALESCE(SUM(ratings_sum), 0) as rated_sum')->first();

        $works = CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $creator->id)
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'));

        // E30: سلاسل المبدع الظاهرة فقط (الظهور حيًّا من CreatorSeriesVisibilityService: عنصر مخفي/مبدع معلَّق/مؤرشفة = غير موجودة). قسم مستقل لا يكسر الصفحة إن تعطّلت السلاسل.
        $series = collect();

        if ((bool) config('creator_series.enabled', true)) {
            try {
                $series = $this->seriesVisibility->visibleQuery()
                    ->where('creator_series.creator_profile_id', $creator->id)
                    ->select('creator_series.id', 'creator_series.public_id', 'creator_series.creator_profile_id', 'creator_series.published_title', 'creator_series.published_items_count', 'creator_series.published_at')
                    ->orderByDesc('creator_series.published_at')->orderByDesc('creator_series.id')
                    ->limit((int) config('creator_series.page_size', 12))->get();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $loadout = $this->loadouts->loadoutFor($creator->user);
        $user = $request->user();

        // E29: حالة زر المتابعة بقرار واحد (استعلام exists واحد للموثَّق فقط). لا هوية متابعين تُعرض؛ العدد المجمَّع (followers_count) علني.
        $followState = match (true) {
            $user === null => 'guest',
            $user->id === $creator->user_id => 'self',
            ! $user->hasVerifiedEmail() => 'verify',
            (bool) $user->is_frozen => 'frozen',
            $this->follows->isFollowing($user, $creator->id) => 'following',
            default => 'can_follow',
        };

        return view('creator.public', [
            'creator' => $creator,
            'avatar' => $loadout['avatar'] ?? null,
            'frame' => $loadout['profile_frame'] ?? null,
            'published' => (int) ($totals->published ?? 0),
            'plays' => (int) ($totals->plays ?? 0),
            'works' => $works,
            'seriesList' => $series,
            'followState' => $followState,
            'followers' => (int) $creator->followers_count,
            'quality' => $this->quality->creatorSummary((int) ($totals->rated ?? 0), (int) ($totals->rated_sum ?? 0)),
            'savedIds' => $this->viewerState->favoriteIds($user, $works->getCollection()->pluck('id')->all()),
        ]);
    }
}
