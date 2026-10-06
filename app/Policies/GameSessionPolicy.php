<?php

namespace App\Policies;

use App\Models\GameSession;
use App\Models\User;

class GameSessionPolicy
{
    public function reveal(User $user, GameSession $session): bool
    {
        return $user->id === $session->user_id && ! $session->isCompetitive(); // E17: الجلسات التنافسية لا تُكشَف بمسار الألعاب القياسي
    }
}