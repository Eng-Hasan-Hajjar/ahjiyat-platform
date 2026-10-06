<?php

namespace App\Events;

/** حدث مجال (E19) بعد commit: TeamJoinRequestCreated. يحمل معرّف السجل فقط. */
class TeamJoinRequestCreated
{
    public function __construct(public readonly int $requestId) {}
}
