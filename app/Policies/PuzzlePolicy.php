<?php

namespace App\Policies;

use App\Models\Puzzle;
use App\Models\User;

class PuzzlePolicy extends BasePermissionPolicy
{
    protected string $prefix = 'puzzles';

    public function publish(User $user, Puzzle $puzzle): bool
    {
        return $user->can('puzzles.publish');
    }
}