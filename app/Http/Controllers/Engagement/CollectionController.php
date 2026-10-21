<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Http\Requests\Engagement\AddToCollectionRequest;
use App\Http\Requests\Engagement\CollectionRequest;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionAccess;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E29 - المجموعات الشخصية (مسارات المالك؛ E35 تضيف الظهور عبر CollectionManageController والقراءة العامة عبر PublicCollectionController، لا هنا). كل مسار {collection} يُحلّ بمعرّف ULID **مع user_id المالك** (غير المالك = 404).
 * المتحكم رقيق: تحقق (FormRequest بقواعد الخدمة) ← خدمة ← عودة.
 */
class CollectionController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CollectionService $collections) {}

    public function index(Request $request)
    {
        $collections = UserCollection::query()->where('user_id', $request->user()->id)
            ->select('id', 'public_id', 'title', 'description', 'visibility', 'saves_count', 'is_moderation_hidden', 'updated_at')->withCount('items')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) config('engagement.pagination.collections'));

        return view('engagement.collections.index', ['collections' => $collections, 'max' => (int) config('engagement.collections.max_per_user')]);
    }

    public function create()
    {
        return view('engagement.collections.form', ['collection' => null]);
    }

    public function store(CollectionRequest $request): RedirectResponse
    {
        try {
            $collection = $this->collections->create($request->user(), (string) $request->validated('title'), $request->validated('description'));
        } catch (EngagementException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $collection->public_id)->with('success', 'أُنشئت المجموعة.');
    }

    public function show(Request $request, EngagementLists $lists, CollectionAccess $access, string $collection)
    {
        $collection = $this->collections->owned($request->user(), $collection);

        return view('engagement.collections.show', [
            'collection' => $collection,
            'works' => $lists->collectionItems($collection, (int) config('engagement.pagination.collection_items')),
            // E35: لوحة الظهور عند المالك (أعداد مشتقة من الظاهر الآن + شرط الملف العام للنشر العام).
            'visibleCount' => $access->visibleCount((int) $collection->id),
            'profilePublic' => $request->user()->profile_visibility === User::VISIBILITY_PUBLIC,
            'minPublicItems' => (int) config('collections.publish.min_visible_items_public'),
        ]);
    }

    public function edit(Request $request, string $collection)
    {
        return view('engagement.collections.form', ['collection' => $this->collections->owned($request->user(), $collection)]);
    }

    public function update(CollectionRequest $request, string $collection): RedirectResponse
    {
        try {
            $updated = $this->collections->update($request->user(), $collection, (string) $request->validated('title'), $request->validated('description'));
        } catch (EngagementException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $updated->public_id)->with('success', 'حُفظت التعديلات.');
    }

    public function destroy(Request $request, string $collection): RedirectResponse
    {
        try {
            $this->collections->delete($request->user(), $collection);
        } catch (EngagementException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.index')->with('success', 'حُذفت المجموعة (الأحجيات نفسها لم تُحذف).');
    }

    /** من صفحة الأحجية: إضافتها لمجموعة قائمة أو لمجموعة جديدة تُنشأ في الخطوة نفسها. */
    public function addWork(AddToCollectionRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            if (filled($request->validated('collection'))) {
                $result = $this->collections->add($request->user(), (string) $request->validated('collection'), $work);

                return $this->backToWork($work, $result['changed'] ? 'أُضيفت الأحجية إلى المجموعة.' : 'الأحجية موجودة في هذه المجموعة مسبقًا.');
            }

            $this->collections->createAndAdd($request->user(), (string) $request->validated('new_title'), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, 'أُنشئت مجموعة جديدة وأُضيفت إليها الأحجية.');
    }

    public function removeWork(Request $request, string $collection, string $work): RedirectResponse
    {
        try {
            $this->collections->remove($request->user(), $collection, $work);
        } catch (EngagementException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('collections.show', $collection);
    }
}
