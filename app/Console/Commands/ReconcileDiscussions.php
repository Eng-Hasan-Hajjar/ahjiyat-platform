<?php

namespace App\Console\Commands;

use App\Services\Discussions\DiscussionReconciler;
use Illuminate\Console\Command;

class ReconcileDiscussions extends Command
{
    protected $signature = 'discussions:reconcile {--dry-run : كشف الانحراف دون تعديل} {--purge-orphans : حذف النقاشات اليتيمة (كيانها لم يعد موجودًا)} {--chunk=500 : حجم دفعة القراءة}';

    protected $description = 'E36: يقارن comments_count للنقاشات و likes_count للتعليقات بالصفوف الحقيقية ويصلح الانحراف، ويكشف الشذوذات (ردّ بعمق زائد، بلاغ بعلامة مفتوحة خاطئة، نقاش يتيم). الصفوف هي المصدر الوحيد للحقيقة؛ آمن للتكرار.';

    public function handle(DiscussionReconciler $reconciler): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $reconciler->run($dry, max(50, min(5000, (int) $this->option('chunk'))), (bool) $this->option('purge-orphans'));

        $this->line('نقاشات مفحوصة: '.$result['threads'].' — تعليقات مفحوصة: '.$result['comments']);
        $driftCount = count($result['drift']);
        $this->line('انحراف عدّادات مكتشف: '.$driftCount);

        foreach (array_slice($result['drift'], 0, 20) as $d) {
            $this->line(sprintf('  %s#%d %s: مخزَّن=%d فعلي=%d', $d['table'], $d['id'], $d['column'], $d['stored'], $d['actual']));
        }

        $this->line('شذوذات هيكلية: '.count($result['anomalies']));

        foreach (array_slice($result['anomalies'], 0, 20) as $a) {
            $this->line(sprintf('  [%s] #%d %s', $a['type'], $a['id'], $a['detail']));
        }

        if ($dry) {
            $clean = $driftCount === 0 && $result['anomalies'] === [];
            $this->info($clean ? 'النقاشات مطابقة للصفوف (dry-run: لم يُعدَّل شيء).' : 'dry-run: لم يُعدَّل شيء. شغّل الأمر بدون --dry-run للإصلاح.');

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        $this->info('أُصلح '.$result['fixed'].' عدّادًا. نقاشات يتيمة محذوفة: '.$result['purged'].'.');

        // الشذوذات الهيكلية لا تُصلَح تلقائيًا: نبلغ بها (رمز خروج غير صفري) ليتدخل بشر.
        return $result['anomalies'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
