<?php

namespace App\Events;

/** حدث مجال: أُنشئ طلب صداقة جديد فعلًا (بعد commit). يحمل معرّف العلاقة فقط. */
class FriendRequestCreated
{
    public function __construct(public readonly int $friendshipId) {}
}
