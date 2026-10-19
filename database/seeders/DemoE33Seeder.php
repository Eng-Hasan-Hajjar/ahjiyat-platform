<?php

namespace Database\Seeders;

use App\Models\DeviceSighting;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ReferralReviewService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * E33 - بيانات Demo للإحالات.   php artisan db:seed --class=DemoE33Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق referrals.test، بلا بريد حقيقي ولا IP حقيقي).
 * - بالخدمات الرسمية فقط (حملات/رموز/إسناد/تأهيل/مراجعة/محفظة): لا كتابة مباشرة بحالة أو جواهر. المكافآت مفعّلة داخل هذه العملية فقط (config مؤقت) لتوليد إحالة مكافَأة.
 * - Idempotent: كل حساب بريد ثابت، وكل إحالة تُنشأ مرة واحدة لكل مُحال، والحملات بـslug ثابت ⇒ إعادة التشغيل لا تكرّر شيئًا.
 * - تولّد: حملتان نشطتان (إحداهما افتراضية بمكافأة)، إحالات معلّقة، مؤهَّلة، مكافَأة واحدة، مستبعدة واحدة، ومشبوهة واحدة (pending_review).
 */
class DemoE33Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-referrals-admin@referrals.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE33Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        config(['referrals.enabled' => true, 'referrals.rewards.enabled' => true]);

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير الإحالات التجريبي', 0);
        $admin->syncRoles(['administrator']);

        $campaigns = app(ReferralCampaignService::class);
        $main = $this->campaign($campaigns, $admin, 'demo-friends', 'ادعُ صديقًا', ['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10, 'min_account_age_hours' => 1], true);
        $winter = $this->campaign($campaigns, $admin, 'demo-winter', 'تحدي الشتاء', ['rewards_enabled' => false, 'min_account_age_hours' => 1], false);

        $codes = app(ReferralCodeService::class);
        $attribution = app(ReferralAttributionService::class);
        $qualification = app(ReferralQualificationService::class);

        $a = $this->user('demo-e33-referrer-a@referrals.test', 'سلمى (محيلة)', 30);
        $b = $this->user('demo-e33-referrer-b@referrals.test', 'عمر (محيل)', 30);
        $c = $this->user('demo-e33-referrer-c@referrals.test', 'ريم (محيلة)', 30);
        $code = ['a' => $codes->ensureFor($a)->code, 'b' => $codes->ensureFor($b)->code, 'c' => $codes->ensureFor($c)->code];

        // (مفتاح، محيل، حملة، هل يحلّ أحجية، مصير)
        $plan = [
            ['p1', 'a', $main, false, null], ['p2', 'a', $winter, false, null], ['p3', 'b', $main, false, null], ['p4', 'b', null, false, null],
            ['q1', 'b', $winter, true, null], ['q2', 'b', $winter, true, null], ['q3', 'c', $winter, true, null],
            ['rw', 'a', $main, true, null],
            ['dq', 'a', $main, true, 'disqualify'],
            ['sus', 'c', $winter, true, 'suspicious'],
        ];

        $review = app(ReferralReviewService::class);

        foreach ($plan as [$key, $who, $campaign, $acts, $fate]) {
            $referred = $this->user("demo-e33-{$key}@referrals.test", 'مُحال '.strtoupper($key), 3);

            if (UserReferral::query()->where('referred_user_id', $referred->id)->exists()) {
                continue; // idempotent
            }

            if ($fate === 'suspicious') {
                // إشارتان ضعيفتان معًا (شبكة + جهاز مشتركان) ⇒ تعليق للمراجعة (لا حظر). قيم وهمية في نطاق الوثائق (TEST-NET).
                foreach ([$c, $referred] as $u) {
                    DeviceSighting::query()->updateOrCreate(['user_id' => $u->id, 'ip_address' => '203.0.113.77', 'device_hash' => str_repeat('d', 64)], ['last_seen_at' => now()]);
                }
            }

            $referral = $attribution->attribute($referred, $code[$who], $campaign?->slug);

            if ($referral !== null && $acts) {
                $qualification->onMeaningfulAction($referred);
            }

            if ($fate === 'disqualify' && $referral !== null) {
                $review->disqualify($admin, $referral->fresh(), 'fake_accounts', 'حالة تجريبية', true);
            }
        }

        $this->command?->info('DemoE33: إحالات='.UserReferral::query()->count().' · '.json_encode(UserReferral::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all()));
        $this->command?->info('حساب المشرف: '.self::ADMIN_EMAIL.' / '.self::PASSWORD);
    }

    protected function campaign(ReferralCampaignService $svc, User $admin, string $slug, string $name, array $extra, bool $default): ReferralCampaign
    {
        $c = ReferralCampaign::query()->where('slug', $slug)->first() ?? $svc->create($admin, ['name' => $name, 'slug' => $slug, 'is_public' => true] + $extra);

        if ($c->status === ReferralCampaign::STATUS_DRAFT) {
            $c = $svc->activate($admin, $c);
        }

        if ($default && ! $c->isDefault()) {
            $c = $svc->makeDefault($admin, $c);
        }

        return $c;
    }

    protected function user(string $email, string $name, int $daysOld): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('users')->where('id', $user->id)->update(['created_at' => now()->subDays($daysOld)->subHour()]);

        return $user->fresh();
    }
}
