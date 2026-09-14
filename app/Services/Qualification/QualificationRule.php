<?php

namespace App\Services\Qualification;

use App\Models\CampaignGate;
use App\Models\User;

/**
 * تُستدعى فقط بعد أن أصبحت الـGate مكتملة فعلياً للمستخدم (Derived Fact
 * من CampaignProgressService) - الـRule لا تتحقق هي نفسها من الاكتمال،
 * فقط تقرر/تسجّل التأهّل. يجب أن تكون qualify() Idempotent بالكامل -
 * استدعاؤها 10 مرات لنفس (user, gate) ينتج صفاً واحداً على الأكثر.
 */
interface QualificationRule
{
    public function qualify(User $user, CampaignGate $gate): void;

    public function isQualified(User $user, CampaignGate $gate): bool;
}