<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Engagement\QuestPeriodService;
use App\Services\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

/**
 * سياسة التذكيرات: ميزانية يومية لكل مستخدم + قاعدة "لا تذكير مهام بعد تحذير سلسلة بنفس اليوم".
 * اليوم = نفس تعريف E13 (QuestPeriodService - المنطقة الزمنية للمنصة). استعلامات مجمَّعة لدفعة كاملة (لا N+1).
 *
 * الأولوية (streak_at_risk > daily_quests_available) تتحقق بثلاثة عناصر معًا:
 *  1) تقسيم الجمهور: من لديه سلسلة حيّة لا يدخل جمهور تذكير المهام أصلًا (ReEngagementService).
 *  2) الميزانية: ما استُهلك اليوم يُحتسَب لكل أنواع التذكير.
 *  3) القاعدة الصريحة أعلاه (حتى لو كانت الميزانية 2).
 */
class ReEngagementPolicy
{
    public function __construct(
        protected PlatformSettingsService $settings,
        protected QuestPeriodService $periods,
    ) {}

    public function maxPerDay(): int
    {
        return max(0, (int) $this->settings->get('notifications', 'max_reengagement_per_day', 1));
    }

    /** @return array{0: Carbon, 1: Carbon} حدود اليوم المحلي الحالي. */
    public function dayBounds(): array
    {
        $now = now()->copy()->setTimezone($this->periods->timezone());

        return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> عدد تذكيرات العودة المُنشأة اليوم لكل مستخدم.
     */
    public function usedToday(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        [$start, $end] = $this->dayBounds();

        return DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->whereIn('type_key', NotificationType::reEngagementKeys())
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('notifiable_id, count(*) as used')
            ->groupBy('notifiable_id')
            ->pluck('used', 'notifiable_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true> من أُرسل له تحذير سلسلة اليوم.
     */
    public function streakWarnedToday(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        [$start, $end] = $this->dayBounds();

        return DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->where('type_key', NotificationType::StreakAtRisk->value)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('notifiable_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
