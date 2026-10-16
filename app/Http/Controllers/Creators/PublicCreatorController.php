<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use Illuminate\Http\Request;

/**
 * E28 - صفحة المبدع العامة /creators/{slug}. عامة للضيوف لكن لمبدع معتمَد وحسابه غير مجمَّد فقط (غيره 404).
 * تعرض: الاسم، الصورة الرمزية (هوية اللاعب القائمة)، النبذة، تاريخ الانضمام كمبدع، عدد المنشور، إجمالي اللاعبين، والأحجيات الظاهرة فقط (مرقَّمة).
 * لا بريد ولا هاتف ولا حالة إدارية ولا علامات احتيال ولا أي بيانات لاعب خاصة.
 */
class PublicCreatorController extends Controller
{
    public function __construct(protected CosmeticLoadoutService $loadouts) {}

    public function show(Request $request, CreatorProfile $creator)
    {
        $creator->loadMissing('user:id,name,is_frozen');

        abort_unless($creator->isApproved() && $creator->user !== null && ! $creator->user->is_frozen, 404);

        $totals = CreatorPuzzle::query()->visible()->where('creator_profile_id', $creator->id)
            ->selectRaw('COUNT(*) as published, COALESCE(SUM(plays_count), 0) as plays')->first();

        $works = CreatorPuzzle::query()->visible()
            ->where('creator_profile_id', $creator->id)
            ->select('id', 'public_id', 'creator_profile_id', 'published_title', 'published_category_id', 'published_difficulty', 'published_at', 'plays_count')
            ->with('publishedCategory:id,name')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate((int) config('creators.page_size'));

        $loadout = $this->loadouts->loadoutFor($creator->user);

        return view('creator.public', [
            'creator' => $creator,
            'avatar' => $loadout['avatar'] ?? null,
            'frame' => $loadout['profile_frame'] ?? null,
            'published' => (int) ($totals->published ?? 0),
            'plays' => (int) ($totals->plays ?? 0),
            'works' => $works,
        ]);
    }
}
