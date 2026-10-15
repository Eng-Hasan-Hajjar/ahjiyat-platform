<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersCheckReport;
use App\Services\Operations\ProductionChecker;
use Illuminate\Console\Command;

/**
 * E25: عقد بيئة الإنتاج. المنطق كله في App\Services\Operations\ProductionChecker (للقراءة فقط: لا كتابة قاعدة/كاش/تخزين، لا ترحيل، لا إعادة تشغيل).
 * Exit 0 = جاهز (قد توجد تحذيرات)، 1 = مشكلة حاجبة. لا تُطبع أي قيمة سرية: PASS/WARN/FAIL + اسم الإعداد فقط.
 * الشدّة: بالإنتاج (APP_ENV=production) تصير معظم المخالفات FAIL؛ خارجه تبقى تحذيرات، إلا الحاجبة دائمًا (APP_DEBUG، APP_KEY، القاعدة…).
 */
class ProductionCheck extends Command
{
    use RendersCheckReport;

    protected $signature = 'production:check {--json : مخرجات JSON لأدوات المراقبة وCI (بلا قيم سرية)}';

    protected $description = 'فحوصات جاهزية الإنتاج (Read-Only بالكامل) - Exit code صفر إذا لا مشاكل حرجة، غير صفر خلاف ذلك';

    public function handle(ProductionChecker $checker): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->info('== فحص جاهزية الإنتاج (Read-Only) ==');
            $this->newLine();
        }

        $report = $checker->run();
        $this->renderReport($report, $json);

        if (! $json) {
            $this->renderSummary($report);
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
