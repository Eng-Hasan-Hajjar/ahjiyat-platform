<?php

namespace App\Http\Controllers\Collections;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collections\CollectionReportRequest;
use App\Services\Collections\CollectionAccess;
use App\Services\Collections\CollectionReportService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * E35 - الصفحة العامة للمجموعة + حفظها + الإبلاغ + «محفوظاتي».
 *
 *  - show: عامة للضيف (GET فقط، بلا أي كتابة). تمرّ كل قراءة بـCollectionAccess::findViewable: غير موجودة/خاصة/مخفية/مالك مجمَّد أو غير موثَّق/حظر = **404 واحد** بلا كشف السبب.
 *    الأعمال غير الظاهرة تُحذف من الصفحة (لا تسريب لعنوانها أو وجودها)، والعدّ للظاهر فقط. نسبة الأمين تتبع خصوصية ملفه.
 *  - حالة الحفظ/البلاغ للمشاهد تُقرأ بدفعة لهذا المشاهد فقط (لا كاش مشترك بحالة شخصية).
 *  - SEO: canonical نظيف؛ العامة قابلة للفهرسة (بحسب إعداد الموقع)، وغير المدرجة noindex,nofollow؛ og:image من صورة أول عمل ظاهر أو الافتراضي.
 *  - save/unsave/report: POST/DELETE فقط خلف auth + verified + account.active + محددات معدّل مسمّاة.
 */
class PublicCollectionController extends Controller
{
    public function __construct(
        protected CollectionAccess $access,
        protected CollectionSaveService $saves,
        protected CollectionReportService $reports,
    ) {}

    public function show(Request $request, EngagementLists $lists, string $collection)
    {
        $viewer = $request->user();
        $model = $this->access->findViewable($collection, $viewer);
        abort_if($model === null, 404);

        $works = $lists->publicCollectionItems($model, (int) config('collections.public_page.items_per_page'));
        $isOwner = $viewer !== null && (int) $viewer->id === (int) $model->user_id;
        $canInteract = $viewer !== null && $viewer->email_verified_at !== null && ! $viewer->is_frozen;
        $coverPath = $this->access->coverPath((int) $model->id);

        return view('collections.public', [
            'collection' => $model,
            'works' => $works,
            'attribution' => $this->access->attribution($model, $viewer),
            'isOwner' => $isOwner,
            'canInteract' => $canInteract,
            'isSaved' => $viewer !== null && ! $isOwner && isset($this->saves->savedAmong($viewer, [(int) $model->id])[(int) $model->id]),
            'hasReported' => $viewer !== null && ! $isOwner && $this->reports->hasOpenReport($viewer, (int) $model->id),
            'coverUrl' => $coverPath !== null ? Storage::url($coverPath) : null,
        ]);
    }

    public function saved(Request $request)
    {
        return view('collections.saved', [
            'items' => $this->saves->savedPage($request->user(), (int) config('collections.saves.per_page')),
            'max' => (int) config('collections.saves.max_per_user'),
        ]);
    }

    public function save(Request $request, string $collection): RedirectResponse
    {
        try {
            $result = $this->saves->save($request->user(), $collection);
        } catch (EngagementException $e) {
            return redirect()->route('collections.public.show', $collection)->with('error', $e->getMessage());
        }

        return redirect()->route('collections.public.show', $collection)->with('success', $result['changed'] ? 'حُفظت المجموعة.' : null);
    }

    public function unsave(Request $request, string $collection): RedirectResponse
    {
        try {
            $this->saves->remove($request->user(), $collection);
        } catch (EngagementException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $request->input('from') === 'saved'
            ? redirect()->route('collections.saved')->with('success', 'أُزيلت المجموعة من محفوظاتك.')
            : back()->with('success', 'أُزيلت المجموعة من محفوظاتك.');
    }

    public function report(CollectionReportRequest $request, string $collection): RedirectResponse
    {
        try {
            $this->reports->report($request->user(), $collection, (string) $request->validated('reason'));
        } catch (EngagementException $e) {
            return redirect()->route('collections.public.show', $collection)->with('error', $e->getMessage());
        }

        return redirect()->route('collections.public.show', $collection)->with('success', 'وصلنا بلاغك وسيراجعه فريق الإشراف. شكرًا لك.');
    }
}
