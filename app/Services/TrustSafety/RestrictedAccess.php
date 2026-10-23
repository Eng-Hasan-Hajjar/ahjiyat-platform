<?php

namespace App\Services\TrustSafety;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * E38 - سياق وصول **مقيَّد** لصاحب حساب مجمَّد إلى صفحة «الأمان والإشراف» (قراءة الإجراء + الاعتراض) فقط.
 *
 * لماذا ليس Auth::login: تسجيل المجمَّد دخولًا عاديًا يفتح كل مسارات الحماية التي تعتمد على auth (ومنها ما لا يمرّ بـaccount.active). لذلك يبقى حارس web
 * **غير مصادَق** تمامًا (E26: حساب مجمَّد لا يترك جلسة مصادَقة)، ويُسجَّل فقط مفتاح جلسة صغير بعد نجاح بيانات الدخول:
 *   {user_id, until}  ← يُكتب في الجلسة **الجديدة** بعد إبطال القديمة (لا تثبيت جلسة)، بمدة قصيرة (config trust_safety.restricted_access.ttl_minutes)،
 *   ويُعاد فحصه كل طلب: المستخدم ما زال موجودًا ومجمَّدًا. لا يمنح أي صلاحية خارج مسارات /account/safety (التي وحدها تقرؤه).
 */
final class RestrictedAccess
{
    public const KEY = 'trust_safety.restricted';

    public static function grant(Session $session, User $user): void
    {
        $session->put(self::KEY, [
            'user_id' => (int) $user->id,
            'until' => now()->addMinutes(max(1, (int) config('trust_safety.restricted_access.ttl_minutes')))->getTimestamp(),
        ]);
    }

    /** المستخدم المجمَّد صاحب السياق الصالح، أو null. يمسح السياق المنتهي أو غير الصالح. */
    public static function user(Session $session): ?User
    {
        $context = $session->get(self::KEY);

        if (! is_array($context) || ! isset($context['user_id'], $context['until'])) {
            return null;
        }

        if ((int) $context['until'] < now()->getTimestamp()) {
            $session->forget(self::KEY);

            return null;
        }

        $user = User::query()->find((int) $context['user_id']);

        // رُفع التجميد (أو حُذف الحساب): السياق انتهى؛ يدخل المستخدم بالطريق العادي.
        if ($user === null || ! $user->is_frozen) {
            $session->forget(self::KEY);

            return null;
        }

        return $user;
    }

    public static function revoke(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
