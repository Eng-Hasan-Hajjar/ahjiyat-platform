<?php

namespace App\Policies;

use App\Models\AdPlacement;
use App\Models\User;

/** E14: موضع واحد بصلاحية إدارة واحدة فقط - لا إنشاء حر (مُقيَّد بالسجلّ، بند 572). */
class AdPlacementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ads.placements.manage');
    }

    public function view(User $user, AdPlacement $placement): bool
    {
        return $user->can('ads.placements.manage');
    }

    public function update(User $user, AdPlacement $placement): bool
    {
        return $user->can('ads.placements.manage');
    }
}
