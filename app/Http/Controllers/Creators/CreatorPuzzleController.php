<?php

namespace App\Http\Controllers\Creators;

use App\Http\Requests\Creators\StoreCreatorPuzzleRequest;
use App\Http\Requests\Creators\UpdateCreatorPuzzleRequest;
use App\Http\Requests\SolvePuzzleRequest;
use App\Models\PuzzleCategory;
use App\Services\Creators\CreatorContent;
use App\Services\Creators\CreatorPreviewService;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E28 - استوديو المبدع. كل مسار عمل يستقبل public_id نصًا (لا ربط نموذج ضمني) ويُحلّ عبر استعلام محصور بالمالك: عمل مبدع آخر = 404 لا 403 (لا كشف وجود).
 * الصلاحية الفعلية والحدود والملكية في CreatorPuzzleService؛ هنا تنسيق HTTP فقط.
 */
class CreatorPuzzleController extends CreatorController
{
    public function __construct(
        protected CreatorPuzzleService $puzzles,
        protected CreatorPreviewService $previews,
    ) {}

    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $bucket = in_array($request->query('status'), CreatorPuzzleService::BUCKETS, true) ? (string) $request->query('status') : 'all';

        return view('creator.puzzles.index', [
            'works' => $this->puzzles->paginate($profile, $bucket),
            'counts' => $this->puzzles->bucketCounts($profile),
            'bucket' => $bucket,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $request->query('kind');

        return view('creator.puzzles.create', [
            'categories' => $this->categories(),
            'kinds' => (array) config('creators.kinds'),
            'kind' => is_string($kind) && in_array($kind, CreatorContent::kinds(), true) ? $kind : null,
        ]);
    }

    public function store(StoreCreatorPuzzleRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->createDraft($request->user(), $request->safe()->except('image'), $request->file('image')),
            'تم حفظ المسودة.',
            fn ($revision) => redirect()->route('creator.puzzles.edit', $revision->work()->value('public_id')),
        );
    }

    public function edit(Request $request, string $work)
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        return view('creator.puzzles.edit', [
            'work' => $work,
            'revision' => $revision,
            'content' => (array) $revision->content,
            'categories' => $this->categories(),
            'kindConfig' => CreatorContent::kindConfig((string) $revision->kind),
        ]);
    }

    public function update(UpdateCreatorPuzzleRequest $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->updateDraft($request->user(), $work, $request->safe()->except('image'), $request->file('image')),
            'تم حفظ التعديلات.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->destroy($request->user(), $work),
            'تم الحذف.',
            fn () => redirect()->route('creator.puzzles.index'),
        );
    }

    public function destroyImage(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->removeImage($request->user(), $work), 'تم حذف الصورة.');
    }

    public function submit(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->submit($request->user(), $work),
            'أُرسلت الأحجية للمراجعة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function withdraw(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->withdraw($request->user(), $work),
            'سُحبت الأحجية من المراجعة وعادت مسودة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function revise(Request $request, string $work): RedirectResponse
    {
        return $this->perform(
            fn () => $this->puzzles->revise($request->user(), $work),
            'أُنشئت نسخة جديدة للتعديل. النسخة المنشورة تبقى متاحة حتى اعتماد الجديدة.',
            fn () => redirect()->route('creator.puzzles.edit', $work),
        );
    }

    public function archive(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->archive($request->user(), $work), 'تمت أرشفة الأحجية وأُخفيت من العامة.');
    }

    public function unarchive(Request $request, string $work): RedirectResponse
    {
        return $this->perform(fn () => $this->puzzles->unarchive($request->user(), $work), 'أُلغيت الأرشفة.');
    }

    public function preview(Request $request, string $work)
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        return view('creator.puzzles.preview', [
            'work' => $work,
            'revision' => $revision,
            'checkRoute' => route('creator.puzzles.preview.check', $work->public_id),
            'backRoute' => route('creator.puzzles.edit', $work->public_id),
            'solution' => null,
        ] + $this->previews->view($revision));
    }

    /** يتحقق بالمدقّق الرسمي نفسه ويعرض صحيح/خاطئ فقط: بلا تخزين محاولة ولا جواهر ولا XP ولا مهام ولا تقدّم. */
    public function previewCheck(SolvePuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->puzzles->ownedWork($this->profile($request), $work);
        $revision = $work->latestRevision()->firstOrFail();

        $submission = (array) $request->input('submission', []);

        if ($request->filled('start_token')) {
            $submission['start_token'] = (string) $request->input('start_token');
        }

        $result = $this->previews->check($revision, (string) $request->input('answer', ''), $submission, $request->user()->id);

        return back()->with($result->correct ? 'success' : 'error', $result->correct ? 'إجابة صحيحة (معاينة: لا تُحتسب ولا مكافآت).' : 'إجابة غير صحيحة (معاينة).');
    }

    protected function categories()
    {
        return PuzzleCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
    }
}
