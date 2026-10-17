<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Services\Engagement\CommunityFavoriteService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E29 - المفضلة: POST/DELETE صريحان (لا تبديل GET). صفحة «مفضلتي» خاصة بصاحبها حصرًا (تُقرأ دائمًا بـuser_id الجلسة، لا معرّف مستخدم من الطلب). */
class CommunityFavoriteController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CommunityFavoriteService $favorites) {}

    public function index(Request $request, EngagementLists $lists)
    {
        $works = $lists->favorites($request->user(), (int) config('engagement.pagination.favorites'));

        return view('engagement.favorites', ['works' => $works]);
    }

    public function store(Request $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $result = $this->favorites->add($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, $result['changed'] ? 'حُفظت الأحجية في مفضلتك.' : null);
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        $work = $this->anyWork($work);

        try {
            $result = $this->favorites->remove($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        abort_if(! $result['changed'] && ! $work->is_visible, 404);

        // من صفحة المفضلة نفسها نعود إليها؛ ومن صفحة الأحجية إلى قسم التفاعل.
        if ($request->input('from') === 'favorites') {
            return redirect()->route('favorites.index');
        }

        return $this->backToWork($work, $result['changed'] ? 'أُزيلت الأحجية من مفضلتك.' : null);
    }
}
