<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcileSponsorshipPayments extends Command
{
    protected $signature = 'sponsorship:reconcile-payments {--dry-run : للتوضيح فقط - الأمر للقراءة فقط دائمًا} {--json : مخرجات JSON}';

    protected $description = 'E32: مطابقة مالية للقراءة فقط بين طلبات الدعم والمساهمات (بلا أي إصلاح تلقائي). يخرج بـ1 عند وجود اختلافات. لا يطبع أسماء ولا بريدًا ولا مسارات إثبات ولا أسرارًا.';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $findings = $reconciliation->run();
        $counts = $reconciliation->summarize($findings);

        if ($this->option('json')) {
            $this->line((string) json_encode(['mismatches' => count($findings), 'counts' => $counts, 'findings' => $findings], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('E32 reconciliation (read-only; nothing was changed)');

        foreach ($counts as $code => $count) {
            $this->line(sprintf('%-36s %d', $code, $count));
        }

        foreach ($findings as $finding) {
            $this->line(sprintf('! %s payment=%s ref=%s contribution=%s', $finding['code'], $finding['payment'] ?? '-', $finding['reference'] ?? '-', $finding['contribution'] ?? '-'));
        }

        $this->line('mismatches='.count($findings));

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}
