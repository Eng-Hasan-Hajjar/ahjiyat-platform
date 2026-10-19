<?php

use App\Models\DeviceSighting;
use App\Models\FraudFlag;
use App\Models\Puzzle;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralAttributionService;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralCodeService;
use App\Services\Referrals\ReferralQualificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E33 (الإحالات) - مساعدات مشتركة. كل شيء على SQLite وكاش array (ويعمل كما هو على MySQL بـphpunit.mysql.xml.dist)؛ لا شبكة ولا حزم.
| كل تهيئة تمرّ بالخدمات الرسمية (حملات/رموز/إسناد/تأهيل)؛ لا كتابة مباشرة بحالة أو مكافأة إلا عند اختبار الحراسة نفسها.
| الافتراضي بالاختبارات: عمر حساب أدنى 0 والمكافآت معطّلة (كالإنتاج)؛ اختبارات المكافآت تفعّلها صراحةً.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';

if (! function_exists('e33_seed')) {
    function e33_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        config([
            'referrals.enabled' => true,
            'referrals.rewards.enabled' => false,
            'referrals.qualification.min_account_age_hours' => 0,
            'referrals.notifications.enabled' => true,
            'referrals.attribution.ttl_days' => 14,
        ]);
    }

    function e33_rewards_on(): void
    {
        config(['referrals.rewards.enabled' => true]);
    }

    function e33_admin(): User
    {
        $u = e16User();
        $u->assignRole('administrator');

        return $u;
    }

    function e33_moderator(): User
    {
        $u = e16User();
        $u->assignRole('moderator');

        return $u;
    }

    function e33_manager(): User
    {
        $u = e16User();
        $u->assignRole('content-manager');

        return $u;
    }

    /** لاعب موثَّق (e16User) بعمر حساب كافٍ (افتراضيًا قبل يومين). */
    function e33_user(array $attrs = [], int $daysOld = 2): User
    {
        $u = e16User($attrs);
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $u->fresh();
    }

    function e33_codes(): ReferralCodeService
    {
        return app(ReferralCodeService::class);
    }

    function e33_code(User $u): string
    {
        return e33_codes()->ensureFor($u)->code;
    }

    /** حملة بالخدمة الرسمية. $over تتجاوز الافتراضيات. */
    function e33_campaign(array $over = [], bool $activate = true, bool $default = false, ?User $actor = null): ReferralCampaign
    {
        $actor ??= e33_admin();
        $svc = app(ReferralCampaignService::class);
        $c = $svc->create($actor, $over + ['name' => 'حملة '.fake()->unique()->numerify('####'), 'slug' => 'c'.fake()->unique()->numerify('######'), 'is_public' => true]);

        if ($activate) {
            $c = $svc->activate($actor, $c);
        }

        if ($default) {
            $c = $svc->makeDefault($actor, $c);
        }

        return $c;
    }

    /** حملة بمكافآت مفعّلة (20 محيل / 10 مُحال افتراضيًا) ونشطة. */
    function e33_reward_campaign(array $over = [], bool $default = false): ReferralCampaign
    {
        e33_rewards_on();

        return e33_campaign($over + ['rewards_enabled' => true, 'referrer_reward_gems' => 20, 'referred_reward_gems' => 10], true, $default);
    }

    /**
     * يُسنِد مُحالًا جديدًا إلى محيل بالخدمة الرسمية. يعيد [مُحال, إحالة].
     *
     * @return array{0: User, 1: UserReferral}
     */
    function e33_attr(User $referrer, ?ReferralCampaign $campaign = null, array $referredAttrs = [], int $daysOld = 2): array
    {
        $referred = e33_user($referredAttrs, $daysOld);
        $referral = app(ReferralAttributionService::class)->attribute($referred, e33_code($referrer), $campaign?->slug);

        return [$referred, $referral];
    }

    /** يحاكي أول إجراء رسمي ذي معنى للمُحال (الحدث الذي تطلقه afterPuzzleSolved) ويعيد الإحالة بعد التقييم. */
    function e33_act(User $referred): ?UserReferral
    {
        app(ReferralQualificationService::class)->onMeaningfulAction($referred);

        return UserReferral::query()->where('referred_user_id', $referred->id)->first();
    }

    /** إحالة مؤهَّلة جاهزة: يعيد [مُحال, إحالة بعد التأهيل]. */
    function e33_qualified(User $referrer, ?ReferralCampaign $campaign = null): array
    {
        [$referred, $referral] = e33_attr($referrer, $campaign);

        return [$referred, e33_act($referred) ?? $referral];
    }

    /** حلّ حقيقي لأحجية رسمية بمسار اللعب الفعلي (يطلق afterPuzzleSolved). */
    function e33_solve(User $user, ?Puzzle $puzzle = null): array
    {
        $puzzle ??= Puzzle::factory()->create(['answer_raw' => 'صح']);

        return app(\App\Services\PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح');
    }

    /** يربط مستخدمَين بنفس IP/بصمة جهاز (إشارات ضعيفة). */
    function e33_share_network(User $a, User $b, bool $ip = true, bool $device = true): void
    {
        foreach ([$a, $b] as $u) {
            DeviceSighting::query()->create([
                'user_id' => $u->id,
                'ip_address' => $ip ? '203.0.113.10' : '203.0.113.'.(100 + $u->id % 100),
                'device_hash' => $device ? str_repeat('a', 64) : hash('sha256', 'dev'.$u->id),
                'last_seen_at' => now(),
            ]);
        }
    }

    function e33_wallet(User $u): array
    {
        $w = \App\Models\Wallet::query()->where('user_id', $u->id)->first();

        return ['pending' => (int) ($w->pending_balance ?? 0), 'available' => (int) ($w->available_balance ?? 0), 'earned' => (int) ($w->lifetime_earned ?? 0)];
    }

    function e33_ledger(): \Illuminate\Support\Collection
    {
        return \App\Models\CurrencyTransaction::query()->where('idempotency_key', 'like', 'referral-qualified:%')->orderBy('id')->get();
    }

    function e33_password(): string
    {
        return 'Str0ng-Passw0rd!x';
    }

    /** يسجّل حسابًا جديدًا عبر مسار التسجيل الفعلي (يستعمل جلسة الاختبار الحالية). */
    function e33_register(?string $email = null, array $extra = [])
    {
        $email ??= 'new'.fake()->unique()->numerify('######').'@example.test';

        return test()->post(route('register'), $extra + ['name' => 'مسجِّل جديد', 'email' => $email, 'password' => e33_password(), 'password_confirmation' => e33_password()]);
    }
}
