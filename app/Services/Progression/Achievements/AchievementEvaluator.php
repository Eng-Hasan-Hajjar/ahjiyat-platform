<?php

namespace App\Services\Progression\Achievements;

use App\Models\Achievement;
use App\Models\User;

interface AchievementEvaluator
{
    public function supports(Achievement $achievement): bool;

    public function currentValue(User $user, Achievement $achievement): int;
}