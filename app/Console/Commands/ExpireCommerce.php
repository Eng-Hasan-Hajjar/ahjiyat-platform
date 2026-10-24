<?php

namespace App\Console\Commands;

use App\Models\DigitalPurchase;
use App\Services\AhjiyatPass\PassPremiumService;
use Illuminate\Console\Command;

class ExpireCommerce extends Command
{
    protected $signature = 'commerce:expire
        {--dry-run : عُدّ ما سينتهي فقط بلا تغيير}
        {--limit= : أقصى عدد في الدفعة}';

    protected $description = 'E41: إنهاء صلاحية طلبات «بانتظار الدفع» المتأخرة (يدوية ومستضافة). الطلب الذي عليه علم مراجعة مالية قائم لا ينتهي آليًا. idempotent ومحدود بدفعة؛ المقدَّم/المحقَّق لا ينتهي أبدًا. (يُنفَّذ أيضًا ضمن ahjiyat-pass:process.)';

    public function handle(PassPremiumService $premium): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        if ($this->option('dry-run')) {
            $count = DigitalPurchase::query()
                ->where('status', DigitalPurchase::STATUS_AWAITING)->whereNotNull('expires_at')->where('expires_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('review_flag')->orWhereNotNull('review_resolved_at'))
                ->limit($limit ?? (int) config('ahjiyat_pass.purchase.expire_batch', 200))->count();

            $this->line(sprintf('طلبات ستنتهي صلاحيتها: %d [تجريبي]', $count));

            return self::SUCCESS;
        }

        $this->line(sprintf('طلبات انتهت صلاحيتها: %d', $premium->expireStale($limit)));

        return self::SUCCESS;
    }
}
