<?php

namespace App\Console\Commands;

use App\Services\TrustSafety\ModerationReconciler;
use Illuminate\Console\Command;

/** E38 - مصالحة طبقة الإشراف. قراءة فقط افتراضيًا؛ --fix لإصلاح المشتقّ الآمن فقط (لا تاريخ ولا حالة مجال). */
class ReconcileModeration extends Command
{
    protected $signature = 'moderation:reconcile {--fix : أصلح الانحرافات المشتقّة الآمنة فقط (أرقام الحالات وعلامات active_marker)} {--limit= : حدّ العيّنة لكل فحص}';

    protected $description = 'فحص اتساق حالات الإشراف والإجراءات والاعتراضات (قراءة فقط افتراضيًا؛ --fix لإصلاح المشتقّ الآمن فقط)';

    public function handle(ModerationReconciler $reconciler): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $result = $reconciler->run((bool) $this->option('fix'), $limit);

        $rows = [];

        foreach ($result['checks'] as $name => $c) {
            $rows[] = [$name, $c['level'], $c['count'], $c['fixable'] ? ($this->option('fix') ? $c['fixed'] : 'قابل للإصلاح') : 'يدوي', implode(',', $c['sample'])];
        }

        $this->table(['الفحص', 'المستوى', 'العدد', 'الإصلاح', 'عيّنة'], $rows);

        if ($result['total'] === 0) {
            $this->info('الطبقة متّسقة: لا انحراف.');

            return self::SUCCESS;
        }

        $this->{$result['integrity'] > 0 ? 'warn' : 'info'}(sprintf('المجموع: %d · انحراف داخلي متبقٍّ: %d · أُصلح: %d', $result['total'], $result['integrity'], $result['fixed']));

        return $result['integrity'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
