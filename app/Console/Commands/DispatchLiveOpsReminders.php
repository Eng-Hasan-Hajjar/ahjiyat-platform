<?php

namespace App\Console\Commands;

use App\Services\LiveOps\LiveOpsReminderService;
use Illuminate\Console\Command;

/**
 * E34 - تذكيرات LiveOps (انتهاء مهمة جارية، أهداف الأسبوع، بدء منافسة للمسجَّلين، عودة بعد غياب). لا منطق هنا: القرار كله بـLiveOpsReminderService.
 * قراءة فقط + إشعارات، Idempotent، ويُجدوَل ساعيًا. غيابه لا يكسر شيئًا.
 */
class DispatchLiveOpsReminders extends Command
{
    protected $signature = 'liveops:remind';

    protected $description = 'تذكيرات LiveOps المحدودة (مهام جارية، أهداف أسبوعية، بدء منافسة، عودة). قراءة فقط، Idempotent، ساعيًا.';

    public function handle(LiveOpsReminderService $service): int
    {
        foreach ($service->run() as $name => $s) {
            $this->info($name.': '.collect($s)->map(fn ($v, $k) => "{$k}={$v}")->implode(' '));
        }

        return self::SUCCESS;
    }
}
