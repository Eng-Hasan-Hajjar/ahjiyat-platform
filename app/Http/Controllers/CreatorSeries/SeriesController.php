<?php

namespace App\Http\Controllers\CreatorSeries;

use App\Http\Controllers\Controller;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Services\CreatorPremium\CreatorPremiumAccess;
use App\Services\CreatorPremium\CreatorPremiumSeriesPresenter;
use App\Services\CreatorSeries\CreatorSeriesProgressService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Discussions\DiscussionPage;
use App\Services\Discussions\DiscussionSubjectRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E30 - صفحة السلسلة العامة /series/{معرّف عام أو slug}. عامة للضيوف لكن لسلسلة ظاهرة فقط (CreatorSeriesVisibilityService): غيرها 404 للجميع.
 * قراءة فقط (GET لا يكتب شيئًا ولا يعدّ مشاهدات). اللعب نفسه يجري في صفحات أحجيات المجتمع القائمة (CommunityPlayService، بلا اقتصاد):
 * هذه الصفحة تعرض العناصر وتقدّم المستخدم المشتقّ من سجل لعب المجتمع وزر «أكمل» لأول عنصر غير محلول. استعلامات الصفحة عدد ثابت مهما كان عدد العناصر.
 *
 * E43: السلسلة المميزة تعرض بياناتها الوصفية علنًا (العنوان/الغلاف/الوصف/العدد/السعر/شارة مميز) مع كتلة الشراء؛ الحل والتلميح والنقاش لا يُسلَّمون لغير المخوَّل (CreatorPremiumAccess).
 * عرض البيع (الحالة/السعر/العملة) يأتي مع استعلام السلسلة نفسه بضمّ مفهرس (live_series_id فريد): صفر استعلامات إضافية للسلاسل الحرة.
 */
class SeriesController extends Controller
{
    public function __construct(
        protected CreatorSeriesVisibilityService $visibility,
        protected CreatorSeriesProgressService $progress,
        protected DiscussionPage $discussionPage,
        protected DiscussionSubjectRegistry $discussionSubjects,
        protected CreatorPremiumAccess $premiumAccess,
        protected CreatorPremiumSeriesPresenter $premiumPresenter,
    ) {}

    public function show(Request $request, string $series)
    {
        abort_unless((bool) config('creator_series.enabled', true), 404);

        $query = $this->visibility->visibleQuery()
            ->select('creator_series.*')
            ->leftJoin('creator_series_sale_offers as pso', 'pso.live_series_id', '=', 'creator_series.id')
            ->addSelect(['pso.status as premium_status', 'pso.price_minor as premium_price_minor', 'pso.currency as premium_currency'])
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedRevision:id,creator_series_id,title,description,cover_path']);

        $model = (Str::isUlid($series) ? $query->where('creator_series.public_id', Str::upper($series)) : $query->where('creator_series.slug', $series))->first();
        abort_unless($model instanceof CreatorSeries && $model->publishedRevision !== null, 404);

        $items = DB::table('creator_series_items as i')
            ->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')
            ->leftJoin('puzzle_categories as c', 'c.id', '=', 'w.published_category_id')
            ->where('i.creator_series_revision_id', $model->published_revision_id)
            ->orderBy('i.position')
            ->get(['i.position', 'w.id as work_id', 'w.public_id', 'w.published_title', 'w.published_difficulty', 'c.name as category']);

        $user = $request->user();
        $isOwner = $user !== null && (int) $model->creator->user_id === (int) $user->id;
        $canPlay = $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen && ! $isOwner;
        $progress = $this->progress->forUser($canPlay ? $user : null, $items);

        $premium = $this->premiumPresenter->forPage($user, $model, $isOwner);
        $premiumLocked = $premium !== null && in_array($premium['status'], CreatorSeriesSaleOffer::LOCKING_STATUSES, true);
        $discussionAllowed = $this->premiumAccess->decideSeriesDiscussion($user, $model, $premiumLocked)->allowed;

        return view('series.show', [
            'series' => $model,
            'revision' => $model->publishedRevision,
            'creator' => $model->creator,
            'items' => $items,
            'progress' => $progress,
            'isOwner' => $isOwner,
            'canPlay' => $canPlay,
            'coverUrl' => $model->publishedCoverUrl(),
            'continueId' => $progress['next_public_id'] ?? $items->first()?->public_id,
            'premium' => $premium,
            // E36: قسم النقاش المضمَّن (null = معطَّل أو فشل قراءته). E43: نقاش السلسلة المميزة للمبدع/المشرف/المالك فقط.
            'discussion' => $discussionAllowed ? $this->discussionPage->for($request, $this->discussionSubjects->forSeries($model)) : null,
        ]);
    }
}
