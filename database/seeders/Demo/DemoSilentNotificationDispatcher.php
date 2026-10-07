<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Services\Notifications\DispatchResult;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;

/**
 * موزّع إشعارات صامت يُستعمل **فقط** أثناء بناء التاريخ التجريبي (مباريات وأحداث ومكافآت قديمة): الأحداث والمستمعون الحقيقيون تعمل بكامل ثوابتها، لكن لا تتولد عشرات
 * الإشعارات. الإشعارات المعروضة للمستخدمين تُنشأ لاحقًا بمجموعة منتقاة (DemoNotificationSeeder) عبر الموزّع الحقيقي. لا يلمس أي جدول.
 */
class DemoSilentNotificationDispatcher extends NotificationDispatcher
{
    public function __construct() {}

    public function dispatch(User $user, NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): DispatchResult
    {
        return DispatchResult::Suppressed;
    }
}
