<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\CaptureReferralIntent;
use App\Http\Middleware\EnsureAccountIsNotFrozen;
use App\Http\Middleware\EnsureSafetyAccess;
use App\Http\Middleware\RequestObservability;
use App\Http\Middleware\RestrictBroadcastChannels;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustApplicationHosts;
use App\Http\Middleware\TrackDeviceFingerprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // E24: جاهزية الموازن /ready خارج مجموعتَي web/api عمدًا (بلا جلسة ولا CSRF ولا تتبّع جهاز): فحص خفيف بلا آثار جانبية.
        then: function () {
            Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');

            // E32: webhook عام لمزوّدين إلكترونيين مستقبليين (مغلق افتراضيًا). مجموعة api = بلا جلسة/CSRF بطبيعتها، لا استثناء من أي وسيط.
            Route::middleware('api')->group(__DIR__.'/../routes/payments_webhooks.php');

            // E41: webhook التجارة الاستهلاكية (المشتريات الرقمية). مجموعة api = بلا جلسة/CSRF بطبيعتها؛ المصادقة بتوقيع المزوّد حصرًا (لا استثناء عام من CSRF).
            Route::middleware('api')->group(__DIR__.'/../routes/commerce_webhooks.php');
        },
    )
    // E21: قنوات البثّ الخاصة + مسار /broadcasting/auth (وسيط web، يتطلب مستخدمًا مصادَقًا)
    // E26: حدّ معدّل لمسار /broadcasting/auth (كل اشتراك قناة خاصة يكلّف استعلامًا) + قصر الأسماء على private-chat.* (يغلق presence-chat.* الذي كان يسرّب user_id الداخلي).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:broadcast-auth', RestrictBroadcastChannels::class]])
    ->withMiddleware(function (Middleware $middleware) {
        // E24: معرّف طلب + سجل الطلبات البطيئة + Server-Timing محليًا (عالميّ، فيشمل web وapi وكل الاستثناءات).
        $middleware->prepend(RequestObservability::class);

        // E26: تقييد ترويسة Host (يمنع تسميم روابط إعادة التعيين/التوثيق). صامت محليًا وبالاختبارات؛ انظر TrustApplicationHosts.
        $middleware->prepend(TrustApplicationHosts::class);

        $middleware->web(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
            // E33: يلتقط ?ref=CODE لزائر ضيف على GET فقط (لا أثر بلا ?ref). بعد StartSession حتمًا (ضمن مجموعة web).
            CaptureReferralIntent::class,
        ]);

        $middleware->api(append: [
            TrackDeviceFingerprint::class,
            SecurityHeaders::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'account.active' => EnsureAccountIsNotFrozen::class,
            // E38: صفحة «الأمان والإشراف» (مصادَق أو سياق وصول مقيَّد لحساب مجمَّد). لا يمنح أي وصول خارج /account/safety.
            'safety.access' => EnsureSafetyAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();