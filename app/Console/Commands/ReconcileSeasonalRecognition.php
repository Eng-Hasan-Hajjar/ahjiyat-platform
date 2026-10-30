<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\SeasonalRecognition\SeasonSupporterReconciler;
use App\Services\SeasonalRecognition\SeasonWriterReconciler;
use Illuminate\Console\Command;

/**
 * E44 - مطابقة التقدير الموسمي (**قراءة فقط تمامًا**): مجاميع الكتّاب والميزانيات مقابل دفتر التوزيعات، إحصاءات الداعمين مقابل دفتر النقاط،
 * البطاقات مقابل الدفعات، مشتريات متجر منحت نقاطًا مرتين/لم تُمنح/غموض الموسم. لا خيار إصلاح هنا عمدًا: إعادة البناء إجراء إداري صريح بصلاحية ومسار تدقيق من صفحة «التقدير الموسمي».
 * رمز الخروج 1 عند وجود أي مخالفة.
 */
class ReconcileSeasonalRecognition extends Command
{
    protected $signature = 'seasonal-recognition:reconcile {season? : slug الموسم (الافتراضي: كل المواسم التي لها إعدادات تقدير)} {--limit= : أقصى عدد مخالفات تُفحص لكل فحص}';

    protected $description = 'E44: مطابقة التقدير الموسمي (قراءة فقط): نجوم الكتّاب وميزانيات الناخبين ونقاط الدعم والبطاقات مقابل الدفاتر. لا يصلح شيئًا. رمز الخروج 1 عند أي مخالفة.';

    public function handle(SeasonWriterReconciler $writers, SeasonSupporterReconciler $supporters): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $slug = trim((string) $this->argument('season'));

        $seasons = Season::query()
            ->when($slug !== '', fn ($q) => $q->where('slug', $slug), fn ($q) => $q->whereIn('id', \Illuminate\Support\Facades\DB::table('season_recognition_settings')->select('season_id')))
            ->orderBy('id')->get();

        if ($seasons->isEmpty()) {
            $this->info($slug !== '' ? 'لا موسم بهذا المعرّف.' : 'لا مواسم لها إعدادات تقدير بعد.');

            return $slug !== '' ? self::FAILURE : self::SUCCESS;
        }

        $total = 0;

        foreach ($seasons as $season) {
            $w = $writers->inspect($season, $limit);
            $s = $supporters->inspect($season, $limit);
            $total += count($w) + count($s);

            $this->line(sprintf('الموسم %s — الكتّاب: %d مخالفة — الداعمون: %d مخالفة', $season->slug, count($w), count($s)));

            foreach (array_slice(array_merge($w, $s), 0, 50) as $f) {
                $this->line('  '.json_encode($f, JSON_UNESCAPED_UNICODE));
            }
        }

        if ($total === 0) {
            $this->info('الدفاتر والمجاميع متسقة.');

            return self::SUCCESS;
        }

        $this->warn('توجد مخالفات؛ راجع دليل تشغيل التقدير الموسمي (لا إصلاح آلي).');

        return self::FAILURE;
    }
}
