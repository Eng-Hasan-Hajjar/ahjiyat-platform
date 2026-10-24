<?php

namespace App\Console\Commands;

use App\Services\Commerce\CommerceHealthService;
use Illuminate\Console\Command;

class CommerceProviderHealth extends Command
{
    protected $signature = 'commerce:provider-health {--json : مخرج JSON}';

    protected $description = 'E41: صحة مزوّدي الدفع: مفعَّل/مُهيَّأ/قدرات/آخر حدث ناجح/فشل التوقيع. لا يعرض أي سر أبدًا (Configured ✅ / Missing ❌ فقط). رمز الخروج 1 إن كان مزوّد مفعَّل غير مُهيَّأ.';

    public function handle(CommerceHealthService $health): int
    {
        $rows = $health->providers();
        $bad = collect($rows)->filter(fn ($r) => $r['enabled'] && ! $r['configured'])->count();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn ($r) => array_merge($r, ['last_event_at' => $r['last_event_at']?->toIso8601String()]), $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $bad === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($rows as $r) {
            $this->line(sprintf('%s  mode=%s enabled=%s configured=%s redirect=%s webhook=%s refund=%s query=%s last_event=%s failures=%d awaiting=%d review=%d refund_pending=%d',
                $r['key'], $r['mode'], $r['enabled'] ? 'yes' : 'no', $r['configured'] ? '✅' : '❌', $r['redirect'] ? 'yes' : 'no', $r['webhook'] ? 'yes' : 'no', $r['refund'] ? 'yes' : 'no', $r['status_query'] ? 'yes' : 'no',
                $r['last_event_at']?->format('Y-m-d H:i') ?? '-', $r['recent_failures'], $r['awaiting'], $r['needs_review'], $r['refund_pending']));
        }

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
