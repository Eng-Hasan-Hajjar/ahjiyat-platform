<?php

namespace App\Events;

/** حدث مجال (E17-A) بعد commit: FriendChallengeCompleted. يحمل معرّف التحدي فقط. */
class FriendChallengeCompleted
{
    public function __construct(public readonly int $challengeId) {}
}
