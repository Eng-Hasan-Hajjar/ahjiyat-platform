<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersCheckReport;
use App\Services\Operations\PreflightChecker;
use Illuminate\Console\Command;

/**
 * E25: فحص ما قبل النشر. production:check + الترحيلات المعلّقة (مع مسح مخاطر ساكن)، ترجمة Blade، حداثة كاش config/routes والأصول، وضع الصيانة، ملفات القفل.
 * لا يُغيّر شيئًا إطلاقًا (لا migrate، لا clear، لا restart). Exit 0 = قابل للنشر، 1 = FAIL حاجب.
 */
class ReleasePreflight extends Command
{
    use RendersCheckReport;

    protected $signature = 'release:preflight {--json : مخرجات JSON} {--skip-views : تخطّي فحص ترجمة قوالب Blade (أسرع)}';

    protected $description = 'فحص ما قبل النشر (Read-Only): هل هذا الإصدار قابل للنشر؟ لا ينفّذ migrate ولا يمسح ولا يعيد تشغيل شيء';

    public function handle(PreflightChecker $checker): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->info('== فحص ما قبل النشر (Read-Only، لا يغيّر شيئًا) ==');
            $this->newLine();
        }

        $report = $checker->run((bool) $this->option('skip-views'));
        $this->renderReport($report, $json);

        if (! $json) {
            $this->renderSummary($report);
            $report->hasFailures()
                ? $this->error('غير جاهز للنشر: عالج حالات FAIL أعلاه ثم أعد الفحص.')
                : $this->info('جاهز للنشر (راجع التحذيرات والتأكيدات اليدوية أعلاه).');
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
