<?php

namespace App\Events;

/**
 * حدث مجال: مرحلة صارت مفتوحة لهذا المستخدم لأول مرة بسبب تقدّمه (StageUnlockService)، يُطلَق بعد commit وبمرة واحدة لكل
 * (مستخدم، مرحلة). يحمل المعرّفات فقط - لا لقطة نماذج.
 */
class StageUnlockedForUser
{
    public function __construct(
        public readonly int $userId,
        public readonly int $campaignId,
        public readonly int $stageId,
    ) {}
}
