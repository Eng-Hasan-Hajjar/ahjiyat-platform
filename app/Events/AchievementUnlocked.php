<?php

namespace App\Events;

use App\Models\Achievement;
use App\Models\User;

/**
 * E15: حدث مجال يُطلَق بعد commit فتح الإنجاز فعليًا (AchievementService). مستمعه (إشعار) ثانوي تمامًا:
 * فشله لا يمسّ فتح الإنجاز ولا مكافآته.
 */
class AchievementUnlocked
{
    public function __construct(public readonly User $user, public readonly Achievement $achievement) {}
}
