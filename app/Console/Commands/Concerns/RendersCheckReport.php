<?php

namespace App\Console\Commands\Concerns;

use App\Services\Operations\CheckReport;
use App\Services\Operations\CheckResult;
use App\Support\SecretRedactor;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * E25 - عرض موحّد لتقارير الفحص: PASS/WARN/FAIL/INFO + اسم الإعداد، بلا قيم سرية.
 * كل نص يمرّ على SecretRedactor قبل الطباعة (طبقة أمان ثانية فوق كون الرسائل خالية من القيم أصلًا).
 */
trait RendersCheckReport
{
    protected function renderReport(CheckReport $report, bool $json): void
    {
        $secrets = SecretRedactor::secrets();

        if ($json) {
            $data = $report->toArray();
            $data['checks'] = array_map(fn (array $check) => array_map(fn ($v) => SecretRedactor::scrub((string) $v, $secrets), $check), $data['checks']);

            $this->line((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return;
        }

        $colors = [CheckResult::PASS => 'green', CheckResult::WARN => 'yellow', CheckResult::FAIL => 'red', CheckResult::INFO => 'cyan'];

        foreach ($report->grouped() as $group => $results) {
            $this->comment(SecretRedactor::scrub((string) $group, $secrets));

            foreach ($results as $result) {
                $message = OutputFormatter::escape(SecretRedactor::scrub($result->message, $secrets));
                $this->line("  <fg={$colors[$result->status]}>{$result->status}</> {$message}");
            }
        }
    }

    protected function renderSummary(CheckReport $report): void
    {
        $this->newLine();
        $this->info("النتيجة: {$report->failures()} فشل حرج، {$report->warnings()} تحذير.");
    }
}
