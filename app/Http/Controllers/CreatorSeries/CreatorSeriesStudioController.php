<?php

namespace App\Http\Controllers\CreatorSeries;

use App\Http\Controllers\Creators\CreatorController;
use App\Http\Requests\CreatorSeries\SaveCreatorSeriesRequest;
use App\Models\CreatorSeriesRevision;
use App\Services\CreatorSeries\CreatorSeriesAnalytics;
use App\Services\CreatorSeries\CreatorSeriesService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E30 - استوديو سلاسل المبدع. كل مسار {series} بمعرّف ULID عام يُحلّ باستعلام محصور بمالكه (سلسلة غيرك = 404 بلا تسريب). الأهلية بميدلوير E28 نفسه وبالخدمة (دفاع مزدوج).
 * المدخلات من FormRequest فقط (validated) وبلا تعيين جماعي للطلب كله؛ أخطاء المجال المتوقَّعة تعود برسالة عربية.
 */
class CreatorSeriesStudioController extends CreatorController
{
    public function __construct(
        protected CreatorSeriesService $series,
        protected CreatorSeriesAnalytics $analytics,
        protected CreatorSeriesVisibilityService $visibility,
    ) {
        abort_unless((bool) config('creator_series.enabled', true), 404);
    }

    public function index(Request $request)
    {
        $profile = $this->profile($request);
        $bucket = in_array($request->query('status'), CreatorSeriesService::BUCKETS, true) ? (string) $request->query('status') : 'all';
        $perSeries = $this->analytics->forCreator($profile);

        return view('creator.series.index', [
            'bucket' => $bucket,
            'counts' => $this->series->bucketCounts($profile),
            'seriesList' => $this->series->paginate($profile, $bucket),
            'stats' => $this->analytics->totals($perSeries),
            'perSeries' => $perSeries,
        ]);
    }

    public function create(Request $request)
    {
        return view('creator.series.create', [
            'works' => $this->series->eligibleWorks($this->profile($request)),
            'current' => collect(),
            'selected' => $this->oldSelection() ?? [],
            'limits' => config('creator_series.limits'),
        ]);
    }

    public function store(SaveCreatorSeriesRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->createDraft($request->user(), $this->payload($request), $request->file('cover')),
            'حُفظت السلسلة كمسودة. راجعها ثم أرسلها للمراجعة.',
            fn (CreatorSeriesRevision $revision) => redirect()->route('creator.series.edit', $revision->series()->value('public_id')),
        );
    }

    public function edit(Request $request, string $series)
    {
        $profile = $this->profile($request);
        $model = $this->series->ownedSeries($profile, $series);
        $revision = CreatorSeriesRevision::query()->findOrFail($model->latest_revision_id);
        $current = $this->series->revisionItems($revision);

        return view('creator.series.edit', [
            'series' => $model,
            'revision' => $revision,
            'works' => $this->series->eligibleWorks($profile),
            'current' => $current,
            'selected' => $this->oldSelection() ?? $current->pluck('public_id')->all(),
            'limits' => config('creator_series.limits'),
            'blockers' => $model->isPublished() ? $this->visibility->blockers($model) : [],
            'publicUrl' => $model->isPublished() ? route('series.show', $model->public_id) : null,
        ]);
    }

    public function update(SaveCreatorSeriesRequest $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->updateDraft($request->user(), $series, $this->payload($request), $request->file('cover'), $request->boolean('remove_cover')),
            'حُفظت التعديلات.',
        );
    }

    public function destroy(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->destroy($request->user(), $series),
            'تم الحذف.',
            fn () => redirect()->route('creator.series.index'),
        );
    }

    public function submit(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->submit($request->user(), $series),
            'أُرسلت السلسلة للمراجعة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function withdraw(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->withdraw($request->user(), $series),
            'سُحبت السلسلة من المراجعة وعادت مسودة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function revise(Request $request, string $series): RedirectResponse
    {
        return $this->perform(
            fn () => $this->series->revise($request->user(), $series),
            'أُنشئت نسخة جديدة للتعديل. النسخة المنشورة تبقى متاحة حتى اعتماد الجديدة.',
            fn () => redirect()->route('creator.series.edit', $series),
        );
    }

    public function archive(Request $request, string $series): RedirectResponse
    {
        return $this->perform(fn () => $this->series->archive($request->user(), $series), 'تمت أرشفة السلسلة وأُخفيت من العامة.');
    }

    public function unarchive(Request $request, string $series): RedirectResponse
    {
        return $this->perform(fn () => $this->series->unarchive($request->user(), $series), 'أُلغيت الأرشفة.');
    }

    /** @return array{title:string, description:?string, items:array<int,string>} مدخلات مُتحقَّق منها فقط (لا $request->all()). */
    protected function payload(SaveCreatorSeriesRequest $request): array
    {
        $data = $request->validated();

        return [
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'items' => array_values($data['items']),
        ];
    }

    /** اختيار المستخدم بعد فشل تحقق (old) بالترتيب نفسه، أو null. @return list<string>|null */
    protected function oldSelection(): ?array
    {
        $old = old('items');

        return is_array($old) ? array_values(array_filter($old, 'is_string')) : null;
    }
}
