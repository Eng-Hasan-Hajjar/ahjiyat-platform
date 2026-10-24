<?php

namespace App\Console\Commands;

use App\Services\AhjiyatPass\PassReconciler;
use Illuminate\Console\Command;

class ReconcileAhjiyatPass extends Command
{
    protected $signature = 'ahjiyat-pass:reconcile {--repair : أعد بناء pass_points وhighest_tier المشتقّين من الدفتر (لا يمسّ دفترًا ولا مطالبة ولا شراءً)}';

    protected $description = 'E40: مطابقة مسار الأحجيات (قراءة فقط افتراضيًا): نقاط مقابل الدفتر، الشريحة مقابل العتبات، المميز مقابل الشراء المحقَّق، المطالبات مقابل الشريحة والمنح، نقاط خارج النافذة، تعريفات المسار. رمز الخروج 1 عند وجود انحراف critical أو warning.';

    public function handle(PassReconciler $reconciler): int
    {
        $report = $reconciler->run((bool) $this->option('repair'));

        foreach ($report['findings'] as $f) {
            $this->line(sprintf('  [%s] %s %s — %s', $f['severity'], $f['code'], $f['subject'], $f['message']));
        }

        $this->line(sprintf('نتائج: %d%s — أُصلح: %d', count($report['findings']), $report['truncated'] ? ' (مقتطع)' : '', $report['repaired']));

        $serious = collect($report['findings'])->whereIn('severity', ['critical', 'warning'])->count();

        if ($serious === 0) {
            $this->info('المسار والتقدّم والمطالبات والمشتريات متسقة.');

            return self::SUCCESS;
        }

        $this->warn('بقيت انحرافات تحتاج مراجعة بشرية؛ راجع دليل تشغيل مسار الأحجيات.');

        return self::FAILURE;
    }
}
