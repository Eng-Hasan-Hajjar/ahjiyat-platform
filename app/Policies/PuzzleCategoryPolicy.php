<?php

namespace App\Policies;

use App\Models\User;

class PuzzleCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('puzzles.manage_categories');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('puzzles.manage_categories');
    }

    public function create(User $user): bool
    {
        return $user->can('puzzles.manage_categories');
    }

    public function update(User $user, $model): bool
    {
        return $user->can('puzzles.manage_categories');
    }

    public function delete(User $user, $model): bool
    {
        return $user->can('puzzles.manage_categories');
    }
}