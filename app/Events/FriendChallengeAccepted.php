<?php

namespace App\Events;

/** حدث مجال (E17-A) بعد commit: FriendChallengeAccepted. يحمل معرّف التحدي فقط. */
class FriendChallengeAccepted
{
    public function __construct(public readonly int $challengeId) {}
}
