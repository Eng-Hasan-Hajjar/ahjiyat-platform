<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignLifecycleService;
use Illuminate\Console\Command;

/**
 * يلتقط الإتاحة الناتجة عن مرور الوقت (starts_at). لا منطق هنا: القرار كله بـCampaignLifecycleService (المصدر الوحيد).
 * مرشَّحوه فقط: حملات مفعَّلة لم تُتَح بعد (became_available_at null)، بـchunkById (لا تحميل للكل).
 */
class SyncCampaignAvailability extends Command
{
    protected $signature = 'campaigns:sync-availability';

    protected $description = 'التقاط إتاحة الحملات الناتجة عن مرور الوقت (CampaignBecameAvailable مرة واحدة لكل حملة). Idempotent؛ يُجدوَل كل 5 دقائق.';

    public function handle(CampaignLifecycleService $lifecycle): int
    {
        $checked = 0;
        $became = 0;

        Campaign::query()->where('is_active', true)->whereNull('became_available_at')->chunkById(100, function ($campaigns) use ($lifecycle, &$checked, &$became) {
            foreach ($campaigns as $campaign) {
                $checked++;

                try {
                    $became += $lifecycle->syncAvailability($campaign) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e); // فشل حملة واحدة لا يوقف البقية.
                }
            }
        });

        $this->info("فُحصت {$checked} حملات مفعَّلة لم تُتَح، وأُتيح منها فعلًا: {$became}.");

        return self::SUCCESS;
    }
}
