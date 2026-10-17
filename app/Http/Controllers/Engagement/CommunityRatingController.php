<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Engagement\Concerns\ResolvesCommunityWork;
use App\Http\Requests\Engagement\RateCommunityPuzzleRequest;
use App\Services\Engagement\CommunityRatingService;
use App\Services\Engagement\EngagementException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E29 - تقييم أحجية مجتمع (PUT/DELETE فقط، أبدًا GET). المتحكم رقيق: تحقق ← خدمة ← عودة. لا أثر اقتصادي. */
class CommunityRatingController extends Controller
{
    use ResolvesCommunityWork;

    public function __construct(protected CommunityRatingService $ratings) {}

    public function update(RateCommunityPuzzleRequest $request, string $work): RedirectResponse
    {
        $work = $this->visibleWork($work);

        try {
            $this->ratings->set($request->user(), $work, (int) $request->validated('rating'));
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        return $this->backToWork($work, 'تم حفظ تقييمك.');
    }

    public function destroy(Request $request, string $work): RedirectResponse
    {
        $work = $this->anyWork($work);

        try {
            $removed = $this->ratings->remove($request->user(), $work);
        } catch (EngagementException $e) {
            return $this->backToWork($work)->with('error', $e->getMessage());
        }

        abort_if(! $removed && ! $work->is_visible, 404);   // لا نكشف وجود عمل مخفي لمن لا تقييم له عليه

        return $this->backToWork($work, $removed ? 'تمت إزالة تقييمك.' : null);
    }
}
