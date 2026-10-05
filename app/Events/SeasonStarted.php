<?php

namespace App\Events;

use App\Models\Season;

/**
 * E15+: حدث مجال: الموسم صار مباشرًا لأول مرة (SeasonLifecycleService)، يُطلَق بعد commit وبمرة واحدة فقط لكل موسم.
 */
class SeasonStarted
{
    public function __construct(public readonly Season $season) {}
}
