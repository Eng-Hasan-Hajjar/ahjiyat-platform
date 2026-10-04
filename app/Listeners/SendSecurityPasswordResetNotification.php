<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use Illuminate\Auth\Events\PasswordReset;

/**
 * حدث موجود أصلًا (يُطلَقه NewPasswordController). إشعار أمان إلزامي. المفتاح الدلالي يشتق من بصمة HMAC
 * لهاش كلمة المرور الجديدة: إعادة معالجة نفس الحدث = نفس المفتاح (لا تكرار)، وإعادة تعيين لاحقة بكلمة
 * جديدة = مفتاح جديد. البصمة غير قابلة للعكس ولا تُخزَّن أي كلمة/هاش بالإشعار نفسه.
 */
class SendSecurityPasswordResetNotification
{
    public function handle(PasswordReset $event): void
    {
        try {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            $fingerprint = substr(hash_hmac('sha256', (string) $user->password, (string) config('app.key')), 0, 16);

            app(NotificationDispatcher::class)->dispatch(
                $user,
                NotificationType::SecurityPasswordReset,
                [],
                "security-password-reset:{$user->id}:{$fingerprint}",
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
