<?php

namespace App\Services\Social;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;

/**
 * الحظر: أحادي الاتجاه بتأثير ثنائي على التفاعل. block() معاملة واحدة: يُدخل الحظر (insertOrIgnore ذري، Idempotent) ويحذف أي
 * صداقة/طلب بين الطرفين بأي اتجاه. لا يحذف أي إشعار قديم ولا تقدّم لعب، ولا يُخبَر المحظور. unblock() لا يعيد الصداقة.
 */
class BlockService
{
    public function hasBlocked(User $blocker, User $blocked): bool
    {
        return UserBlock::query()->where('blocker_id', $blocker->getKey())->where('blocked_id', $blocked->getKey())->exists();
    }

    public function blockedEitherWay(User $a, User $b): bool
    {
        return UserBlock::query()->where(function ($q) use ($a, $b) {
            $q->where(fn ($w) => $w->where('blocker_id', $a->getKey())->where('blocked_id', $b->getKey()))
                ->orWhere(fn ($w) => $w->where('blocker_id', $b->getKey())->where('blocked_id', $a->getKey()));
        })->exists();
    }

    /** @return list<int> معرّفات من بينهم وبين هذا المستخدم حظر بأي اتجاه (للاستبعاد من البحث وغيره). */
    public function hiddenIds(User $user): array
    {
        $id = $user->getKey();

        return UserBlock::query()->where('blocker_id', $id)->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $id)->pluck('blocker_id'))
            ->unique()->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array{by_me: array<int, true>, me: array<int, true>} من حظرتُهم / من حظرني من بين $ids
     */
    public function blockedAmong(User $viewer, array $ids): array
    {
        if ($ids === []) {
            return ['by_me' => [], 'me' => []];
        }

        return [
            'by_me' => array_fill_keys(UserBlock::query()->where('blocker_id', $viewer->getKey())->whereIn('blocked_id', $ids)->pluck('blocked_id')->all(), true),
            'me' => array_fill_keys(UserBlock::query()->where('blocked_id', $viewer->getKey())->whereIn('blocker_id', $ids)->pluck('blocker_id')->all(), true),
        ];
    }

    public function block(User $blocker, User $target): FriendActionResult
    {
        if ($blocker->is($target)) {
            return FriendActionResult::Self;
        }

        return DB::transaction(function () use ($blocker, $target) {
            $inserted = DB::table('user_blocks')->insertOrIgnore([[
                'blocker_id' => $blocker->getKey(),
                'blocked_id' => $target->getKey(),
                'created_at' => now(),
            ]]) === 1;

            Friendship::query()->forPair($blocker->getKey(), $target->getKey())->delete(); // صداقة أو طلب، بأي اتجاه

            return $inserted ? FriendActionResult::Blocked : FriendActionResult::AlreadyBlocked;
        });
    }

    public function unblock(User $blocker, User $target): FriendActionResult
    {
        $deleted = UserBlock::query()->where('blocker_id', $blocker->getKey())->where('blocked_id', $target->getKey())->delete();

        return $deleted > 0 ? FriendActionResult::Unblocked : FriendActionResult::NotFound; // لا استرجاع للصداقة القديمة
    }
}
