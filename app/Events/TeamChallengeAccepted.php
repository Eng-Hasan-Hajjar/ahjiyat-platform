<?php

namespace App\Events;

/** حدث مجال (E20) بعد commit: TeamChallengeAccepted. يحمل معرّف التحدّي فقط. */
class TeamChallengeAccepted
{
    public function __construct(public readonly int $challengeId) {}
}
