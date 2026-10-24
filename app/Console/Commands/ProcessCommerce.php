<?php

namespace App\Console\Commands;

use App\Services\Commerce\CommerceMaintenanceService;
use Illuminate\Console\Command;

class ProcessCommerce extends Command
{
    protected $signature = 'commerce:process
        {--dry-run : عُدّ ما سيُعالَج فقط بلا استعلام مزوّد ولا إشعارات}
        {--limit= : أقصى عدد طلبات في الدفعة}';

    protected $description = 'E41: صيانة التجارة (idempotent ومحدودة): مزامنة طلبات مستضافة بانتظار الدفع لم يصلها webhook (استعلام المزوّد إن دعمه)، وإعادة محاولة الإشعارات المعزولة. لا استطلاع لا نهائي ولا تأكيد إلا عبر المسار الموثوق.';

    public function handle(CommerceMaintenanceService $maintenance): int
    {
        $stats = $maintenance->run((bool) $this->option('dry-run'), $this->option('limit') !== null ? (int) $this->option('limit') : null);

        $this->line(sprintf('مفحوص: %d — مُزامَن: %d (طُبِّق: %d، المزوّد غير متاح: %d) — إشعارات أُعيدت محاولتها: %d%s', $stats['scanned'], $stats['synced'], $stats['applied'], $stats['unavailable'], $stats['notified'], $stats['dry_run'] ? ' [تجريبي]' : ''));

        return self::SUCCESS;
    }
}
