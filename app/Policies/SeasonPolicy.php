<?php

namespace App\Policies;

use App\Models\Season;
use App\Models\User;

class SeasonPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'seasons';

    public function publish(User $user, Season $season): bool
    {
        return $user->can('seasons.publish');
    }

    public function preview(User $user, Season $season): bool
    {
        return $user->can('seasons.preview');
    }
}