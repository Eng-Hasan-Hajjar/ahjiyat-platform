<?php

namespace App\Events;

/** حدث مجال: قُبل طلب صداقة فعلًا (بعد commit، مرة واحدة لكل علاقة). يحمل معرّف العلاقة فقط. */
class FriendshipAccepted
{
    public function __construct(public readonly int $friendshipId) {}
}
