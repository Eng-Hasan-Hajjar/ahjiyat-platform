<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizationSafetyService;
use App\Services\Teams\TeamService;
use App\Support\Security\Fingerprint;
use Illuminate\Cache\RateLimiting\Limit;
use App\Support\ReleaseInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // E27: محرك البحث. السائق غير المعروف يسقط إلى database (لا يعطّل الموقع). محرك خارجي مستقبلي = تنفيذ جديد لـSearchBackend وفرع هنا.
        $this->app->singleton(\App\Services\Search\Contracts\SearchBackend::class, fn ($app) => match ((string) config('search.driver', 'database')) {
            default => $app->make(\App\Services\Search\DatabaseSearchBackend::class),
        });

        // E32: سجل مزوّدي الدفع نسخة واحدة (يسمح بحقن مزوّد بالاختبارات/المستقبل دون المساس بالمجال المالي).
        $this->app->singleton(\App\Services\Payments\PaymentProviderRegistry::class);

        // E39: حدّ الصرف الصادر للمبدعين. اليوم يدوي فقط؛ مزوّد آلي مستقبلي = تنفيذ جديد للواجهة وفرع هنا (منفصل عن مزوّدي الدفع الواردين).
        $this->app->bind(\App\Services\CreatorFinance\CreatorPayoutProvider::class, fn ($app) => match ((string) config('creator_monetization.payout.provider', 'manual')) {
            default => $app->make(\App\Services\CreatorFinance\ManualCreatorPayoutProvider::class),
        });

        // E41: سجل مزوّدي دفع المشتريات الرقمية (E40 كان يربط مزوّدًا واحدًا بالحاوية؛ لم يعد). المزوّدون من config/commerce.php، ويُحلّون بالمفتاح؛ مزوّد الشراء يُجمَّد وقت إنشائه.
        // منفصل عن PaymentProviderRegistry الخاص بالرعاية (E32). مزوّد حيّ مستقبلي = صنف ينفّذ CommercePaymentProvider + سطر في config/commerce.php دون المساس بـDigitalPurchase.
        $this->app->singleton(\App\Services\Commerce\CommercePaymentProviderRegistry::class);
        $this->app->singleton(\App\Services\Commerce\CommerceProductRegistry::class);

        // E31: أسماء الفهارس/المفاتيح المولَّدة تلقائيًا تُقتطع إلى 64 محرفًا على MySQL/MariaDB فقط (SQLite بلا تغيير). انظر MysqlSafeBlueprint.
        $this->app->bind(\Illuminate\Database\Schema\Blueprint::class, \App\Database\MysqlSafeBlueprint::class);
    }

    public function boot(): void
    {
        // E22: قائمة المحادثات الجانبية لصفحة الغرفة (قراءة فقط، بلا إنشاء غرف عند العرض).
        \Illuminate\Support\Facades\View::composer('chat.room', fn ($view) => $view->with('sidebar', app(\App\Services\Chat\ChatSidebar::class)->for(auth()->user())));

        // E19-E20: قبل حذف أي حساب يُعالَج ما يملكه من فرق (نقل الملكية لأقدم مشرف/عضو، وإلا أرشفة): لا فريق يتيم بلا مالك.
        // فشل المعالجة لا يمنع الحذف (owner_id يصير NULL بقيد القاعدة والفريق يبقى قابلًا لتدخل الإدارة).
        User::deleting(function (User $user) {
            try {
                app(TeamService::class)->releaseOwnershipFor($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E36: قبل E29 (ترتيب التسجيل = ترتيب التنفيذ): إعجابات المستخدم تُنقَص عدّاداتها، وتعليقاته تصير لحودًا محذوفة، ونقاشات مجموعاته العامة تُحذف قبل أن تحذفها E29.
        // فشل التنظيف لا يمنع الحذف؛ discussions:reconcile يكشف أي يتيم أو انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Discussions\DiscussionCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // E29: قبل حذف أي حساب تُنقَص عدّادات ما تفاعل معه (مفضلة/متابعات) فتبقى المجمَّعات دقيقة؛ الصفوف نفسها تتتابع بقيد القاعدة، والتقييمات تبقى مجهولة الهوية.
        // فشل التنظيف لا يمنع الحذف؛ community:reconcile-engagement يصلح أي انحراف.
        User::deleting(function (User $user) {
            try {
                app(\App\Services\Engagement\EngagementCleanup::class)->purgeUser($user);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Super Admin يتجاوز كل فحص can()/authorize() في التطبيق بالكامل -
        // Server-side حصراً. مصدر الفحص الوحيد AuthorizationSafetyService.
        Gate::before(function ($user, string $ability) {
            return app(AuthorizationSafetyService::class)->isSuperAdmin($user) ? true : null;
        });

        $this->registerRateLimiters();
        $this->registerOperationalGuards();
    }

    /**
     * E25: حواجز التشغيل بالإنتاج.
     *  - الأوامر الهدّامة (db:wipe, migrate:fresh|refresh|reset|rollback) ممنوعة بالإنتاج: الترحيلات بالإنتاج تتقدّم للأمام فقط (forward-fix) والتراجع عن القاعدة غير آمن بعد كتابات الإصدار الجديد.
     *    استثناء واعٍ ومؤقت بمتغير بيئة لتلك العملية وحدها: OPS_ALLOW_DESTRUCTIVE_COMMANDS=true (انظر docs/release-engineering.md). لا يُضاف إلى .env الدائم.
     *  - معرّف الإصدار (إن ضُبط) يُضاف لسياق كل سطر سجل (release) بجانب request_id من E24، لربط أي خطأ بالإصدار الذي أنتجه. لا أسرار فيه.
     */
    protected function registerOperationalGuards(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() && ! config('operations.allow_destructive_commands', false));

        if (($release = ReleaseInfo::short()) !== null) {
            Log::shareContext(['release' => $release]);
        }
    }

    /**
     * E8: بنية Rate Limiting مركزية واحدة - بدل أرقام مبعثرة (throttle:20,1)
     * بكل Route مباشرة. المفتاح: user ID للمصادَق عليهم (بند 26 - نفس سلوك
     * Laravel الافتراضي أصلاً بمجموعات auth/verified)، أو IP للضيوف حصراً
     * (تسجيل/استرجاع كلمة سر - لا يوجد مستخدم مصادَق عليه بعد أصلاً).
     */
    protected function registerRateLimiters(): void
    {
        // E8: لم تكن محمية إطلاقاً سابقاً (بند 21) - تسجيل حساب جديد.
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
        });

        // E8: لم تكن محمية إطلاقاً سابقاً (بند 22) - منع email flooding.
        // E26: حدّ ثانٍ **لكل مستلم** (بريد مُجزَّأ، لا بريد خام بمفتاح المحدِّد) كي لا يُغرِق مهاجمٌ بعناوين IP متعددة صندوق ضحية واحدة.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinutes(10, 5)->by('pr-ip:'.$request->ip()),
                Limit::perHour(3)->by('pr-rcpt:'.Fingerprint::email($request->input('email'))),
            ];
        });

        // E26: تنفيذ إعادة التعيين نفسه (POST /reset-password) لم يكن محدودًا. الرمز عشوائي طويل، لكن لا سبب لقبول طرق غير محدودة.
        RateLimiter::for('password-reset-submit', function (Request $request) {
            return Limit::perMinutes(10, 10)->by('prs-ip:'.$request->ip());
        });

        // E26: تأكيد كلمة المرور (confirm-password) كان قابلًا للتخمين بلا حدّ داخل جلسة مسروقة. المفتاح المستخدم.
        RateLimiter::for('password-confirm', function (Request $request) {
            return Limit::perMinute(6)->by('pc:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: تعديل بيانات الحساب الحسّاسة (الاسم/البريد/ظهور الملف) - أدنى بكثير من أي استخدام بشري.
        RateLimiter::for('account-sensitive', function (Request $request) {
            return Limit::perMinute(10)->by('acs:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: أفعال لعب خفيفة كانت بلا حدّ (شراء تلميح، انضمام تحدٍّ، إكمال/تأمل خطوة حملة).
        RateLimiter::for('game-actions', function (Request $request) {
            return Limit::perMinute(30)->by('ga:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('puzzle-hint', function (Request $request) {
            return Limit::perMinute(10)->by('ph:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: مصادقة قنوات البثّ الخاصة (/broadcasting/auth): اشتراك كل غرفة مرة واحدة عادةً؛ السقف يمنع استنزاف القاعدة باستعلامات التخويل.
        RateLimiter::for('broadcast-auth', function (Request $request) {
            return Limit::perMinute(90)->by('bca:'.($request->user()?->id ?: $request->ip()));
        });

        // E26: دخول API (إصدار رمز Sanctum) - حدّ لكل IP وحدّ لكل (بريد مُجزَّأ+IP). لا يتشارك مع محدّد دخول الويب.
        RateLimiter::for('api-login', function (Request $request) {
            return [
                Limit::perMinute(20)->by('apil-ip:'.$request->ip()),
                Limit::perMinutes(5, 5)->by('apil-acct:'.Fingerprint::email($request->input('email')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('email-verification', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        // أفعال اللعب (بند 24) - نفس الأرقام الحالية بالضبط، فقط مُسمّاة
        // ومركزية الآن. لا نخفّضها - قد تكسر لعبًا طبيعيًا سريعًا.
        RateLimiter::for('puzzle-attempt', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-start', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('game-session-reveal', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E14 (بند 606): تحويل نقر الإعلان - عام (قد يشمل زائرًا غير مسجَّل)، معدَّل معقول يمنع إساءة الاستخدام بلا إزعاج تصفُّح عادي.
        RateLimiter::for('ad-click', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // طلبات الاستبدال (بند 25) - نفس الحد الحالي: 5 كل ساعة.
        RateLimiter::for('redemption', function (Request $request) {
            return Limit::perMinutes(60, 5)->by($request->user()?->id ?: $request->ip());
        });

                // E10: مشتريات المتجر الافتراضي - مفتاح المستخدم (لا IP، دائماً
        // مُصادَق عليه بهذه المرحلة).
        RateLimiter::for('store-purchase', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        // E16: الأصدقاء. إرسال الطلبات بالدقيقة وبالساعة (مع cooldown إعادة الإرسال داخل FriendshipService)؛ بقية الإجراءات
        // والبحث أوسع. المفتاح المستخدم المصادَق (لا IP). لا يمنع الاستخدام الطبيعي.
        RateLimiter::for('friend-requests', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(10)->by("friend-req-min:{$key}"), Limit::perHour(60)->by("friend-req-hour:{$key}")];
        });

        RateLimiter::for('friend-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // E17: إنشاء تحدٍّ (حد ضيق: يولّد إشعارًا لشخص آخر)، تسجيل المنافسات، ولعب/إرسال نتيجة، وبقية إجراءات التحديات.
        RateLimiter::for('friend-challenges', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return [Limit::perMinute(5)->by("fc-min:{$key}"), Limit::perHour(20)->by("fc-hour:{$key}")];
        });

        RateLimiter::for('competitive-register', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // E19: الفرق. إنشاء نادر؛ الدعوات/الطلبات تحت حد يمنع الإزعاج؛ إجراءات الإدارة والقبول معتدلة.
        RateLimiter::for('team-create', fn (Request $request) => [Limit::perMinute(2)->by('tc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('tch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-invite', fn (Request $request) => [Limit::perMinute(10)->by('ti:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('tih:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-join', fn (Request $request) => [Limit::perMinute(8)->by('tj:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('tjh:'.($request->user()?->id ?: $request->ip()))]);
        // E20: تحدّيات الفرق (الإنشاء نادر نسبيًا؛ القبول/الرفض/الإلغاء/الروستر معتدلة؛ اللعب بحد competitive-play القائم).
        RateLimiter::for('team-challenge-create', fn (Request $request) => [Limit::perMinute(4)->by('tcc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('tcch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('team-challenge-actions', fn (Request $request) => Limit::perMinute(30)->by('tca:'.($request->user()?->id ?: $request->ip())));
        // E21: دردشة. الإرسال حسب النوع (العامة أشد + حماية دفعات)، والبلاغ والتعديل/الحذف والقراءة بحدود منفصلة. المفتاح: المستخدم لا IP.
        RateLimiter::for('chat-dm-send', fn (Request $request) => Limit::perMinute(30)->by('cdm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-team-send', fn (Request $request) => Limit::perMinute(30)->by('ctm:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-global-send', fn (Request $request) => [
            Limit::perMinute(10)->by('cgm:'.($request->user()?->id ?: $request->ip())),
            Limit::perSecond(3, 10)->by('cgb:'.($request->user()?->id ?: $request->ip())),     // دفعة: 3 رسائل/10 ثوانٍ
        ]);
        RateLimiter::for('chat-report', fn (Request $request) => [Limit::perMinute(5)->by('crm:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('crh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('chat-edit', fn (Request $request) => Limit::perMinute(30)->by('ced:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-moderate', fn (Request $request) => Limit::perMinute(30)->by('cmo:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(120)->by('crd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('team-actions', fn (Request $request) => Limit::perMinute(40)->by('ta:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('competitive-play', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('player-search', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
                // E27: البحث العام. الاقتراحات الفورية أوسع (تُطلَق أثناء الكتابة بعد تأخير 220ms) لكن الضيف أضيق بكثير من العضو (مفتاح IP) كي لا يُستعمل مسحًا لأسماء اللاعبين.
        // صفحة البحث الكاملة أضيق من الاقتراحات. الاكتشاف (القراءة فقط) بحدّ واسع. المفتاح المستخدم المصادَق لا IP (يمنع التفاف مفتاح IP المشترك).
        RateLimiter::for('global-search', fn (Request $request) => $request->user()
            ? Limit::perMinute(60)->by('gs:u:'.$request->user()->id)
            : Limit::perMinute(20)->by('gs:ip:'.$request->ip()));
        RateLimiter::for('search-suggest', fn (Request $request) => $request->user()
            ? [Limit::perMinute(120)->by('ss:u:'.$request->user()->id), Limit::perSecond(16, 2)->by('ssb:u:'.$request->user()->id)]
            : [Limit::perMinute(40)->by('ss:ip:'.$request->ip()), Limit::perSecond(10, 2)->by('ssb:ip:'.$request->ip())]);
        RateLimiter::for('discover', fn (Request $request) => Limit::perMinute(90)->by('dc:'.($request->user()?->id ?: $request->ip())));

        // E28: المبدعون. كلها بمفتاح المستخدم لا IP. الطلب/الإنشاء/الإرسال (ويشمل السحب وإنشاء نسخة جديدة كي لا يُغرَق الإشراف بدورات إرسال/إلغاء) والبلاغات بحدّين دقيقة/ساعة.
        RateLimiter::for('creator-apply', fn (Request $request) => [Limit::perMinute(2)->by('cap:'.($request->user()?->id ?: $request->ip())), Limit::perHour(5)->by('caph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-draft-create', fn (Request $request) => [Limit::perMinute(5)->by('cdc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(30)->by('cdch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-submit', fn (Request $request) => [Limit::perMinute(5)->by('csb:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('csbh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-report', fn (Request $request) => [Limit::perMinute(5)->by('cpr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cprh:'.($request->user()?->id ?: $request->ip()))]);

        // E29: التفاعل المجتمعي. بمفتاح المستخدم لا IP (كلها خلف auth). حدّان: دقيقة (سرعة بشرية) + ساعة (سقف إغراق/نمط آلي).
        // التقييم/الحفظ يسمحان بتغيير الرأي والتنقل بين أعمال كثيرة، فالحدّ أوسع من المتابعة والمجموعات (أثرهما أثقل على بيانات الآخرين).
        RateLimiter::for('community-rating', fn (Request $request) => [Limit::perMinute(20)->by('crt:'.($request->user()?->id ?: $request->ip())), Limit::perHour(200)->by('crth:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('community-favorite', fn (Request $request) => [Limit::perMinute(30)->by('cfv:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('cfvh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-follow', fn (Request $request) => [Limit::perMinute(15)->by('cfl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(100)->by('cflh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-actions', fn (Request $request) => [Limit::perMinute(30)->by('cca:'.($request->user()?->id ?: $request->ip())), Limit::perHour(240)->by('ccah:'.($request->user()?->id ?: $request->ip()))]);

        // E35: المجموعات العامة. كلها بمفتاح المستخدم (لا IP) عدا القراءة العامة للضيوف. الحدود من config/collections.php (limits.*). الحفظ/النشر/البلاغ بحدّ ساعة إضافي ضد الإغراق.
        RateLimiter::for('collection-publish', fn (Request $request) => [Limit::perMinute(max(1, (int) config('collections.limits.publish_per_minute')))->by('ccp:'.($request->user()?->id ?: $request->ip())), Limit::perHour(60)->by('ccph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-save', fn (Request $request) => [Limit::perMinute(max(1, (int) config('collections.limits.save_per_minute')))->by('ccs:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('ccsh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-report', fn (Request $request) => [Limit::perMinute(max(1, (int) config('collections.limits.report_per_minute')))->by('ccr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('ccrh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('collection-reorder', fn (Request $request) => Limit::perMinute(max(1, (int) config('collections.limits.reorder_per_minute')))->by('cco:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('collection-read', fn (Request $request) => Limit::perMinute(max(1, (int) config('collections.limits.read_per_minute')))->by('ccv:'.($request->user()?->id ?: $request->ip())));

        // E36: النقاشات. كل المحدّدات بمفتاح المستخدم (لا IP مشترك خلف NAT)؛ الكتابة بحدّ دقيقة + ساعة، والقراءة (صفحة ردود/رابط ثابت) بحدّ دقيقة.
        RateLimiter::for('discussion-comment', fn (Request $request) => [Limit::perMinute(max(1, (int) config('discussions.limits.comment_per_minute')))->by('dsc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(max(1, (int) config('discussions.limits.comment_per_hour')))->by('dsch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('discussion-reply', fn (Request $request) => [Limit::perMinute(max(1, (int) config('discussions.limits.reply_per_minute')))->by('dsr:'.($request->user()?->id ?: $request->ip())), Limit::perHour(max(1, (int) config('discussions.limits.reply_per_hour')))->by('dsrh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('discussion-edit', fn (Request $request) => Limit::perMinute(max(1, (int) config('discussions.limits.edit_per_minute')))->by('dse:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('discussion-like', fn (Request $request) => [Limit::perMinute(max(1, (int) config('discussions.limits.like_per_minute')))->by('dsl:'.($request->user()?->id ?: $request->ip())), Limit::perHour(max(1, (int) config('discussions.limits.like_per_hour')))->by('dslh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('discussion-report', fn (Request $request) => [Limit::perMinute(max(1, (int) config('discussions.limits.report_per_minute')))->by('dsp:'.($request->user()?->id ?: $request->ip())), Limit::perHour(max(1, (int) config('discussions.limits.report_per_hour')))->by('dsph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('discussion-read', fn (Request $request) => Limit::perMinute(max(1, (int) config('discussions.limits.read_per_minute')))->by('dsv:'.($request->user()?->id ?: $request->ip())));

        // E37: قراءة صفحة ترتيبات الجودة العامة (قراءة فقط من لقطات مشتقّة مع إعادة فحص حيّ؛ لا إجراء كتابة علني أبدًا).
        RateLimiter::for('reputation-read', fn (Request $request) => Limit::perMinute(90)->by('rpr:'.($request->user()?->id ?: $request->ip())));

        // E38: صفحة «الأمان والإشراف» (قراءة) وتقديم/سحب الاعتراض (أضيقها: اعتراض بالدقيقة والساعة). مفتاح المستخدم المعني (يشمل سياق الوصول المقيَّد للمجمَّد) وإلا IP.
        RateLimiter::for('safety-read', fn (Request $request) => Limit::perMinute(60)->by('sfr:'.($request->attributes->get('safety_user')?->id ?: $request->ip())));
        RateLimiter::for('safety-appeal', fn (Request $request) => [
            Limit::perMinute(3)->by('sfa:'.($request->attributes->get('safety_user')?->id ?: $request->ip())),
            Limit::perHour(10)->by('sfah:'.($request->attributes->get('safety_user')?->id ?: $request->ip())),
        ]);

        // E39: طلب الدفعة وإلغاؤه (إجراءان ماليان للمبدع) بمفتاح المستخدم: حدّ دقيقة + ساعة (يمنع النقر المتكرر والإغراق). قراءة صفحة الأرباح تستعمل حدّ قراءة المبدع القائم.
        RateLimiter::for('creator-payout-request', fn (Request $request) => [Limit::perMinute(3)->by('cpq:'.($request->user()?->id ?: $request->ip())), Limit::perHour(10)->by('cpqh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-payout-cancel', fn (Request $request) => [Limit::perMinute(5)->by('cpc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('cpch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('creator-earnings-read', fn (Request $request) => Limit::perMinute(60)->by('cer:'.($request->user()?->id ?: $request->ip())));

        // E30: قراءات الرعاة والسلاسل العامة (صفحات عرض فقط) بحدّ معتدل لكل مستخدم/عنوان؛ إجراءات استوديو السلاسل تُعيد استعمال محدّدات المبدعين (creator-draft-create / creator-submit).
        RateLimiter::for('supporters-read', fn (Request $request) => Limit::perMinute(120)->by('spr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('series-read', fn (Request $request) => Limit::perMinute(120)->by('ser:'.($request->user()?->id ?: $request->ip())));

        // E32: دفع الرعاية. قراءة الصفحات العامة، إنشاء الطلب، إرسال/إلغاء التحويل، رفع الإثبات (أضيقها)، متابعة حالة الطلب، وWebhook المستقبلي بحدّ لكل عنوان.
        RateLimiter::for('support-read', fn (Request $request) => Limit::perMinute(120)->by('sur:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('support-checkout', fn (Request $request) => [Limit::perMinute(10)->by('suc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('such:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('support-submit', fn (Request $request) => [Limit::perMinute(10)->by('sus:'.($request->user()?->id ?: $request->ip())), Limit::perHour(60)->by('sush:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('support-proof-upload', fn (Request $request) => [Limit::perMinute(5)->by('sup:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('suph:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(60)->by('pst:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)->by('pwh:'.$request->ip()));

        // E33: الإحالات. الإعادة التوجيه العامة /r/{code} (لكل IP)، لوحة اللاعب (لكل مستخدم)، عدّاد المشاركة، وإجراءات المشرف على الحملات/المراجعة (تُطبَّق داخل الخدمات).
        RateLimiter::for('referral-redirect', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.redirect_per_minute')))->by('rfr:'.$request->ip()));
        RateLimiter::for('referral-dashboard', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.dashboard_per_minute')))->by('rfd:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('referral-share', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.share_per_minute')))->by('rfs:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('referral-campaign-action', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.campaign_action_per_minute')))->by('rfc:'.($request->user()?->id ?: $request->ip())));

        // E34: مركز المهام (لكل مستخدم) وتقويم الأحداث العام (لكل مستخدم/عنوان). قراءة فقط؛ لا إجراء يغيّر حالة عبر GET.
        RateLimiter::for('missions-read', fn (Request $request) => Limit::perMinute(120)->by('msr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('events-read', fn (Request $request) => Limit::perMinute(120)->by('evr:'.($request->user()?->id ?: $request->ip())));

        // E40: مسار الأحجيات. قراءة الصفحات، استلام مكافأة/الكل، إنشاء طلب المميز، تقديم التحويل، رفع الإثبات (أضيقها). مفتاح الحد = المستخدم المصادَق.
        RateLimiter::for('pass-read', fn (Request $request) => Limit::perMinute(120)->by('psr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('pass-collect', fn (Request $request) => [Limit::perMinute(30)->by('psc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(300)->by('psch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('pass-order', fn (Request $request) => [Limit::perMinute(10)->by('pso:'.($request->user()?->id ?: $request->ip())), Limit::perHour(40)->by('psoh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('pass-submit', fn (Request $request) => [Limit::perMinute(10)->by('pss:'.($request->user()?->id ?: $request->ip())), Limit::perHour(60)->by('pssh:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('pass-proof', fn (Request $request) => [Limit::perMinute(5)->by('psp:'.($request->user()?->id ?: $request->ip())), Limit::perHour(20)->by('psph:'.($request->user()?->id ?: $request->ip()))]);

        // E41: التجارة. بدء الدفع المستضاف (يستدعي المزوّد: الأضيق)، استطلاع حالة الشراء (قراءة فقط بفاصل أدنى)، السجل/الإيصال (قراءة)، وwebhook المزوّد (بالـIP+المزوّد؛ سقف سخي كي لا نحجب مزوّدًا شرعيًا).
        RateLimiter::for('commerce-checkout', fn (Request $request) => [Limit::perMinute(max(1, (int) config('commerce.limits.checkout_per_minute', 6)))->by('cmc:'.($request->user()?->id ?: $request->ip())), Limit::perHour(max(1, (int) config('commerce.limits.checkout_per_hour', 30)))->by('cmch:'.($request->user()?->id ?: $request->ip()))]);
        RateLimiter::for('commerce-status', fn (Request $request) => Limit::perMinute(max(1, (int) config('commerce.status.rate_per_minute', 20)))->by('cms:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('commerce-read', fn (Request $request) => Limit::perMinute(60)->by('cmr:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('commerce-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('commerce.webhooks.rate_per_minute', 600)))->by('cmw:'.$request->ip().':'.(string) $request->route('provider')));

        // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
        RateLimiter::for('cosmetic-equip', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });
        
        // E12: فجوة سابقة على هذه المرحلة - مجموعة Middleware الافتراضية 'api'
        // (المُطبَّقة تلقائيًا على كل مسار بـroutes/api.php) تتضمَّن throttle:api،
        // ولم يكن هذا الاسم مُعرَّفًا إطلاقًا (لم يُختَبر أي مسار API قبل الآن).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }

}