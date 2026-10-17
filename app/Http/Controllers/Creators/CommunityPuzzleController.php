<?php

namespace App\Http\Controllers\Creators;

use App\GameEngine\GameTypeRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creators\ReportCommunityPuzzleRequest;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\CommunityPuzzleReport;
use App\Models\CreatorPuzzle;
use App\Models\PuzzleCategory;
use App\Services\Creators\CommunityPlayService;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorException;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E28 - صفحات أحجيات المجتمع العامة واللعب والبلاغ. الظهور من is_visible وحدها (منشور ∧ غير مخفي ∧ غير مؤرشف ∧ مبدع معتمَد وحسابه سليم):
 * أي عمل غير ظاهر = 404 للجميع (مسودة/مرفوض/مخفي/مؤرشف/معلَّق). المسار منفصل عن /puzzles الرسمي وكل اللعب عبر CommunityPlayService (بلا اقتصاد).
 */
class CommunityPuzzleController extends Controller
{
    public function __construct(
        protected CommunityPlayService $play,
        protected CommunityReportService $reports,
        protected GameTypeRegistry $games,
        protected EngagementViewerState $viewerState,
        protected CollectionService $collections,
    ) {}

    public function index(Request $request)
    {
        $categorySlug = $request->query('category');
        $category = is_string($categorySlug) ? PuzzleCategory::query()->where('slug', $categorySlug)->where('is_active', true)->first(['id', 'name', 'slug']) : null;

        $works = CreatorPuzzle::query()->visible()
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count', 'ratings_count', 'ratings_sum', 'favorites_count')
            ->when($category, fn ($q) => $q->where('published_category_id', $category->id))
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedCategory:id,name'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'))->withQueryString();

        return view('community.index', [
            'works' => $works,
            'category' => $category,
            // E29: مفضلة المشاهد لصفحة البطاقات بدفعة واحدة (استعلام واحد للمستخدم المسجَّل، صفر للضيف).
            'savedIds' => $this->viewerState->favoriteIds($request->user(), $works->getCollection()->pluck('id')->all()),
            'categories' => PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Request $request, string $work)
    {
        $work = $this->visibleWork($work);
        $puzzle = $work->publishedPuzzle;
        abort_unless($puzzle !== null && $puzzle->isCommunity(), 404);

        $user = $request->user();
        $state = $this->play->state($user, $puzzle);
        $isOwner = $user !== null && $work->creator->user_id === $user->id;
        $canInteract = $user !== null && $user->hasVerifiedEmail() && ! $user->is_frozen;

        // E29: حالة المشاهد (تقييمي/محفوظ/جرّب) باستعلام واحد + منتقي المجموعات باستعلام واحد، ولغير المالك الموثَّق فقط. الضيف والمالك: صفر استعلامات إضافية.
        $viewer = $canInteract && ! $isOwner ? $this->viewerState->forWork($user, $work->id) : null;
        $collections = $viewer !== null ? $this->collections->pickerFor($user, $work->id) : collect();

        return view('community.show', [
            'viewer' => $viewer,
            'collections' => $collections,
            'work' => $work,
            'puzzle' => $puzzle,
            'state' => $state,
            'renderer' => $this->games->rendererFor($puzzle),
            'isOwner' => $isOwner,
            'canInteract' => $canInteract,
            'reasons' => CommunityPuzzleReport::REASONS,
        ]);
    }

    public function attempt(SolvePuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);
        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        try {
            $result = $this->play->attempt($request->user(), $work, (string) $request->input('answer', ''), $submission);
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['correct']) {
            return back()->with('success', 'إجابة صحيحة! أحسنت. (أحجيات المجتمع بلا جوائز اقتصادية.)');
        }

        return back()->with('error', $result['attempts_left'] > 0
            ? "إجابة غير صحيحة. تبقى لك {$result['attempts_left']} محاولة."
            : 'إجابة غير صحيحة. لقد استنفدت محاولاتك لهذه الأحجية.');
    }

    public function hint(Request $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            return back()->with('hint', $this->play->hint($work));
        } catch (CreatorException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function report(ReportCommunityPuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $this->reports->report($request->user(), $work, (string) $request->validated('reason'), $request->validated('details'));
        } catch (CreatorException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'وصلنا بلاغك، شكرًا لمساعدتك في إبقاء المنصة آمنة.');
    }

    /** عمل ظاهر للعامة وإلا 404. */
    protected function visibleWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->visible()->where('public_id', $publicId)
            ->with(['creator.user:id,name', 'publishedPuzzle', 'publishedCategory:id,name'])
            ->firstOrFail();
    }
}
