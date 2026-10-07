<?php

namespace App\Events;

/** حدث مجال (E20) بعد commit: TeamChallengeCreated. يحمل معرّف التحدّي فقط. */
class TeamChallengeCreated
{
    public function __construct(public readonly int $challengeId) {}
}
