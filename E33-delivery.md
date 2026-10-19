# E33 — حزمة التسليم (Referral & Growth Engine)

> لا أوامر git من جهتي. GitHub قراءة فقط. كل ما يلي يُنسخ يدويًا إلى `C:\Users\engya\Desktop\puzzles\puzzle-gems-platform`.
> الأساس (Baseline): `ec653ca` (v32). هجرات جديدة: 3 فقط. حزم جديدة: 0.

## A) الملفات الجديدة (54) — المحتوى الكامل

المحتوى الكامل لكل ملف في `E33-new-files-bundle.md` وفي مجلد `files/` داخل الـ zip. القائمة (المسار — عدد الأسطر):

- `app/Console/Commands/ProcessReferrals.php` — 54
- `app/Console/Commands/ReconcileReferrals.php` — 107
- `app/Filament/Pages/ReferralAnalyticsPage.php` — 47
- `app/Filament/Resources/ReferralCampaignResource.php` — 133
- `app/Filament/Resources/ReferralCampaignResource/Pages/CreateReferralCampaign.php` — 27
- `app/Filament/Resources/ReferralCampaignResource/Pages/EditReferralCampaign.php` — 27
- `app/Filament/Resources/ReferralCampaignResource/Pages/ListReferralCampaigns.php` — 17
- `app/Filament/Resources/UserReferralResource.php` — 122
- `app/Filament/Resources/UserReferralResource/Pages/ListUserReferrals.php` — 11
- `app/Http/Controllers/Referrals/ReferralController.php` — 73
- `app/Http/Controllers/Referrals/ReferralLandingController.php` — 35
- `app/Http/Middleware/CaptureReferralIntent.php` — 32
- `app/Models/ReferralCampaign.php` — 121
- `app/Models/ReferralCode.php` — 36
- `app/Models/UserReferral.php` — 122
- `app/Policies/ReferralCampaignPolicy.php` — 34
- `app/Policies/UserReferralPolicy.php` — 34
- `app/Services/Referrals/ReferralAnalytics.php` — 147
- `app/Services/Referrals/ReferralAttributionService.php` — 104
- `app/Services/Referrals/ReferralCampaignService.php` — 276
- `app/Services/Referrals/ReferralClickRecorder.php` — 33
- `app/Services/Referrals/ReferralCodeService.php` — 168
- `app/Services/Referrals/ReferralException.php` — 12
- `app/Services/Referrals/ReferralIntent.php` — 117
- `app/Services/Referrals/ReferralNotifier.php` — 57
- `app/Services/Referrals/ReferralQualificationService.php` — 174
- `app/Services/Referrals/ReferralReviewService.php` — 121
- `app/Services/Referrals/ReferralRewardService.php` — 166
- `app/Services/Referrals/ReferralRiskService.php` — 98
- `app/Services/Referrals/ReferralShareStats.php` — 53
- `app/Services/Referrals/ShareLinkBuilder.php` — 74
- `config/referrals.php` — 86
- `database/migrations/2026_10_26_000001_create_referral_campaigns_table.php` — 56
- `database/migrations/2026_10_26_000002_create_referral_codes_table.php` — 37
- `database/migrations/2026_10_26_000003_create_user_referrals_table.php` — 77
- `database/seeders/DemoE33Seeder.php` — 127
- `docs/referrals-growth-e33.md` — 146
- `resources/views/components/share-button.blade.php` — 75
- `resources/views/filament/pages/referral-analytics.blade.php` — 58
- `resources/views/referrals/index.blade.php` — 73
- `routes/referrals.php` — 20
- `tests/Feature/ReferralE33/E33AnalyticsTest.php` — 213
- `tests/Feature/ReferralE33/E33CampaignTest.php` — 200
- `tests/Feature/ReferralE33/E33CodeTest.php` — 112
- `tests/Feature/ReferralE33/E33FraudTest.php` — 177
- `tests/Feature/ReferralE33/E33MysqlSchemaTest.php` — 235
- `tests/Feature/ReferralE33/E33PerformanceTest.php` — 245
- `tests/Feature/ReferralE33/E33PrivacyTest.php` — 181
- `tests/Feature/ReferralE33/E33QualificationTest.php` — 149
- `tests/Feature/ReferralE33/E33RedirectTest.php` — 104
- `tests/Feature/ReferralE33/E33RegistrationTest.php` — 127
- `tests/Feature/ReferralE33/E33RewardsTest.php` — 279
- `tests/Feature/ReferralE33/E33SharingTest.php` — 211
- `tests/Feature/ReferralE33/_support.php` — 186

## B) الملفات المعدَّلة (24) — Patch دقيق

كل التعديلات إضافية (additive). النسخ الكاملة للملفات الـ24 المعدَّلة في `files/` أيضًا (استبدال مباشر آمن إن لم تكن عدّلتها محليًا بعد `ec653ca`). الباتش يطبَّق على `ec653ca` بلا أي رفض (تحقق منه: patch -p1 dry-run ثم مقارنة النتيجة بالملفات النهائية 24/24).

```diff
--- a/.env.example
+++ b/.env.example
@@ -249,3 +249,10 @@
 # MIGRATION_MYSQL_USERNAME=
 # MIGRATION_MYSQL_PASSWORD=
 # MIGRATION_BATCH_SIZE=1000                  # صفوف لكل دفعة (500..2000)
+
+# E33 - الإحالات ونمو المنصة (كلها اختيارية؛ الافتراضيات آمنة). انظر docs/referrals-growth-e33.md.
+# REFERRALS_ENABLED=true                     # مفتاح شامل: false = لا /r/{code} ولا ?ref= ولا صفحة الدعوات ولا تأهيل (الإحالات القائمة تبقى كما هي)
+# REFERRALS_TTL_DAYS=14                      # صلاحية نية الإحالة (جلسة + كوكي أول-طرف مشفّر)؛ تُقصّ دائمًا إلى [7, 30] يومًا
+# REFERRALS_MIN_ACCOUNT_AGE_HOURS=1          # عمر الحساب الأدنى قبل التأهيل (الحملة قد تتجاوزه ضمن [0, 720])
+# REFERRALS_REWARDS_ENABLED=false            # مكافآت جواهر الإحالة معطّلة افتراضيًا؛ تفعيلها يتطلب أيضًا حملة نشطة بمبالغ > 0
+# REFERRALS_NOTIFICATIONS=true               # إشعارات «أصبحت دعوتك مؤهَّلة» و«حصلت على جواهر من الدعوات»
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -168,6 +168,9 @@
       - name: E32 payments on MySQL (verify / refund / reconciliation / reward integration)
         run: php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/PaymentsE32
 
+      - name: E33 referrals on MySQL (unique guards / FK policies / idempotent rewards / privacy / performance)
+        run: php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/ReferralE33
+
       - name: Schema parity against the migrated MySQL database (read-only)
         run: php artisan db:parity --connection=mysql --runtime
 
--- a/app/Http/Controllers/Auth/RegisteredUserController.php
+++ b/app/Http/Controllers/Auth/RegisteredUserController.php
@@ -54,6 +54,9 @@
             return $user;
         });
 
+        // E33: إسناد الإحالة بقرار الخادم من «النية» المخزّنة (جلسة/كوكي مشفّر) لا من حقل في الطلب. معزول (لا يرمي) وصامت للزائر.
+        app(\App\Services\Referrals\ReferralAttributionService::class)->attributeFromRequest($user, $request);
+
         event(new Registered($user));
 
         Auth::login($user);
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -259,6 +259,12 @@
         RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(60)->by('pst:'.($request->user()?->id ?: $request->ip())));
         RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)->by('pwh:'.$request->ip()));
 
+        // E33: الإحالات. الإعادة التوجيه العامة /r/{code} (لكل IP)، لوحة اللاعب (لكل مستخدم)، عدّاد المشاركة، وإجراءات المشرف على الحملات/المراجعة (تُطبَّق داخل الخدمات).
+        RateLimiter::for('referral-redirect', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.redirect_per_minute')))->by('rfr:'.$request->ip()));
+        RateLimiter::for('referral-dashboard', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.dashboard_per_minute')))->by('rfd:'.($request->user()?->id ?: $request->ip())));
+        RateLimiter::for('referral-share', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.share_per_minute')))->by('rfs:'.($request->user()?->id ?: $request->ip())));
+        RateLimiter::for('referral-campaign-action', fn (Request $request) => Limit::perMinute(max(1, (int) config('referrals.limits.campaign_action_per_minute')))->by('rfc:'.($request->user()?->id ?: $request->ip())));
+
         // E11: تجهيز/إزالة تجميليات - UX تتطلب تجربة سريعة (تبديل/معاينة)، لا حساسية مالية هنا.
         RateLimiter::for('cosmetic-equip', function (Request $request) {
             return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
--- a/app/Services/Notifications/NotificationType.php
+++ b/app/Services/Notifications/NotificationType.php
@@ -27,6 +27,7 @@
  *  - E30 رعاية: ContributionService بعد الـcommit → support_confirmed / supporter_tier_changed (للداعم المرتبط بحساب فقط، بلا مبالغ ولا مراجع خاصة)؛ SponsorshipProgramService عند التفعيل → sponsorship_program_started (لداعمي البرنامج فقط)
  *  - E32 دفع الرعاية: SponsorshipPaymentService بعد الـcommit → support_request_received / support_action_required / support_rejected / support_refunded (لصاحب الطلب فقط؛ بلا مبالغ ولا مراجع تحويل ولا بيانات بنكية بالنص). تأكيد الدعم يستعمل support_confirmed القائم (لا إشعار تحقق مكرر)
  *  - E30 سلاسل: CreatorSeriesReviewService بعد الـcommit → series_approved / series_changes_requested / series_rejected / series_hidden (للمبدع)؛ اعتماد سلسلة لمبدع له متابعون (CreatorSeriesAnnouncer ← دفعات بالطابور) → creator_new_series (social)
+ *  - E33 إحالات: ReferralNotifier بعد الـcommit → referral_qualified (للمحيل حين تتأهل إحالته؛ بلا اسم المُحال ولا بياناته) / referral_reward_received (لصاحب الجائزة فقط: مبلغه هو، عبر محفظة الجواهر المعلّقة). لا إشعار على نقرة ولا على تسجيل وحده
  */
 enum NotificationType: string
 {
@@ -79,6 +80,8 @@
     case SupportActionRequired = 'support_action_required';
     case SupportRejected = 'support_rejected';
     case SupportRefunded = 'support_refunded';
+    case ReferralQualified = 'referral_qualified';
+    case ReferralRewardReceived = 'referral_reward_received';
 
     public function category(): NotificationCategory
     {
@@ -99,7 +102,8 @@
             self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden, self::CreatorNewPublication,
             self::SupportConfirmed, self::SupporterTierChanged, self::SponsorshipProgramStarted,
             self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded,
-            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden, self::CreatorNewSeries => NotificationCategory::Social,
+            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden, self::CreatorNewSeries,
+            self::ReferralQualified, self::ReferralRewardReceived => NotificationCategory::Social,
             self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted,
             self::TeamChampionshipStarted, self::TeamChampionshipResultReady => NotificationCategory::Competitive,
         };
@@ -163,6 +167,8 @@
             self::SupportActionRequired => 40,
             self::SupportRejected => 41,
             self::SupportRefunded => 42,
+            self::ReferralQualified => 43,
+            self::ReferralRewardReceived => 44,
         };
     }
 
@@ -207,6 +213,8 @@
             self::SupportActionRequired => '📎',
             self::SupportRejected => '⚠️',
             self::SupportRefunded => '↩️',
+            self::ReferralQualified => '🤝',
+            self::ReferralRewardReceived => '💎',
         };
     }
 
@@ -242,6 +250,7 @@
             self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden => ['creator.series.index'],
             self::CreatorNewSeries => ['series.show'],
             self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded => ['support.payments.show', 'support.history'],
+            self::ReferralQualified, self::ReferralRewardReceived => ['referrals.index'],
         };
     }
 
@@ -297,6 +306,8 @@
             self::SupportActionRequired => 'طلب دعمك يحتاج إلى توضيح منك',
             self::SupportRejected => 'لم يُقبل طلب دعمك',
             self::SupportRefunded => 'سُجّل استرداد دعمك',
+            self::ReferralQualified => 'أصبحت إحدى دعواتك مؤهَّلة',
+            self::ReferralRewardReceived => 'حصلت على '.(int) ($params['gems'] ?? 0).' جوهرة من الدعوات',
             self::SupporterTierChanged => ! empty($params['up'])
                 ? 'ارتقى مستوى دعمك إلى: '.($params['tier'] ?? '')
                 : 'تغيّر مستوى دعمك'.(! empty($params['tier']) ? ' إلى: '.$params['tier'] : ''),
@@ -358,6 +369,8 @@
             self::SupportActionRequired => static::withNote('راجع صفحة الطلب وأكمل البيانات المطلوبة ثم أعد الإرسال.', $params),
             self::SupportRejected => static::withNote('لم يُحتسب هذا الطلب كدعم. يمكنك إنشاء طلب جديد متى شئت.', $params),
             self::SupportRefunded => 'سُجّل استرداد دعمك ويُحتسب مستواك من صافي دعمك المؤكَّد. لا تُسحب مكافآت اللاعبين السابقة.',
+            self::ReferralQualified => 'أنهى صديق دعوتَه إلى المنصة خطواته الأولى. الدعوات لا تغيّر ترتيبك ولا نتائج اللعب.',
+            self::ReferralRewardReceived => 'أُضيفت الجواهر إلى رصيدك المعلّق وتصير متاحة بعد فترة الحجز المعتادة.',
             self::SupporterTierChanged => 'يظهر مستواك ضمن «الداعمون». المستوى يُحسب من صافي دعمك المؤكَّد.',
             self::SponsorshipProgramStarted => 'بدأ برنامج رعاية تشارك فيه كداعم. اطّلع على صفحة الداعمين.',
             self::SeriesApproved => 'أصبحت سلسلتك منشورة ضمن «من المجتمع» ويمكن للاعبين تتبّعها.',
--- a/app/Services/Progression/GameplayProgressionService.php
+++ b/app/Services/Progression/GameplayProgressionService.php
@@ -47,6 +47,13 @@
 
         // E13: نفس الحدث الموثوق بالضبط - لا تكرار لمنطق XP/الإنجازات أعلاه، فقط Quest+Streak.
         $this->engagement->onPuzzleSolved($user, $attempt);
+
+        // E33: أول إجراء رسمي ذو معنى لمُحال = حلّ أحجية رسمية صحيحة (هذا المسار لأحجيات رسمية فقط). معزول بالكامل: الفشل لا يؤثر على اللعب.
+        try {
+            app(\App\Services\Referrals\ReferralQualificationService::class)->onMeaningfulAction($user);
+        } catch (\Throwable $e) {
+            Log::error('فشل تقييم تأهيل الإحالة', ['attempt_id' => $attempt->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
+        }
     }
 
     public function afterCampaignStepCompleted(User $user, CampaignStep $step, UserCampaignProgress $progress): void
--- a/app/Support/NavigationMenu.php
+++ b/app/Support/NavigationMenu.php
@@ -74,6 +74,8 @@
                 $item('notifications', 'notifications.index', 'bell', 'مركز الإشعارات', ['notifications.*']),
                 $teams,
                 $friends,
+                // E33: دعوة الأصدقاء (للموثَّقين فقط؛ يخفيها مفتاح referrals.enabled).
+                config('referrals.enabled') ? $item('referrals', 'referrals.index', 'friends', 'دعوة الأصدقاء', ['referrals.*']) : null,
             ]))];
             // E28: بوابة الاستوديو (عرض فقط، بلا استعلام لكل طلب): صفحة الطلب تعرض الحالة وتُحيل المبدع المعتمَد للاستوديو.
             $verified && $moreGroups[] = ['title' => 'المبدعون', 'items' => [
--- a/app/Support/WalletReasonLabel.php
+++ b/app/Support/WalletReasonLabel.php
@@ -23,6 +23,7 @@
     /** @var array<string, string> رمز كامل ← النص المعروض */
     private const EXACT = [
         'competitive_event_reward' => 'جائزة منافسة',
+        'referral_reward' => 'مكافأة دعوة',
     ];
 
     /**
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Http\Controllers\HealthController;
+use App\Http\Middleware\CaptureReferralIntent;
 use App\Http\Middleware\EnsureAccountIsNotFrozen;
 use App\Http\Middleware\RequestObservability;
 use App\Http\Middleware\RestrictBroadcastChannels;
@@ -39,6 +40,8 @@
         $middleware->web(append: [
             TrackDeviceFingerprint::class,
             SecurityHeaders::class,
+            // E33: يلتقط ?ref=CODE لزائر ضيف على GET فقط (لا أثر بلا ?ref). بعد StartSession حتمًا (ضمن مجموعة web).
+            CaptureReferralIntent::class,
         ]);
 
         $middleware->api(append: [
--- a/config/permissions.php
+++ b/config/permissions.php
@@ -304,6 +304,16 @@
         ],
     ],
 
+    // E33: الإحالات ونمو المنصة. view = الاطلاع على الحملات والإحالات والتحليلات (بلا بيانات خاصة)؛ manage = إدارة الحملات (إنشاء/تعديل/إيقاف/إنهاء)؛ review = اعتماد أو استبعاد إحالة موقوفة.
+    'referrals' => [
+        'label' => 'الإحالات ونمو المنصة',
+        'permissions' => [
+            'referrals.view' => 'عرض حملات الدعوة والإحالات وتحليلات الاكتساب',
+            'referrals.manage' => 'إنشاء/تعديل/إيقاف/استئناف/إنهاء حملات الدعوة وتحديد الافتراضية',
+            'referrals.review' => 'مراجعة الإحالات الموقوفة: اعتماد أو استبعاد بسبب منظَّم',
+        ],
+    ],
+
     'notifications' => [
         'label' => 'الإشعارات',
         'permissions' => [
--- a/database/seeders/RolesAndPermissionsSeeder.php
+++ b/database/seeders/RolesAndPermissionsSeeder.php
@@ -106,6 +106,11 @@
         $this->grantIfMissing('administrator', ['sponsorships.view', 'sponsorships.manage', 'sponsorships.confirm', 'sponsorships.reward_plan']);
         $this->grantIfMissing('content-manager', ['sponsorships.view', 'sponsorships.manage']);
 
+        // E33: المدير العام يملك الكل؛ مدير المحتوى يعرض ويدير الحملات؛ المشرف يعرض ويراجع الإحالات المشبوهة (لا يدير الحملات).
+        $this->grantIfMissing('administrator', ['referrals.view', 'referrals.manage', 'referrals.review']);
+        $this->grantIfMissing('content-manager', ['referrals.view', 'referrals.manage']);
+        $this->grantIfMissing('moderator', ['referrals.view', 'referrals.review']);
+
         $this->grantIfMissing('moderator', [
             'users.view_security',
             'users.view_activity',
--- a/resources/views/campaigns/show.blade.php
+++ b/resources/views/campaigns/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $campaign->title)
+@section('meta_description', 'حملة «'.$campaign->title.'» على أحجيات.')
+@section('canonical', route('campaigns.show', $campaign))
 
 @section('content')
     <div class="max-w-4xl mx-auto">
@@ -11,6 +13,8 @@
 
         <x-sponsor-strip type="campaign" :target-id="$campaign->id" class="mb-4" />
 
+        <x-share-button kind="campaign" :param="$campaign" :title="$campaign->title" class="mb-4" />
+
         <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
             @if ($campaign->cover_image)
                 <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
--- a/resources/views/community/show.blade.php
+++ b/resources/views/community/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $puzzle->title.' - من المجتمع')
+@section('meta_description', 'أحجية من المجتمع بعنوان «'.$puzzle->title.'». أحجيات المجتمع بلا جوائز اقتصادية.')
+@section('canonical', route('community.puzzles.show', $work->public_id))
 @section('content_width', 'max-w-3xl')
 
 {{--
@@ -13,6 +15,8 @@
             <x-ui-icon name="chevron-right" class="w-4 h-4" />أحجيات المجتمع
         </a>
 
+        <x-share-button kind="community_puzzle" :param="$work->public_id" :title="$puzzle->title" />
+
         @if (! empty($seriesContext))
             {{-- E30: شريط سياق السلسلة (تنقّل فقط): الموضع والسابق والتالي. لا يغيّر اللعب ولا يمنح شيئًا. --}}
             <nav aria-label="سياق السلسلة" data-series-context class="glass rounded-2xl px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-sm anim-fade-up">
--- a/resources/views/competitions/show.blade.php
+++ b/resources/views/competitions/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $event->title)
+@section('meta_description', 'منافسة «'.$event->title.'» على أحجيات.')
+@section('canonical', route('competitions.show', $event->slug))
 
 @section('content')
     @php
@@ -12,6 +14,8 @@
     <div class="max-w-3xl mx-auto space-y-6">
         <a href="{{ route('competitions.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل المنافسات</a>
 
+        <x-share-button kind="competition" :param="$event->slug" :title="$event->title" />
+
         <x-sponsor-strip type="competitive_event" :target-id="$event->id" />
 
         <div class="glass rounded-3xl p-6 md:p-8 anim-fade-up">
--- a/resources/views/creator/public.blade.php
+++ b/resources/views/creator/public.blade.php
@@ -1,11 +1,15 @@
 @extends('layouts.app')
 
 @section('title', $creator->user->name.' - مبدع')
+@section('meta_description', 'أحجيات المبدع '.$creator->user->name.' على أحجيات.')
+@section('canonical', route('creators.show', $creator->slug))
 @section('content_width', 'max-w-5xl')
 
 {{-- E28: صفحة المبدع العامة. اسم + صورة رمزية + نبذة + تاريخ الانضمام كمبدع + أرقام مجمَّعة + أحجياته الظاهرة فقط. بلا بريد/هاتف/حالة إدارية/بيانات خاصة. --}}
 @section('content')
     <div class="space-y-6">
+        <x-share-button kind="creator" :param="$creator->slug" :title="$creator->user->name.' - مبدع على أحجيات'" />
+
         <section class="glass rounded-3xl p-6 md:p-8 flex flex-col sm:flex-row items-center sm:items-start gap-5 anim-fade-up" aria-labelledby="creator-name">
             <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$creator->user->name" size="xl" />
             <div class="min-w-0 text-center sm:text-start">
--- a/resources/views/layouts/app.blade.php
+++ b/resources/views/layouts/app.blade.php
@@ -69,6 +69,13 @@
     <meta property="og:title" content="@yield('title', $metaTitle)">
     <meta property="og:description" content="@yield('meta_description', $metaDescription)">
     <meta property="og:type" content="website">
+    <meta property="og:site_name" content="{{ $general['site_name'] }}">
+    <meta property="og:locale" content="ar_AR">
+    {{-- E33: رابط قانوني بلا معاملات (?ref/?c/تتبّع) للصفحات العامة القابلة للمشاركة؛ تحدده كل صفحة بالقسم canonical من route() لا من الطلب. --}}
+    @hasSection('canonical')
+        <link rel="canonical" href="@yield('canonical')">
+        <meta property="og:url" content="@yield('canonical')">
+    @endif
     @if ($ogImageUrl)
         <meta property="og:image" content="{{ $ogImageUrl }}">
     @endif
--- a/resources/views/puzzles/show.blade.php
+++ b/resources/views/puzzles/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $puzzle->title ?? 'أحجية')
+@section('meta_description', 'تحدَّ نفسك بأحجية «'.($puzzle->title ?? '').'» على أحجيات.')
+@section('canonical', route('puzzles.show', $puzzle))
 
 @section('content')
 
@@ -10,6 +12,8 @@
             → كل الأحجيات
         </a>
 
+        <x-share-button kind="puzzle" :param="$puzzle" :title="$puzzle->title ?? 'أحجية'" class="mb-4" />
+
         <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
             <div class="flex flex-wrap items-center gap-2 mb-5">
                 <span class="chip !py-1 !px-3">{{ $puzzle->category->name }}</span>
--- a/resources/views/seasons/show.blade.php
+++ b/resources/views/seasons/show.blade.php
@@ -1,9 +1,13 @@
 @extends('layouts.app')
 
 @section('title', $campaign->title)
+@section('meta_description', 'موسم «'.$campaign->title.'» على أحجيات: مراحل ومهام وتحديات.')
+@section('canonical', route('seasons.show', $season))
 
 @section('content')
     <div class="max-w-4xl mx-auto space-y-8">
+        <x-share-button kind="season" :param="$season" :season-id="$season->id" :title="$campaign->title" />
+
         @if ($isAdmin && ! $season->is_published)
             <div class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-5 py-3 font-bold text-sm anim-fade-up">
                 👁️ وضع المعاينة — غير منشور للجمهور بعد.
--- a/resources/views/series/show.blade.php
+++ b/resources/views/series/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $revision->title.' - سلسلة من المجتمع')
+@section('meta_description', 'سلسلة أحجيات من المجتمع: '.$revision->title.'. سلاسل المجتمع بلا جوائز اقتصادية.')
+@section('canonical', route('series.show', $series->public_id))
 @section('content_width', 'max-w-3xl')
 
 {{--
@@ -13,6 +15,8 @@
             <x-ui-icon name="chevron-right" class="w-4 h-4" />{{ $creator->user->name }}
         </a>
 
+        <x-share-button kind="series" :param="$series->public_id" :title="$revision->title" />
+
         <header class="puzzle-card !p-6 md:!p-8 anim-fade-up d-1" data-series-header>
             @if ($coverUrl)
                 <div class="rounded-2xl overflow-hidden border border-white/10 mb-5">
--- a/resources/views/supporters/index.blade.php
+++ b/resources/views/supporters/index.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', 'الداعمون والرعاة')
+@section('meta_description', 'الداعمون والرعاة الذين يساندون أحجيات.')
+@section('canonical', route('supporters.index'))
 @section('content_width', 'max-w-5xl')
 
 {{--
--- a/resources/views/supporters/show.blade.php
+++ b/resources/views/supporters/show.blade.php
@@ -1,6 +1,8 @@
 @extends('layouts.app')
 
 @section('title', $supporter->display_name)
+@section('meta_description', 'صفحة الداعم '.$supporter->display_name.' على أحجيات.')
+@section('canonical', route('supporters.show', $supporter->slug))
 @section('content_width', 'max-w-3xl')
 
 {{--
@@ -11,6 +13,8 @@
     <div class="space-y-6">
         <a href="{{ route('supporters.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل الداعمين</a>
 
+        <x-share-button kind="supporters" :param="$supporter->slug" :title="$supporter->display_name" />
+
         <article class="glass rounded-3xl p-6 md:p-8 anim-fade-up space-y-4">
             <div class="flex items-start gap-4">
                 @if ($card['logo_url'])
--- a/routes/console.php
+++ b/routes/console.php
@@ -56,3 +56,6 @@
 
 // E32: إنهاء طلبات الدعم «بانتظار الدفع» المتأخرة (حالة فقط، لا حذف؛ المرسَل/المحقَّق لا ينتهي). غيابه لا يكسر شيئًا: تبقى الطلبات القديمة مفتوحة حتى يُنفَّذ.
 $guard(Schedule::command('payments:expire-stale')->everyFifteenMinutes()->name('payments-expire-stale'), 30);
+
+// E33: استكمال الإحالات المنتظرة لعمر الحساب + إعادة تسليم جوانب مكافآت فاشلة (idempotent). مطابقة referrals:reconcile يدوية للقراءة فقط (لا جدولة تكتب).
+$guard(Schedule::command('referrals:process')->everyThirtyMinutes()->name('referrals-process'), 60);
--- a/routes/web.php
+++ b/routes/web.php
@@ -277,3 +277,4 @@
 require __DIR__.'/sponsorship.php';
 require __DIR__.'/payments.php';
 require __DIR__.'/series.php';
+require __DIR__.'/referrals.php';
--- a/tests/Feature/DatabaseE31/E31StaticSchemaTest.php
+++ b/tests/Feature/DatabaseE31/E31StaticSchemaTest.php
@@ -143,7 +143,13 @@
 
 test('U-2/U-3: financial and reward history never cascades accidentally - cascades onto users are guarded or explicitly exempt', function () {
     $guarded = array_keys(FinancialHistoryGuard::HISTORY_TABLES);
-    $exempt = ['wallets' /* يحرسه فحص الرصيد في الحارس */, 'challenge_participants' /* سجل مشاركة مشتق؛ الدفتر الفعلي في currency_transactions */];
+    $exempt = [
+        'wallets' /* يحرسه فحص الرصيد في الحارس */,
+        'challenge_participants' /* سجل مشاركة مشتق؛ الدفتر الفعلي في currency_transactions */,
+        // E33: صف الإحالة لقطة مشتقة (snap_*)؛ الدفتر الفعلي لمكافآتها في currency_transactions المحروس أعلاه، فأي مستخدم نالت محفظته مكافأة إحالة يُمنع حذفه أصلًا.
+        // المُحال بلا مكافأة مسلَّمة لا تاريخ مالي له، وحذفه (حق المحو) يزيل صفّ إحالته فقط؛ المحيل المحذوف يُجعل NULL فيبقى تاريخ المُحال.
+        'user_referrals',
+    ];
     $unguarded = [];
 
     foreach (e31_compiled()['lint']['tables'] as $table => $model) {
```

## C) الهجرات (3) — كاملة

### `database/migrations/2026_10_26_000001_create_referral_campaigns_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - حملات الدعوة (إعدادات فقط؛ لا تاريخ مالي هنا).
 *
 * حدود السلامة في قاعدة البيانات:
 *  - slug UNIQUE            ⇒ ?c=slug يشير إلى حملة واحدة.
 *  - default_slot UNIQUE    ⇒ حملة افتراضية واحدة كحدّ أقصى. القيمة 1 للحملة الافتراضية و NULL لغيرها (NULL متعدد مسموح على SQLite وMySQL)، فلا حاجة لفهرس جزئي.
 *  - لا قيم مبالغ عشرية: الجواهر أعداد صحيحة غير سالبة (أعمدة unsigned).
 * سياسة الحذف: الموسم/المنشئ SET NULL (حذفهما لا يحذف الحملة ولا يكسر الإحالات التاريخية).
 * اللقطة المالية للإحالة تُنسخ إلى user_referrals عند التأهيل، فتعديل الحملة لاحقًا لا يغيّر تاريخًا (انظر الهجرة الثالثة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_campaigns', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('draft'); // draft | active | paused | ended
            $table->boolean('is_public')->default(true);
            $table->unsignedTinyInteger('default_slot')->nullable()->unique('referral_campaigns_default_slot_unique');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();

            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // NULL = قيمة config

            $table->boolean('rewards_enabled')->default(false);
            $table->unsignedInteger('referrer_reward_gems')->default(0);
            $table->unsignedInteger('referred_reward_gems')->default(0);
            $table->unsignedSmallInteger('daily_cap')->nullable();    // عدد الإحالات المكافَأة لكل محيل؛ NULL = قيمة config
            $table->unsignedSmallInteger('monthly_cap')->nullable();
            $table->unsignedInteger('lifetime_cap')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'referral_campaigns_status_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_campaigns');
    }
};
```

### `database/migrations/2026_10_26_000002_create_referral_codes_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - رموز الإحالة الشخصية.
 *
 *  - code UNIQUE              ⇒ رمز واحد لا يخص مستخدمَين (حتى بعد التدوير؛ الرموز القديمة تبقى صفوفًا معطّلة لتفسير روابط قديمة).
 *  - active_for_user_id UNIQUE ⇒ رمز نشط واحد كحدّ أقصى لكل مستخدم: يساوي user_id للرمز النشط وNULL للمدوَّر/المعطّل (NULL متعدد مسموح). الحارس الأخير ضد سباق إنشاء رمزين.
 * سياسة الحذف: حذف المستخدم يحذف رموزه (CASCADE)؛ الإحالات التاريخية تحتفظ بالمحيل SET NULL ولا تنكسر (انظر user_referrals).
 * العدّادات (clicks/shares) تقريبية بالتصميم (نقرات فريدة بالساعة، بلا IP/UA مخزَّن)؛ ليست مصدرًا للمكافآت أبدًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->unsignedBigInteger('active_for_user_id')->nullable()->unique('referral_codes_active_for_user_unique');
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'referral_codes_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
```

### `database/migrations/2026_10_26_000003_create_user_referrals_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E33 - إحالة واحدة لكل حساب مُحال (المصدر الوحيد للحقيقة).
 *
 *  - referred_user_id UNIQUE ⇒ حساب جديد له محيل واحد كحدّ أقصى، ومحيل لا يتغيّر (لا مسار تحديث له في الخدمات). حارس القاعدة لا التطبيق وحده.
 *  - حالات: pending | pending_review | qualified | rewarded | disqualified (مفتاح نصي مغلق في النموذج).
 *  - لقطة المكافأة (snap_*, reward_reserved_at, *_granted_at) تُنسخ عند التأهيل: تعديل الحملة بعدها لا يغيّر تاريخ هذه الإحالة.
 *  - مفتاح فرادة المكافأة في دفتر المحفظة (currency_transactions.idempotency_key): referral-qualified:{id}:referrer|referred ⇒ لا ائتمان مزدوج حتى لو تكرر التسليم.
 *  - risk_signals: مفاتيح إشارات مغلقة فقط (لا IP ولا UA ولا بصمة خام).
 *
 * سياسة الحذف المقصودة:
 *  - المُحال محذوف  ⇒ CASCADE (الإحالة بياناته الشخصية؛ قيود المحفظة تبقى بإجراءاتها الخاصة).
 *  - المحيل محذوف   ⇒ SET NULL (يبقى تاريخ المُحال وأرقام التحليلات؛ لا مكافآت جديدة تُسلَّم لمحيل مجهول).
 *  - الرمز/الحملة/المراجِع محذوفة ⇒ SET NULL (الحملة تُنهى عادةً ولا تُحذف؛ اللقطة تحفظ الشروط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referral_code_id')->nullable()->constrained('referral_codes')->nullOnDelete();
            $table->foreignId('referral_campaign_id')->nullable()->constrained('referral_campaigns')->nullOnDelete();

            $table->string('source', 24)->default('link'); // link | share_param
            $table->string('status', 16)->default('pending');
            $table->string('qualification_rule', 40)->default('first_official_solve');
            $table->unsignedSmallInteger('min_account_age_hours')->nullable(); // لقطة وقت الإسناد (NULL = قيمة config)

            $table->timestamp('action_at')->nullable();       // أول إجراء رسمي ذي معنى (قد يسبق التأهيل بانتظار عمر الحساب)
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->json('risk_signals')->nullable();

            // لقطة المكافأة عند التأهيل + تسليم كل جانب باستقلال.
            $table->string('reward_status', 16)->default('none'); // none | pending | granted | skipped
            $table->string('reward_skip_reason', 32)->nullable();
            $table->unsignedInteger('snap_referrer_gems')->default(0);
            $table->unsignedInteger('snap_referred_gems')->default(0);
            $table->unsignedBigInteger('snap_campaign_id')->nullable(); // معرّف الحملة وقت التأهيل (بلا FK: لقطة تاريخية)
            $table->timestamp('reward_reserved_at')->nullable();        // يُحتسب ضمن سقف المحيل
            $table->timestamp('referrer_granted_at')->nullable();
            $table->timestamp('referred_granted_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->string('disqualified_reason', 32)->nullable(); // قائمة مغلقة
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->unique('referred_user_id', 'user_referrals_referred_user_unique');
            $table->index(['referrer_user_id', 'status'], 'user_referrals_referrer_status_idx');
            $table->index(['referrer_user_id', 'reward_reserved_at'], 'user_referrals_referrer_reserved_idx');
            $table->index(['referral_campaign_id', 'status'], 'user_referrals_campaign_status_idx');
            $table->index(['status', 'action_at'], 'user_referrals_status_action_idx');
            $table->index(['status', 'created_at'], 'user_referrals_status_created_idx');
            $table->index('referral_code_id', 'user_referrals_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
    }
};
```

## D) الإعدادات / env

لا شيء إلزامي: كل الافتراضيات آمنة (المكافآت **معطّلة**). أسطر `.env.example` المضافة (اختيارية، تُنسخ إلى `.env` عند الحاجة فقط):

```dotenv
# E33 - الإحالات ونمو المنصة (كلها اختيارية؛ الافتراضيات آمنة).
REFERRALS_ENABLED=true
REFERRALS_TTL_DAYS=14
REFERRALS_MIN_ACCOUNT_AGE_HOURS=1
REFERRALS_REWARDS_ENABLED=false
REFERRALS_NOTIFICATIONS=true
```

ملف `config/referrals.php` (جديد، موجود في A) هو مصدر الافتراضيات. لا تفعّل `REFERRALS_REWARDS_ENABLED=true` قبل: حملة نشطة بمبالغ > 0 + نص شروط برنامج الدعوة + مراجعة السقوف.

## E) أوامر محلية (PowerShell)

```powershell
cd C:\Users\engya\Desktop\puzzles\puzzle-gems-platform

# 1) فك e33-delivery.zip خارج المشروع (مثلًا C:\Users\engya\Desktop\e33-delivery) ثم انسخ محتوى files\ فوق جذر المشروع:
Copy-Item -Path "C:\Users\engya\Desktop\e33-delivery\files\*" -Destination . -Recurse -Force

# 2) الهجرات الثلاث الجديدة + الصلاحيات الجديدة (referrals.view|manage|review)
php artisan migrate
php artisan db:seed --class=RolesAndPermissionsSeeder

# 3) تنظيف الكاش + بناء الأصول (Tailwind يلتقط أصناف الـ views الجديدة)
php artisan optimize:clear
npm run build

# 4) اختبارات E33 المستهدفة (مرتان؛ لا تشغّل نسختين معًا لأن SQLite ملف واحد)
php vendor/bin/pest --compact tests/Feature/ReferralE33
php vendor/bin/pest --compact tests/Feature/ReferralE33

# 5) فحوص مساندة
php artisan referrals:reconcile            # قراءة فقط؛ رمز الخروج 1 عند انحراف
php artisan referrals:process              # آمن للتكرار (يعمل تلقائيًا كل 30 دقيقة)
php artisan db:parity --static

# 6) (اختياري، محلي فقط) بيانات تجريبية: يرفض العمل في production
php artisan db:seed --class=DemoE33Seeder
```

> إن كنت عدّلت أيًّا من الملفات الـ24 المعدَّلة محليًا بعد `ec653ca`، فلا تستبدلها بالكامل؛ طبّق `e33-modified-files.patch` بدلًا من ذلك (مثلًا `git apply --check e33-modified-files.patch` ثم `git apply e33-modified-files.patch` من جهتك) وانسخ الملفات الجديدة فقط.

## F) اختبارات MySQL

```powershell
$env:APP_ENV="testing"
$env:DB_CONNECTION="mysql"
$env:DB_HOST="127.0.0.1"
$env:DB_PORT="3306"
$env:DB_DATABASE="ahjiyat_platform_test_mysql"
$env:DB_USERNAME="root"
$env:DB_PASSWORD="<كلمة مرور MySQL المحلية>"

# أنشئ القاعدة الفارغة مرة واحدة إن لم تكن موجودة:
#   CREATE DATABASE ahjiyat_platform_test_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/ReferralE33
php vendor/bin/pest --compact --configuration=phpunit.mysql.xml.dist tests/Feature/ReferralE33
php artisan db:parity --connection=mysql --runtime
```

خطوة CI الجديدة (مضافة إلى `.github/workflows/ci.yml` ضمن job الـ MySQL): `E33 referrals on MySQL` تشغّل نفس أمر pest أعلاه.

## DELIVERY PACK

| الملف | الوصف |
|---|---|
| `E33-delivery.md` | هذا الملف: A–F + الباتش + الهجرات |
| `E33-new-files-bundle.md` | الملفات الجديدة الـ54 بمحتواها الكامل |
| `e33-modified-files.patch` | تعديلات الملفات الـ24 (additive) |
| `e33-delivery.zip` | `files/` (78 ملفًا كاملة بمساراتها) + الباتش + الـ md + SHA256SUMS |
| `SHA256SUMS` | بصمات كل الملفات |

لا شيء من الحزمة داخل جذر المستودع.
