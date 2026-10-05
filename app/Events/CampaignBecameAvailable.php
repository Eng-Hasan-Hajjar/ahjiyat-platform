<?php

namespace App\Events;

use App\Models\Campaign;

/** حدث مجال: الحملة صارت متاحة لأول مرة (CampaignLifecycleService)، يُطلَق بعد commit وبمرة واحدة فقط لكل حملة. */
class CampaignBecameAvailable
{
    public function __construct(public readonly Campaign $campaign) {}
}
