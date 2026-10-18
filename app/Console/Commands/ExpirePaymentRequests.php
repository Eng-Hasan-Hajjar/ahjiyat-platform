<?php

namespace App\Console\Commands;

use App\Services\Payments\SponsorshipPaymentService;
use Illuminate\Console\Command;

class ExpirePaymentRequests extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'E32: يُنهي طلبات الدعم «بانتظار الدفع» المتأخرة عن مهلتها (حالة فقط، لا حذف). المرسَل/المحقَّق/المسترد لا ينتهي أبدًا. Idempotent؛ يُجدوَل كل 15 دقيقة.';

    public function handle(SponsorshipPaymentService $payments): int
    {
        try {
            $count = $payments->expireStale();
        } catch (\Throwable $e) {
            report($e);
            $this->error('فشل إنهاء الطلبات المتأخرة: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("expired={$count}");

        return self::SUCCESS;
    }
}
