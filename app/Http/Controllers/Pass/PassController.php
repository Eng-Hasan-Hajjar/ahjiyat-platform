<?php

namespace App\Http\Controllers\Pass;

use App\Http\Controllers\Controller;
use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTierReward;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\PassClaimService;
use App\Services\AhjiyatPass\PassException;
use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E40 - صفحة مسار الأحجيات للاعب (قراءة) واستلام المكافآت. كل شيء من الخادم:
 * المسار المعروض يحدّده الخادم (الفعّال أو الأخير ضمن مهلة الاستلام)، والمكافأة تُحلّ من تعريف المسار المنشور، والشريحة/المميز تُقرأ من تقدّم اللاعب المقفول داخل الخدمة.
 * أخطاء الأعمال تُعرض رسالة عربية آمنة؛ أي خطأ غير متوقع لا يُسرَّب تفاصيله.
 */
class PassController extends Controller
{
    public function __construct(
        protected PassPageData $pages,
        protected PassClaimService $claims,
        protected PassLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): View
    {
        $data = $this->pages->forUser($request->user());

        if ($data['pass'] === null) {
            return view('pass.empty', [
                'hasArchive' => AhjiyatPass::query()->whereNotNull('locked_at')->where('ends_at', '<=', now())->exists(),
            ]);
        }

        return view('pass.index', $data + ['archive' => false]);
    }

    public function archive(Request $request): View
    {
        $passes = AhjiyatPass::query()
            ->whereNotNull('locked_at')
            ->where('ends_at', '<=', now())
            ->orderByDesc('ends_at')
            ->paginate(max(1, (int) config('ahjiyat_pass.page.archive_per_page', 12)));

        $progress = UserPassProgress::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('ahjiyat_pass_id', $passes->pluck('id'))
            ->get()
            ->keyBy('ahjiyat_pass_id');

        return view('pass.archive', ['passes' => $passes, 'progress' => $progress]);
    }

    public function archiveShow(Request $request, string $pass): View
    {
        $model = $this->lifecycle->findVisible($pass);

        abort_if($model === null || $model->ends_at->gt(now()), 404);

        return view('pass.index', $this->pages->build($request->user(), $model) + ['archive' => true]);
    }

    /** استلام مكافأة واحدة. المعرّف رقم تعريف مكافأة الشريحة؛ الملكية والأهلية تُفحصان داخل الخدمة. */
    public function collect(Request $request, int $slot): RedirectResponse
    {
        $reward = AhjiyatPassTierReward::query()->with('pass')->find($slot);
        $pass = $reward?->pass;

        abort_if($reward === null || $pass === null || $pass->locked_at === null || ! $pass->isPubliclyVisible(), 404);

        try {
            $claim = $this->claims->claim($request->user(), $pass, (int) $reward->getKey());
        } catch (PassException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تم استلام «'.$claim->item_name.'». ستجدها في مقتنياتك.');
    }

    public function collectAll(Request $request): RedirectResponse
    {
        $data = $request->validate(['pass' => ['required', 'string', 'size:26']]);
        $pass = $this->lifecycle->findVisible($data['pass']);

        abort_if($pass === null, 404);

        try {
            $result = $this->claims->collectAll($request->user(), $pass);
        } catch (PassException $e) {
            return back()->with('error', $e->getMessage());
        }

        $count = count($result['claimed']);

        if ($count === 0 && $result['failed'] === []) {
            return back()->with('error', 'لا توجد مكافآت جاهزة للاستلام الآن.');
        }

        $message = $count > 0 ? "تم استلام {$count} مكافأة." : 'لم يُستلم شيء.';

        if ($result['failed'] !== []) {
            $message .= ' تعذّر استلام '.count($result['failed']).' مكافأة، يمكنك إعادة المحاولة لاحقًا.';
        }

        return back()->with($count > 0 ? 'success' : 'error', $message);
    }
}
