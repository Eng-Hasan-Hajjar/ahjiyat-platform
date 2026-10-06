<?php

namespace App\Events;

/** حدث مجال (E19) بعد commit: TeamInvitationAccepted. يحمل معرّف السجل فقط. */
class TeamInvitationAccepted
{
    public function __construct(public readonly int $invitationId) {}
}
