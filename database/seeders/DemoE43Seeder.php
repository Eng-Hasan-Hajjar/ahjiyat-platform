<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\User;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorPremium\CreatorSeriesOfferService;
use App\Services\CreatorPremium\CreatorSeriesPurchaseService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E43 - بيانات Demo للسلاسل المميزة.   php artisan db:seed --class=DemoE43Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق premium.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط. لا تُستدعى من أي seeder آخر.
 * - تعتمد على DemoCreatorSeriesSeeder (تستدعيه إن لم يوجد مبدعها) وتعمل **بالخدمات الرسمية فقط** (تفعيل التسييل E39، عرض البيع، طلب الشراء E41): لا إدخال مباشر في جداول الوصول أو البيع أو الدفتر.
 * - المشهد: سلسلة منشورة بعرض بيع **نشط** (5.00 بعملة حساب المبدع) وسلسلة ثانية تبقى مجانية للمقارنة، ومشترٍ تجريبي لديه **طلب شراء بانتظار الدفع** عبر المزوّد التجريبي (sandbox).
 * - **لا شراء مؤكَّد مزيَّف ولا حصة مبدع ولا قيد دفتر ولا وصول ممنوح**: التأكيد يأتي من إتمام الدفع في المزوّد التجريبي (أو إجراء إداري موثَّق)، وهو ما يختبره العرض.
 * - العرض يحتاج CREATOR_PREMIUM_ENABLED=true ليكون مفعَّلًا؛ يُفعَّل المفتاح هنا **أثناء التشغيل فقط** لبناء العرض ثم يعود لقيمته (لا تعديل لملف البيئة). مع بقاء المفتاح مُعطَّلًا يبقى القفل ساريًا لكن لا checkout جديد.
 * - Idempotent: عرض واحد حيّ لكل سلسلة وطلب الشراء بمفتاح طلب ثابت؛ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE43Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-premium-admin@premium.test';

    public const BUYER_EMAIL = 'demo-premium-buyer@premium.test';

    public const PREMIUM_SERIES_TITLE = 'رحلة الفصول والألغاز';

    public const FREE_SERIES_TITLE = 'ألغاز القلم والكواكب';

    public const PRICE = '5.00';

    public const REQUEST_KEY = 'demo-e43-buyer-order';

    public function run(): void
    {
        $this->refuseInProduction('DemoE43Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        $creatorUser = User::query()->where('email', DemoCreatorSeriesSeeder::CREATOR_EMAIL)->first();

        if ($creatorUser === null || ! CreatorSeries::query()->whereHas('creator', fn ($q) => $q->where('user_id', $creatorUser->id))->whereNotNull('published_revision_id')->exists()) {
            $this->call(DemoCreatorSeriesSeeder::class);
            $creatorUser = User::query()->where('email', DemoCreatorSeriesSeeder::CREATOR_EMAIL)->firstOrFail();
        }

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير السوق المميز التجريبي', 'administrator');
        $buyer = $this->user(self::BUYER_EMAIL, 'مشترٍ مميز تجريبي');
        $profile = CreatorProfile::query()->where('user_id', $creatorUser->id)->firstOrFail();

        $series = CreatorSeries::query()->where('creator_profile_id', $profile->id)->whereNotNull('published_revision_id')->where('published_title', self::PREMIUM_SERIES_TITLE)->first();

        if ($series === null) {
            $this->command?->warn('DemoE43Seeder: لم أجد السلسلة المنشورة «'.self::PREMIUM_SERIES_TITLE.'»؛ شغّل DemoCreatorSeriesSeeder أولًا.');

            return;
        }

        $this->tryDo(fn () => app(CreatorMonetizationService::class)->enable($profile, $admin, 'تفعيل تجريبي لعرض السلاسل المميزة.'));

        // المفتاح يُفعَّل أثناء بناء العرض فقط ثم يعود لقيمته الأصلية. وكذلك المزوّد الافتراضي: السوق المميز لا يقبل إلا مزوّدًا بصفحة دفع مستضافة
        // (التحويل اليدوي manual مرفوض له)، فنستعمل المزوّد التجريبي sandbox أثناء بناء الطلب فقط، ويُرفض في الإنتاج بالكود أصلًا.
        $was = config('creator_premium.enabled');
        $wasProvider = config('commerce.default_provider');
        config(['creator_premium.enabled' => true, 'commerce.default_provider' => 'sandbox']);
        $orderPlaced = false;

        try {
            $offers = app(CreatorSeriesOfferService::class);
            $live = CreatorSeriesSaleOffer::query()->where('creator_series_id', $series->id)->whereIn('status', CreatorSeriesSaleOffer::LIVE_STATUSES)->first();

            if ($live === null) {
                $this->tryDo(function () use ($offers, $creatorUser, $series) {
                    $draft = $offers->createDraft($creatorUser, $series->public_id, self::PRICE);
                    $offers->activate($creatorUser, $draft->public_id);
                });
            } elseif ($live->status === CreatorSeriesSaleOffer::STATUS_DRAFT) {
                $this->tryDo(fn () => $offers->activate($creatorUser, $live->public_id));
            }

            $orderPlaced = $this->tryDo(fn () => app(CreatorSeriesPurchaseService::class)->request($buyer, $series->fresh(), self::REQUEST_KEY));
        } finally {
            config(['creator_premium.enabled' => $was, 'commerce.default_provider' => $wasProvider]);
        }

        $order = $orderPlaced
            ? 'وطلب شراء بانتظار الدفع للمشتري التجريبي (لا شراء مؤكَّد ولا وصول ولا دفتر)'
            : 'ولم يُنشأ طلب الشراء التجريبي (المزوّد التجريبي sandbox غير متاح في هذه البيئة)';

        $this->command?->info('DemoE43Seeder: سلسلة مميزة «'.self::PREMIUM_SERIES_TITLE.'» بعرض نشط، وسلسلة مجانية «'.self::FREE_SERIES_TITLE.'»، '.$order.'. الدخول: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    protected function user(string $email, string $name, ?string $role = null): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if ($role !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /** @return bool true إن نجحت الخطوة. */
    protected function tryDo(\Closure $call): bool
    {
        try {
            $call();

            return true;
        } catch (\Throwable $e) {
            $this->command?->warn('DemoE43Seeder: تخطّي خطوة - '.$e->getMessage());

            return false;
        }
    }
}
