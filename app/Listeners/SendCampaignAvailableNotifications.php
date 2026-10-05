<?php

namespace App\Listeners;

use App\Events\CampaignBecameAvailable;
use App\Jobs\DispatchCampaignAvailableChunk;

/**
 * الحملة المرتبطة بموسم يُعلنها season_started أصلًا (نفس اللحظة والجمهور): لا نضاعف الإشعار ولا نعلن عن حملة
 * موسم غير منشور. الحدث نفسه يُطلَق دائمًا (حقيقة)؛ هذا قرار جمهور الإشعار فقط. لا حلقة متزامنة داخل طلب الإدارة:
 * يبدأ سلسلة Jobs بدفعات، وأي فشل يُلتقَط ولا يصل لحفظ الحملة.
 */
class SendCampaignAvailableNotifications
{
    public function handle(CampaignBecameAvailable $event): void
    {
        try {
            if ($event->campaign->season()->exists()) {
                return;
            }

            DispatchCampaignAvailableChunk::dispatch($event->campaign->getKey(), 0);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
