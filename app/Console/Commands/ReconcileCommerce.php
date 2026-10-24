<?php

namespace App\Console\Commands;

use App\Services\Commerce\CommerceReconciler;
use Illuminate\Console\Command;

class ReconcileCommerce extends Command
{
    protected $signature = 'commerce:reconcile
        {--provider= : حصر المطابقة بمزوّد واحد}
        {--purchase= : حصر المطابقة بطلب (المعرّف العام أو مرجع الطلب)}
        {--limit= : أقصى صفوف لكل فحص (افتراضي من الإعداد، وله سقف)}
        {--query : استعلم المزوّد (إن دعمه) وقارن فقط، دفعة محدودة، بلا كتابة}
        {--json : مخرج JSON}
        {--fix-derived : املأ أعمدة المرآة المشتقّة provider_status/provider_paid_at لشراء محقَّق بحدث paid مطبَّق في السجل (لا يمسّ الحالة ولا الأحداث)}';

    protected $description = 'E41: مطابقة التجارة (قراءة فقط افتراضيًا): شراء محقَّق بلا مميز، مميز بلا شراء، مدفوع عند المزوّد ومحلي ينتظر، محقَّق محليًا والمزوّد لا يؤكد، مراجع/أحداث مكررة، عدم تطابق مبلغ/عملة، استرداد غير متسق، جلسات قديمة. رمز الخروج 1 عند critical أو warning.';

    public function handle(CommerceReconciler $reconciler): int
    {
        $report = $reconciler->run([
            'provider' => $this->option('provider'),
            'purchase' => $this->option('purchase'),
            'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            'query' => (bool) $this->option('query'),
            'fix_derived' => (bool) $this->option('fix-derived'),
        ]);

        $serious = collect($report['findings'])->whereIn('severity', ['critical', 'warning'])->count();

        if ($this->option('json')) {
            $this->line((string) json_encode(['findings' => $report['findings'], 'counts' => $report['counts'], 'fixed' => $report['fixed'], 'truncated' => $report['truncated'], 'queried' => $report['queried'], 'ok' => $serious === 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $serious === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['findings'] as $f) {
            $this->line(sprintf('  [%s] %s %s — %s', $f['severity'], $f['code'], $f['subject'], $f['message']));
        }

        $this->line(sprintf('نتائج: %d%s — أُصلح (مشتقّ): %d — استُعلم من المزوّد: %d', count($report['findings']), $report['truncated'] ? ' (مقتطع)' : '', $report['fixed'], $report['queried']));

        if ($serious === 0) {
            $this->info('التجارة متسقة.');

            return self::SUCCESS;
        }

        $this->warn('بقيت انحرافات تحتاج مراجعة بشرية؛ راجع دليل تشغيل التجارة.');

        return self::FAILURE;
    }
}
