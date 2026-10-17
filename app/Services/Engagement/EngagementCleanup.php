<?php

namespace App\Services\Engagement;

use App\Models\CommunityPuzzleFavorite;
use App\Models\CreatorFollow;
use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E29 - عند حذف حساب: بياناته الشخصية الخاصة (مفضلة، متابعات، مجموعات) تُحذف **مع إنقاص العدّادات العامة**، فلا يبقى رقم عام ينسب لمن لم يعد موجودًا.
 * التقييمات تبقى مجهولة (user_id → NULL بقيد القاعدة) لأنها مساهمة في المتوسط لا تحمل هوية: فالمجمَّع صحيح دون إعادة حساب.
 * يُستدعى من مستمع deleting على User (نمط E19 نفسه) ولا يمنع الحذف إن فشل: قيود الحذف التتابعي بالقاعدة تضمن عدم بقاء بيانات شخصية،
 * وأي انحراف عدّاد ينتج يلتقطه community:reconcile-engagement.
 */
class EngagementCleanup
{
    public function purgeUser(User $user): void
    {
        DB::transaction(function () use ($user) {
            CommunityPuzzleFavorite::query()->where('user_id', $user->id)->select('id', 'creator_puzzle_id')->chunkById(500, function ($rows) {
                foreach ($rows->groupBy('creator_puzzle_id') as $workId => $group) {
                    EngagementCounters::adjust('creator_puzzles', (int) $workId, ['favorites_count' => -$group->count()]);
                }

                CommunityPuzzleFavorite::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

            CreatorFollow::query()->where('user_id', $user->id)->select('id', 'creator_profile_id')->chunkById(500, function ($rows) {
                foreach ($rows->groupBy('creator_profile_id') as $profileId => $group) {
                    EngagementCounters::adjust('creator_profiles', (int) $profileId, ['followers_count' => -$group->count()]);
                }

                CreatorFollow::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

            $collectionIds = UserCollection::query()->where('user_id', $user->id)->pluck('id');
            UserCollectionItem::query()->whereIn('user_collection_id', $collectionIds)->delete();
            UserCollection::query()->whereIn('id', $collectionIds)->delete();
        });
    }
}
