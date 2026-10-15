<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersCheckReport;
use App\Services\Operations\SmokeChecker;
use Illuminate\Console\Command;

/**
 * E25: فحص الدخان بعد النشر - طلبات GET فقط على المسارات العامة (انظر config/operations.php smoke.routes) بمهلة صغيرة.
 * لا كتابة: لا صداقة/محاولة أحجية/رسالة/محفظة. Exit 0 = كل المسارات 200 (بلا FAIL)، 1 = فشل.
 */
class ReleaseSmoke extends Command
{
    use RendersCheckReport;

    protected $signature = 'release:smoke
        {--url= : العنوان الأساسي (الافتراضي APP_URL)}
        {--profile= : public_id لاعب عام لفحص /players/{profile} (اختياري)}
        {--expect-release= : يتحقق أن /ready يعرض هذا المعرّف (يكشف نشرًا لم يُفعَّل بعد)}
        {--json : مخرجات JSON}';

    protected $description = 'فحص دخان بعد النشر (GET فقط، بلا كتابة): /up و/ready والرئيسية وتسجيل الدخول والأحجيات والمنافسات والمتجر';

    public function handle(SmokeChecker $checker): int
    {
        $json = (bool) $this->option('json');
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $profile = $this->option('profile') ? (string) $this->option('profile') : null;
        $expect = $this->option('expect-release') ? (string) $this->option('expect-release') : null;

        if (! $json) {
            $this->info('== فحص الدخان بعد النشر (GET فقط) ==');
            $this->newLine();
        }

        $report = $checker->run($url, $profile, $expect);
        $this->renderReport($report, $json);

        if (! $json) {
            $this->renderSummary($report);
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
