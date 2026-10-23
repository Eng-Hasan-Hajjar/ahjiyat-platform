<?php

use App\Models\CreatorEarningAccount;
use App\Models\CreatorPayout;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorRevenuePeriod;
use App\Models\User;
use App\Services\CreatorFinance\CreatorMonetizationService;
use App\Services\CreatorFinance\CreatorPayoutService;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E39 - مساعدات مشتركة لاختبارات مالية المبدعين. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. الإعداد والتفعيل والفترات والدفعات كلها بالخدمات الرسمية؛ **سجل محاولات اللعب فقط يُدرَج مباشرة** كبيانات مصدر
| (نفس شكل CommunityPlayService) كي تُبنى فترات كبيرة بسرعة؛ ويوجد اختبار تكامل يلعب عبر CommunityPlayService الحقيقية.
| الزمن المثبَّت: now = 2026-11-20 12:00. فترة افتراضية 2026-11-01 → 2026-11-10 (منتهية قبل الآن بأكثر من ساعة الاستقرار).
*/

require_once __DIR__.'/../CreatorE28/_support.php';

if (! function_exists('e39_seed')) {
    function e39_seed(): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00'));
        config(['creator_monetization.enabled' => true, 'creator_monetization.accounting_currency' => 'USD', 'sponsorship.accounting_currency' => 'USD']);
    }

    /** مسؤول مالي كامل (administrator: كل صلاحيات creator-finance). */
    function e39_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    /** مشرف عادي لا يملك أي صلاحية مالية. */
    function e39_plain_staff(): User
    {
        return e28_reviewer('moderator');
    }

    /** مسؤول بصلاحيات مالية محددة فقط. @param list<string> $permissions */
    function e39_staff_with(array $permissions): User
    {
        $user = e16User();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** مبدع معتمَد مفعَّل للتسييل. @return array{0: User, 1: CreatorProfile, 2: CreatorEarningAccount} */
    function e39_creator(?User $admin = null, bool $enable = true): array
    {
        $user = e28_creator();
        $profile = $user->creatorProfile()->first();
        $account = null;

        if ($enable) {
            $account = app(CreatorMonetizationService::class)->enable($profile, $admin ?? e39_admin());
        }

        return [$user, $profile, $account];
    }

    function e39_work(User $creator, array $over = []): CreatorPuzzle
    {
        return e28_published($creator, 'text', $over);
    }

    /** يدرج محاولة لعب مجتمع (مصدر البيانات). $correct=false لإجابة خاطئة. */
    function e39_attempt(User $player, CreatorPuzzle $work, bool $correct = true, string|Carbon|null $at = null, int $number = 1): void
    {
        DB::table('community_puzzle_attempts')->insert([
            'user_id' => $player->id,
            'creator_puzzle_id' => $work->id,
            'puzzle_id' => $work->published_puzzle_id,
            'attempt_number' => $number,
            'is_correct' => $correct ? 1 : 0,
            'created_at' => $at === null ? Carbon::parse('2026-11-05 10:00:00') : Carbon::parse($at),
        ]);
    }

    /** $players لاعبين مختلفين كلٌّ يحل كل عمل في $works مرة. @return list<User> */
    function e39_solve(array $works, int $players, string|Carbon|null $at = null): array
    {
        $out = [];

        for ($i = 0; $i < $players; $i++) {
            $player = e16User();
            $out[] = $player;

            foreach ($works as $work) {
                e39_attempt($player, $work, true, $at);
            }
        }

        return $out;
    }

    /** يخفّض الحدود الدنيا لتسهيل السيناريوهات الصغيرة. */
    function e39_loose_policy(int $minUnits = 1, int $minPlayers = 1, int $cap = 3): void
    {
        config([
            'creator_monetization.allocation.min_units_per_creator' => $minUnits,
            'creator_monetization.allocation.min_unique_players_per_creator' => $minPlayers,
            'creator_monetization.allocation.per_user_per_creator_cap' => $cap,
        ]);
    }

    function e39_periods(): CreatorRevenuePeriodService
    {
        return app(CreatorRevenuePeriodService::class);
    }

    /** يرمي CreatorFinanceException؟ يعيد الرسالة أو null. */
    function e39_error(callable $call): ?string
    {
        try {
            $call();
        } catch (\App\Services\CreatorFinance\CreatorFinanceException $e) {
            return $e->getMessage();
        }

        return null;
    }

    function e39_ledger(): \App\Services\CreatorFinance\CreatorLedgerService
    {
        return app(\App\Services\CreatorFinance\CreatorLedgerService::class);
    }

    function e39_payouts(): CreatorPayoutService
    {
        return app(CreatorPayoutService::class);
    }

    function e39_monetization(): CreatorMonetizationService
    {
        return app(CreatorMonetizationService::class);
    }

    /** فترة مسودة (افتراضيًا 2026-11-01 → 2026-11-10، مجمّع 1000.00). */
    function e39_period(?User $admin = null, array $over = []): CreatorRevenuePeriod
    {
        return e39_periods()->create($admin ?? e39_admin(), $over + [
            'label' => 'نوفمبر — الدفعة الأولى',
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-10 00:00:00',
            'pool' => '1000.00',
        ]);
    }

    /** فترة في المراجعة. */
    function e39_review(?User $admin = null, array $over = []): CreatorRevenuePeriod
    {
        $admin ??= e39_admin();

        return e39_periods()->submitForReview(e39_period($admin, $over), $admin);
    }

    /** يقفل فترة جاهزة. */
    function e39_finalize(?CreatorRevenuePeriod $period = null, ?User $admin = null): CreatorRevenuePeriod
    {
        $admin ??= e39_admin();
        $period ??= e39_review($admin);

        return e39_periods()->finalize($period, $admin);
    }

    /**
     * سيناريو جاهز: مبدع مفعَّل بعمل واحد و$players لاعبين، فترة مقفلة بمجمّع 1000.00 ⇒ كل المجمّع للمبدع الوحيد.
     *
     * @return array{0: User, 1: CreatorProfile, 2: CreatorEarningAccount, 3: CreatorRevenuePeriod}
     */
    function e39_funded_creator(int $players = 3, string $pool = '1000.00'): array
    {
        e39_loose_policy();
        $admin = e39_admin();
        [$user, $profile, $account] = e39_creator($admin);
        $work = e39_work($user);
        e39_solve([$work], $players);
        $period = e39_finalize(e39_review($admin, ['pool' => $pool]), $admin);

        return [$user, $profile, $account->refresh(), $period];
    }

    /** يطلب دفعة باسم المبدع. */
    function e39_request(User $creator, string $amount = '30.00', string $method = 'bank_transfer'): CreatorPayout
    {
        return e39_payouts()->request($creator->refresh(), $amount, $method);
    }

    /** إشعارات نوع محدد (قيمة NotificationType) لمستخدم، أو للجميع إن null. */
    function e39_notes(?User $user, string $type)
    {
        $query = \Illuminate\Notifications\DatabaseNotification::query();

        if ($user !== null) {
            $query->where('notifiable_id', $user->id);
        }

        return $query->get()->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->values();
    }

    /** يعدّ صفوف جداول E39 كلها (لقياس «لم يتغير شيء»). */
    function e39_counts(): array
    {
        return [
            'periods' => DB::table('creator_revenue_periods')->count(),
            'allocations' => DB::table('creator_revenue_allocations')->count(),
            'accounts' => DB::table('creator_earning_accounts')->count(),
            'entries' => DB::table('creator_earning_entries')->count(),
            'payouts' => DB::table('creator_payouts')->count(),
        ];
    }
}
