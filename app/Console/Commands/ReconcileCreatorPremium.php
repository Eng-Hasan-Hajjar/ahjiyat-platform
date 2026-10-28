<?php

namespace App\Console\Commands;

use App\Services\CreatorPremium\CreatorPremiumReconciler;
use Illuminate\Console\Command;

class ReconcileCreatorPremium extends Command
{
    protected $signature = 'creator-premium:reconcile {--limit= : أقصى عدد طلبات/مبيعات تُفحص (الأحدث أولًا)}';

    protected $description = 'E43: مطابقة السوق المميز (قراءة فقط): الطلب المؤكَّد (E41) ↔ الوصول ↔ لقطة البيع ↔ قيد دفتر E39. لا تصلح شيئًا آليًا (الإصلاح بقرار بشري عبر لوحة الإدارة). رمز الخروج 1 إن وُجد أي انحراف.';

    public function handle(CreatorPremiumReconciler $reconciler): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $r = $reconciler->run($limit);

        $this->line(sprintf('طلبات مفحوصة: %d — مبيعات مفحوصة: %d — انحرافات: %d%s', $r['checked_purchases'], $r['checked_sales'], $r['remaining'], $r['truncated'] ? ' (المعروض الأول '.count($r['findings']).')' : ''));

        foreach (array_slice($r['findings'], 0, 100) as $f) {
            $this->line(sprintf('  [%s] %s — %s', $f['code'], $f['subject'], $f['detail']));
        }

        if ($r['remaining'] === 0) {
            $this->info('الطلبات والوصول والمبيعات والدفتر متسقة.');

            return self::SUCCESS;
        }

        $this->warn('بقيت انحرافات تحتاج مراجعة بشرية؛ راجع دليل تشغيل السوق المميز (docs/creator-premium-operations-runbook.md).');

        return self::FAILURE;
    }
}
