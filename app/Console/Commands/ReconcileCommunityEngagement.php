<?php

namespace App\Console\Commands;

use App\Services\Engagement\EngagementReconciler;
use Illuminate\Console\Command;

class ReconcileCommunityEngagement extends Command
{
    protected $signature = 'community:reconcile-engagement {--dry-run : كشف الانحراف دون تعديل} {--chunk=500 : حجم دفعة القراءة}';

    protected $description = 'E29: يقارن عدّادات التفاعل (ratings_count/ratings_sum/favorites_count/followers_count) بالصفوف الحقيقية ويصلح الانحراف. الصفوف هي المصدر الوحيد للحقيقة؛ آمن للتكرار.';

    public function handle(EngagementReconciler $reconciler): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $reconciler->run($dry, max(50, min(5000, (int) $this->option('chunk'))));

        $this->line('أعمال مفحوصة: '.$result['works'].' — مبدعون مفحوصون: '.$result['creators']);
        $driftCount = count($result['drift']);
        $this->line('انحراف مكتشف: '.$driftCount);

        foreach (array_slice($result['drift'], 0, 20) as $d) {
            $this->line(sprintf('  %s#%d %s: مخزَّن=%d فعلي=%d', $d['table'], $d['id'], $d['column'], $d['stored'], $d['actual']));
        }

        if ($dry) {
            $this->info($driftCount === 0 ? 'العدّادات مطابقة للصفوف (dry-run: لم يُعدَّل شيء).' : 'dry-run: لم يُعدَّل شيء. شغّل الأمر بدون --dry-run للإصلاح.');

            return $driftCount === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('أُصلح '.$result['fixed'].' عدّادًا.');

        return self::SUCCESS;
    }
}
