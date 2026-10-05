<?php

namespace App\Services\Notifications;

use App\Models\Campaign;

/**
 * وجهة إشعارات الحملة (فتح مرحلة، إكمال حملة): الموسم المنشور المرتبط بالحملة (واجهتها الفعلية للاعب) وإلا صفحة الحملة.
 * موسم غير منشور: صفحته 404 للاعب فلا نوجّه إليها. المسار من سجل النوع (allowedRoutes) والمُرسِل يتحقق منه.
 */
final class CampaignNotificationLink
{
    /** @return array{0: string, 1: array<string, string>} [route, actionParams] */
    public static function for(Campaign $campaign): array
    {
        $season = $campaign->season;

        if ($season !== null && $season->is_published) {
            return ['seasons.show', ['season' => $season->slug]];
        }

        return ['campaigns.show', ['campaign' => $campaign->slug]];
    }
}
