<?php

namespace App\Http\Controllers\Collections;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collections\CollectionReorderRequest;
use App\Http\Requests\Collections\CollectionVisibilityRequest;
use App\Models\UserCollection;
use App\Services\Collections\CollectionOrderService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * E35 - إدارة المالك: تغيير الظهور وترتيب العناصر. كلاهما PATCH خلف auth + verified + account.active + محدّد معدّل مسمّى بمفتاح المستخدم.
 * المتحكم رقيق: طلب مُتحقَّق ← خدمة (تحل المجموعة بمعرّفها مع user_id المالك: غير المالك 404 بلا كشف) ← عودة. الأخطاء المجالية رسائل عربية آمنة.
 */
class CollectionManageController extends Controller
{
    public function __construct(protected CollectionVisibilityService $visibility, protected CollectionOrderService $order) {}

    public function visibility(CollectionVisibilityRequest $request, string $collection): RedirectResponse
    {
        try {
            $result = $this->visibility->set($request->user(), $collection, (string) $request->validated('visibility'));
        } catch (EngagementException $e) {
            return redirect()->route('collections.show', $collection)->with('error', $e->getMessage());
        }

        $label = UserCollection::visibilityLabel((string) $result['collection']->visibility);

        return redirect()->route('collections.show', $collection)->with('success', $result['changed'] ? 'أصبحت المجموعة «'.$label.'».' : 'لم يتغيّر ظهور المجموعة.');
    }

    public function reorder(CollectionReorderRequest $request, string $collection): RedirectResponse|JsonResponse
    {
        try {
            $result = $request->filled('order')
                ? $this->order->reorder($request->user(), $collection, array_values((array) $request->validated('order')))
                : $this->order->move($request->user(), $collection, (string) $request->validated('work'), (string) $request->validated('direction'));
        } catch (EngagementException $e) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'changed' => $result['changed']])
            : back()->with('success', $result['changed'] ? 'حُدّث ترتيب المجموعة.' : null);
    }
}
