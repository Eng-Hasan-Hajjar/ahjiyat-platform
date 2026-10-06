<?php

namespace App\Events;

/** حدث مجال (E19) بعد commit: TeamInvitationCreated. يحمل معرّف السجل فقط. */
class TeamInvitationCreated
{
    public function __construct(public readonly int $invitationId) {}
}
