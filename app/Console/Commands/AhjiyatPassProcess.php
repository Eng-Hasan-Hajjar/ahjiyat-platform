<?php

namespace App\Console\Commands;

use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AhjiyatPass\PassPremiumService;
use App\Services\AhjiyatPass\PassReminderService;
use Illuminate\Console\Command;

class AhjiyatPassProcess extends Command
{
    protected $signature = 'ahjiyat-pass:process';

    protected $description = 'E40: صيانة مسار الأحجيات (idempotent): تفعيل/إنهاء المسارات المجدولة بالتاريخ، إنهاء طلبات المميز «بانتظار الدفع» المتأخرة، وتذكير مهلة الاستلام. لا يمنح نقاطًا ولا مميزًا ولا مكافآت.';

    public function handle(PassLifecycleService $lifecycle, PassPremiumService $premium, PassReminderService $reminders): int
    {
        $sync = $lifecycle->sync();
        $expired = $premium->expireStale();
        $reminded = $reminders->run();

        $this->line(sprintf('فُعِّل: %d — انتهى: %d — طلبات انتهت صلاحيتها: %d — مسارات التذكير: %d (مفحوص %d، أُرسل %d)', $sync['activated'], $sync['ended'], $expired, $reminded['passes'], $reminded['scanned'], $reminded['sent']));

        return self::SUCCESS;
    }
}
