<?php

namespace App\Events;

/** حدث مجال (E17-A) بعد commit: FriendChallengeCreated. يحمل معرّف التحدي فقط. */
class FriendChallengeCreated
{
    public function __construct(public readonly int $challengeId) {}
}
