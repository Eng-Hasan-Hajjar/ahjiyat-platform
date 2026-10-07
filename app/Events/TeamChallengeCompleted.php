<?php

namespace App\Events;

/** حدث مجال (E20) بعد commit: TeamChallengeCompleted. يحمل معرّف التحدّي فقط. */
class TeamChallengeCompleted
{
    public function __construct(public readonly int $challengeId) {}
}
