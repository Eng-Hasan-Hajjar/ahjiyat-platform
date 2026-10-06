<?php

namespace App\Events;

/** حدث مجال (E19) بعد commit: TeamJoinRequestAccepted. يحمل معرّف السجل فقط. */
class TeamJoinRequestAccepted
{
    public function __construct(public readonly int $requestId) {}
}
