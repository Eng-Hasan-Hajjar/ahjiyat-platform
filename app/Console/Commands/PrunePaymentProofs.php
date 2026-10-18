<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentRetentionService;
use Illuminate\Console\Command;

class PrunePaymentProofs extends Command
{
    protected $signature = 'payments:prune-proofs {--dry-run : يعدّ المرشَّحين فقط بلا حذف}';

    protected $description = 'E32: يقلّم ملفات إثبات التحويل بعد مدة الاحتفاظ من الحالة النهائية (لا يحذف سجل الطلب، ولا يطال طلبًا مفتوحًا أو داخل نافذة النزاع). ابدأ بـ--dry-run.';

    public function handle(PaymentRetentionService $retention): int
    {
        if ($this->option('dry-run')) {
            $this->line('candidates='.$retention->count().' (dry-run: nothing was deleted)');

            return self::SUCCESS;
        }

        try {
            $count = $retention->prune();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل تقليم الإثباتات: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("pruned={$count}");

        return self::SUCCESS;
    }
}
