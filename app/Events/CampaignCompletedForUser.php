<?php

namespace App\Events;

/**
 * حدث مجال: هذا المستخدم أكمل هذه الحملة لأول مرة (CampaignCompletionService)، يُطلَق بعد commit وبمرة واحدة لكل
 * (مستخدم، حملة). يحمل المعرّفات فقط.
 */
class CampaignCompletedForUser
{
    public function __construct(
        public readonly int $userId,
        public readonly int $campaignId,
    ) {}
}
