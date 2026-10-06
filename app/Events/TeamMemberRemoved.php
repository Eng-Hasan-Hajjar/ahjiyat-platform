<?php

namespace App\Events;

/** حدث مجال (E19) بعد commit: أُزيل عضو بيد مدير الفريق أو إدارة المنصة. يحمل معرّفات فقط (الصف نفسه حُذف). */
class TeamMemberRemoved
{
    public function __construct(public readonly int $teamId, public readonly int $userId, public readonly int $membershipId) {}
}
