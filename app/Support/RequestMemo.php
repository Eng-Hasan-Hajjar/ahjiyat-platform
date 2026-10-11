<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * E24: ذاكرة قراءة **لطلب HTTP واحد** فقط، لتفادي تكرار قراءة نقيّة (عضوية الفريق، قائمة الحظر…) تستدعيها عدة مكوّنات بالصفحة نفسها
 * (التنقل، الشريط الجانبي، المتحكّم، القالب) فتخرج كل مرة باستعلام مطابق.
 *
 * ضماناتها (ولهذا هي آمنة للقراءات التي تقرّر عرضًا لا صلاحية كتابة):
 *  - تعمل **فقط** لطلب آمن (GET/HEAD) له مسار محلول؛ كل POST/PATCH/PUT/DELETE يقرأ القاعدة مباشرةً كما كان، فلا تُرى قراءة قديمة بعد كتابة بالطلب نفسه.
 *  - تُخزَّن في `$request->attributes`، فتنتهي بانتهاء الطلب (طلب جديد = ذاكرة جديدة، حتى في الاختبارات وبين طلبات Octane).
 *  - لا تعمل إلا **بين begin() وend()** اللذين يستدعيهما الوسيط RequestObservability حول معالجة الطلب. بعد انتهاء المعالجة (مثلًا مهام terminate، أو اختبار يستدعي خدمة مباشرةً بعد $this->get())
 *    يبقى app('request') مرتبطًا بآخر طلب GET ذي مسار؛ لولا هذا الحدّ لقُرئت عضوية/قائمة حظر قديمة من ذلك الطلب (وقد كشفه فعلًا TeamGloryTest).
 *  - خارج الطلب (الأوامر، الطوابير، المجدول، استدعاء خدمة مباشر بلا مسار) لا تحفظ شيئًا إطلاقًا.
 * ليست كاشًا مشتركًا: لا تعبر الطلبات ولا المستخدمين، فلا تسرّب بين حسابين. لا تُستعمل لقرارات التفويض الأمنية بحد ذاتها.
 */
final class RequestMemo
{
    private const BAG = '_request_memo';

    /** @var list<Request> مكدّس الطلبات الجاري معالجتها (الأخير هو النشط؛ مكدّس لا متغيّر مفرد كي لا يُعطِّل طلب متداخل الطلبَ الخارجي). */
    private static array $active = [];

    /** يفتح نافذة الذاكرة لهذا الطلب. يُستدعى من الوسيط قبل تمرير الطلب. */
    public static function begin(Request $request): void
    {
        self::$active[] = $request;
    }

    /** يغلق النافذة: بعدها تقرأ كل الاستدعاءات القاعدة مباشرةً. */
    public static function end(Request $request): void
    {
        if (self::$active !== [] && end(self::$active) === $request) {
            array_pop(self::$active);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public static function remember(string $key, Closure $resolve): mixed
    {
        $request = self::activeRequest();

        if ($request === null) {
            return $resolve();
        }

        $store = $request->attributes->get(self::BAG, []);

        if (array_key_exists($key, $store)) {
            return $store[$key];
        }

        $value = $resolve();
        // يُعاد قراءة الحقيبة بعد الحساب: قد يكون الحساب نفسه سجّل مفاتيح أخرى.
        $store = $request->attributes->get(self::BAG, []);
        $store[$key] = $value;
        $request->attributes->set(self::BAG, $store);

        return $value;
    }

    /** يمسح كل مفتاح يبدأ بالبادئة (لمن يكتب ثم يقرأ بعدها داخل طلب آمن نادر). */
    public static function forget(string $prefix): void
    {
        $request = self::activeRequest();

        if ($request === null) {
            return;
        }

        $store = array_filter(
            $request->attributes->get(self::BAG, []),
            fn (string $key) => ! str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        );

        $request->attributes->set(self::BAG, $store);
    }

    private static function activeRequest(): ?Request
    {
        $request = self::$active === [] ? null : end(self::$active);

        return $request !== null && $request->isMethodSafe() && $request->route() !== null ? $request : null;
    }
}
