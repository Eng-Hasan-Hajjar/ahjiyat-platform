<?php

namespace App\Policies;

use App\Models\PuzzleLabExercise;
use App\Models\User;

/**
 * E47 - إدارة تمارين «مختبر الأحجيات» بصلاحيات الأحجيات القائمة نفسها (لا صلاحيات جديدة ولا Seeder للأدوار):
 *  puzzles.view/create/update/delete من BasePermissionPolicy، والنشر/الإخفاء بـpuzzles.publish، والمعاينة الإدارية بـpuzzles.view.
 * اللاعب العادي لا يملك أيًّا منها، فلا يرى مسودة ولا يعاينها.
 */
class PuzzleLabExercisePolicy extends BasePermissionPolicy
{
    protected string $prefix = 'puzzles';

    public function publish(User $user, PuzzleLabExercise $exercise): bool
    {
        return $user->can('puzzles.publish');
    }

    /** معاينة تمرين غير منشور (مسودة/مخفي) من الإدارة فقط. */
    public function preview(User $user, PuzzleLabExercise $exercise): bool
    {
        return $user->can('puzzles.view');
    }
}
