<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CommunityPuzzleRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - حالة المشاهد (تقييمي/محفوظ) لقائمة أعمال بدفعة استعلامات **ثابتة العدد** (لا exists() لكل بطاقة).
 * خاصة بالمستخدم دائمًا (WHERE user_id): لا تُخزَّن بكاش عام ولا تُشارَك بين المستخدمين.
 */
class EngagementViewerState
{
    /** @param list<int> $workIds @return array<int, true> معرّفات الأعمال التي حفظها المستخدم */
    public function favoriteIds(?User $user, array $workIds): array
    {
        if ($user === null || $workIds === []) {
            return [];
        }

        return array_fill_keys(
            CommunityPuzzleFavorite::query()->where('user_id', $user->id)->whereIn('creator_puzzle_id', $workIds)->pluck('creator_puzzle_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
    }

    /** @param list<int> $workIds @return array<int, int> عمل => تقييم المستخدم */
    public function ratings(?User $user, array $workIds): array
    {
        if ($user === null || $workIds === []) {
            return [];
        }

        return CommunityPuzzleRating::query()->where('user_id', $user->id)->whereIn('creator_puzzle_id', $workIds)->pluck('rating', 'creator_puzzle_id')
            ->map(fn ($r) => (int) $r)->all();
    }

    /**
     * حالة المشاهد لعمل واحد باستعلام **واحد** (ثلاث استعلامات فرعية): تقييمي الحالي، هل حفظته، هل جرّبته (شرط أهلية التقييم).
     * «جرّبته» = محاولة واحدة على الأقل على العمل الثابت (creator_puzzle_id) عبر كل نسخه، فتعديل المبدع لا يلغي أهلية من لعب سابقًا.
     *
     * @return array{rating: int|null, favorite: bool, played: bool}
     */
    public function forWork(?User $user, int $workId): array
    {
        if ($user === null) {
            return ['rating' => null, 'favorite' => false, 'played' => false];
        }

        $row = DB::query()
            ->selectSub(DB::table('community_puzzle_ratings')->select('rating')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'rating')
            ->selectSub(DB::table('community_puzzle_favorites')->selectRaw('1')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'favorite')
            ->selectSub(DB::table('community_puzzle_attempts')->selectRaw('1')->where('user_id', $user->id)->where('creator_puzzle_id', $workId)->limit(1), 'played')
            ->first();

        return [
            'rating' => $row?->rating === null ? null : (int) $row->rating,
            'favorite' => $row?->favorite !== null,
            'played' => $row?->played !== null,
        ];
    }
}
