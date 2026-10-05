<?php

namespace App\Services\Social;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use Illuminate\Support\Collection;

/** يُرفق بكل لاعب أفاتاره وإطاره وصلاحية رابط ملفه بدفعة استعلامات (نفس نمط لوحة الصدارة) - لا N+1، ولا حقول حساسة. */
class PlayerCardLoader
{
    /** @param  iterable<User>  $users */
    public function attach(iterable $users, ?User $viewer): void
    {
        $users = Collection::make($users);

        if ($users->isEmpty()) {
            return;
        }

        $loadouts = UserCosmeticLoadout::query()->whereIn('user_id', $users->pluck('id'))->with('storeItem')->get()->groupBy('user_id');

        $users->each(function (User $user) use ($loadouts, $viewer) {
            $rows = ($loadouts->get($user->id) ?? collect())->keyBy('slot');

            $user->identityAvatar = $rows->get(StoreItem::SLOT_AVATAR)?->storeItem;
            $user->identityFrame = $rows->get(StoreItem::SLOT_FRAME)?->storeItem;
            $user->profileLinkable = $user->profileViewableBy($viewer);
        });
    }
}
