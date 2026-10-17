<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementLists;
use App\Services\Engagement\EngagementViewerState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E29 - متابعة المبدعين + خلاصة /following. POST/DELETE فقط. لا تُكشف هوية المتابعين لأحد؛ خلاصتي وقائمة من أتابعهم خاصتان بي (user_id الجلسة).
 * مبدع غير معتمَد/مجمَّد = 404 كصفحته العامة (لا تسريب لوجود ملف معلَّق). إلغاء متابعة مبدع عُلِّق لاحقًا مسموح لمن يتابعه فعلًا.
 */
class CreatorFollowController extends Controller
{
    public function __construct(protected CreatorFollowService $follows) {}

    public function feed(Request $request, EngagementLists $lists, EngagementViewerState $viewerState)
    {
        $works = $lists->following($request->user(), (int) config('engagement.pagination.following'));

        return view('engagement.following', [
            'works' => $works,
            'savedIds' => $viewerState->favoriteIds($request->user(), collect($works->items())->pluck('id')->all()),
            // الحالة الفارغة تفرّق بين «لا تتابع أحدًا» و«من تتابعهم لم ينشروا جديدًا»: استعلام exists واحد وفقط عند خلوّ الصفحة.
            'followsAny' => $works->isNotEmpty() || $this->followsAnyone($request),
        ]);
    }

    public function store(Request $request, CreatorProfile $creator): RedirectResponse
    {
        abort_unless($this->isPublic($creator), 404);

        try {
            $this->follows->follow($request->user(), $creator);
        } catch (EngagementException $e) {
            return $this->back($creator)->with('error', $e->getMessage());
        }

        return $this->back($creator, 'أصبحت تتابع هذا المبدع.');
    }

    public function destroy(Request $request, CreatorProfile $creator): RedirectResponse
    {
        abort_unless($this->isPublic($creator) || $this->follows->isFollowing($request->user(), $creator->id), 404);

        try {
            $result = $this->follows->unfollow($request->user(), $creator);
        } catch (EngagementException $e) {
            return $this->back($creator)->with('error', $e->getMessage());
        }

        return $this->back($creator, $result['changed'] ? 'ألغيت متابعة هذا المبدع.' : null);
    }

    protected function isPublic(CreatorProfile $creator): bool
    {
        $creator->loadMissing('user:id,is_frozen');

        return $creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen;
    }

    protected function followsAnyone(Request $request): bool
    {
        return \App\Models\CreatorFollow::query()->where('user_id', $request->user()->id)->exists();
    }

    /** العودة لحيث كان المستخدم (صفحة أحجية أو صفحة المبدع أو الخلاصة) إن كانت من موقعنا، وإلا لصفحة المبدع. */
    protected function back(CreatorProfile $creator, ?string $status = null): RedirectResponse
    {
        $previous = url()->previous();
        $target = str_starts_with($previous, url('/')) ? $previous : route('creators.show', $creator->slug);
        $redirect = redirect()->to($target);

        return $status === null ? $redirect : $redirect->with('engagement_status', $status);
    }
}
