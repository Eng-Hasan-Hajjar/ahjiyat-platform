<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\PlatformSettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * نقطة الإنشاء الوحيدة لإشعار لاعب (in-app). مبادئ ثابتة:
 *  - الإشعار معلوماتي فقط: لا XP ولا عملة ولا تقدّم ولا Streak ولا أي مكافأة - الصنف لا يعتمد على أي خدمة لعب.
 *  - عزل الفشل: أي استثناء هنا (قاعدة بيانات، مفتاح، حمولة) يُلتقَط ويُرجِع Failed؛ لا يصل لمستدعٍ يبني فوق
 *    معاملة لعب أبدًا.
 *  - Idempotency دلالي: قيد فريد (notifiable + idempotency_key) - التكرار يُرجِع Duplicate لا خطأ.
 *  - الإلزامي (الأمان) يتجاوز المفتاح الشامل وتفضيل المستخدم؛ كل ما عداه يتطلب: الشامل + التفضيل.
 *  - التفضيلات والمفتاح الشامل تُقرَأ لحظة الإرسال نفسها (لا لقطة قديمة).
 *  - الوجهة (action_route) من سجل النوع حصرًا: المستدعي يستطيع فقط الاختيار من allowedRoutes() للنوع؛ أي قيمة خارجها تُتجاهَل
 *    فيُستعمل المسار الافتراضي - لا مسار اعتباطيًا أبدًا.
 */
class NotificationDispatcher
{
    public function __construct(
        protected PlatformSettingsService $settings,
        protected NotificationPreferenceService $preferences,
    ) {}

    /**
     * @param  array<string, mixed>  $params  معاملات نص الإشعار (مثل name/streak) - نصوص عادية، تُهرَّب عند العرض.
     * @param  array<string, scalar>  $refs  مراجع كيانات اختيارية (achievement_id ...) - لا يُعتمد عليها بلا فحص.
     * @param  array<string, scalar>  $actionParams  معاملات مسار الوجهة الداخلية (مثل slug الموسم): scalar فقط؛ المسار نفسه من
     *                                               سجل النوع حصرًا ويتحقق NotificationUrlResolver منه ومن المعاملات عند كل استعمال.
     * @param  string|null  $actionRoute  اختيار مسار من allowedRoutes() للنوع فقط (مثل seasons.show أو campaigns.show)؛ غيره يُتجاهَل.
     */
    public function dispatch(User $user, NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): DispatchResult
    {
        try {
            if (! $type->category()->isMandatory()) {
                if (! (bool) $this->settings->get('notifications', 'notifications_enabled', true)) {
                    return DispatchResult::Suppressed;
                }

                if (! $this->preferences->isEnabled($user, $type->category())) {
                    return DispatchResult::Suppressed;
                }
            }

            $data = [
                'type' => $type->value,
                'category' => $type->category()->value,
                'title' => Str::limit($type->title($params), 120, ''),
                'body' => Str::limit($type->body($params), 300, ''),
                'icon' => $type->icon(),
                'action_route' => ($actionRoute !== null && in_array($actionRoute, $type->allowedRoutes(), true)) ? $actionRoute : $type->defaultRoute(),
                'action_params' => array_filter($actionParams, 'is_scalar'),
                'idempotency_key' => $idempotencyKey,
                'refs' => $refs,
            ];

            // savepoint: انتهاك القيد الفريد لا يُفسد معاملة خارجية (PostgreSQL) إن وُجدت.
            DB::transaction(fn () => $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $type->value,
                'data' => $data,
                'type_key' => $type->value,
                'category' => $type->category()->value,
                'idempotency_key' => $idempotencyKey,
            ]));

            return DispatchResult::Created;
        } catch (UniqueConstraintViolationException) {
            return DispatchResult::Duplicate;
        } catch (Throwable $e) {
            report($e);

            return DispatchResult::Failed;
        }
    }
}
