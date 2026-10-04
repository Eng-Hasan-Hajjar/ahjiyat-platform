<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Route;

/**
 * يحوّل بيانات إشعار مخزَّنة إلى رابط **داخلي** آمن أو null. لا يثق بأي رابط مخزَّن: يقبل فقط اسم مسار
 * ضمن allowedRoutes() الخاصة بنوع الإشعار (سجل الكود)، وبمعاملات scalar فقط، ويُنتج مسارًا نسبيًا
 * (لا نطاق خارجي) - فلا Open Redirect ولا CTA خارجي مهما كانت حمولة قاعدة البيانات.
 */
class NotificationUrlResolver
{
    public function resolve(array $data): ?string
    {
        $type = NotificationType::tryFrom((string) ($data['type'] ?? ''));
        $route = $data['action_route'] ?? null;

        if ($type === null || ! is_string($route) || ! in_array($route, $type->allowedRoutes(), true) || ! Route::has($route)) {
            return null;
        }

        $params = $data['action_params'] ?? [];

        if (! is_array($params)) {
            return null;
        }

        foreach ($params as $value) {
            if (! is_scalar($value)) {
                return null;
            }
        }

        try {
            return route($route, $params, false);
        } catch (\Throwable) {
            return null; // معاملات ناقصة/كيان محذوف: الصفحة سليمة والزر مخفي.
        }
    }
}
