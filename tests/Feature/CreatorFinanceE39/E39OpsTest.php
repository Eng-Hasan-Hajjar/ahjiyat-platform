<?php

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorPayout;
use App\Models\CreatorRevenuePeriod;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceAnalytics;
use App\Services\CreatorFinance\CreatorFinanceReconciler;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\DemoE39Seeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e39_seed());

/** [exit code, output] */
function e39_artisan(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

function e39_fingerprint(): string
{
    $parts = [];

    foreach (['creator_revenue_periods', 'creator_revenue_allocations', 'creator_earning_accounts', 'creator_earning_entries', 'creator_payouts'] as $table) {
        $parts[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return md5(json_encode($parts));
}

// ───────────────────────────── BA18: الأوامر ─────────────────────────────

test('BA18-01: the preview command writes nothing, prints the numbers and supports JSON', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 3);
    $period = e39_review($admin);
    $before = e39_fingerprint();

    [$code, $out] = e39_artisan('creator-revenue:preview', ['period' => $period->public_id]);
    [$jsonCode, $json] = e39_artisan('creator-revenue:preview', ['period' => $period->public_id, '--json' => true]);
    $decoded = json_decode($json, true);

    expect($code)->toBe(0)->and($out)->toContain($period->public_id)->toContain('معاينة فقط')->and($jsonCode)->toBe(0)->and($decoded)->toBeArray()->and($decoded)->toHaveKeys(['summary', 'rows'])
        ->and($decoded['summary']['pool_minor'])->toBe(100000)->and(e39_fingerprint())->toBe($before)->and(CreatorEarningEntry::query()->count())->toBe(0);
});

test('BA18-02: previewing an unknown period fails cleanly and a finalized period shows the stored numbers', function () {
    [, , , $period] = e39_funded_creator(3);

    expect(e39_artisan('creator-revenue:preview', ['period' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])[0])->toBe(1);

    [$code, $out] = e39_artisan('creator-revenue:preview', ['period' => $period->public_id]);

    expect($code)->toBe(0)->and($out)->toContain('أرقام مخزَّنة');
});

test('BA18-03: the finalize command refuses without the exact confirmation, an actor or a permitted actor', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_solve([e39_work($creator)], 3);
    $period = e39_review($admin);
    $before = e39_fingerprint();
    $plain = e39_plain_staff();

    expect(e39_artisan('creator-revenue:finalize', ['period' => $period->public_id, '--actor' => $admin->email])[0])->toBe(1)
        ->and(e39_artisan('creator-revenue:finalize', ['period' => $period->public_id, '--actor' => $admin->email, '--confirm' => 'WRONG'])[0])->toBe(1)
        ->and(e39_artisan('creator-revenue:finalize', ['period' => $period->public_id, '--confirm' => $period->public_id])[0])->toBe(1)
        ->and(e39_artisan('creator-revenue:finalize', ['period' => $period->public_id, '--actor' => 'nobody@example.test', '--confirm' => $period->public_id])[0])->toBe(1)
        ->and(e39_artisan('creator-revenue:finalize', ['period' => $period->public_id, '--actor' => $plain->email, '--confirm' => $period->public_id])[0])->toBe(1)
        ->and(e39_artisan('creator-revenue:finalize', ['period' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', '--actor' => $admin->email, '--confirm' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])[0])->toBe(1)
        ->and(e39_fingerprint())->toBe($before)->and($period->refresh()->status)->toBe('review');
});

test('BA18-04: the finalize command closes a reviewed period once and a rerun changes nothing', function () {
    e39_loose_policy();
    $admin = e39_admin();
    [$creator, , $account] = e39_creator($admin);
    e39_solve([e39_work($creator)], 3);
    $period = e39_review($admin);
    $args = ['period' => $period->public_id, '--actor' => $admin->email, '--confirm' => $period->public_id];

    [$code, $out] = e39_artisan('creator-revenue:finalize', $args);
    $after = e39_fingerprint();
    [$again, $outAgain] = e39_artisan('creator-revenue:finalize', $args);

    expect($code)->toBe(0)->and($out)->toContain('أُقفلت الفترة')->and($period->refresh()->status)->toBe('finalized')->and($account->refresh()->available_minor)->toBe(100000)
        ->and($again)->toBe(0)->and($outAgain)->toContain('مُقفلة مسبقًا')->and(e39_fingerprint())->toBe($after)->and(CreatorEarningEntry::query()->count())->toBe(1);
});

test('BA18-05: the finalize command will not close a draft or a period that has not settled yet', function () {
    $admin = e39_admin();
    $draft = e39_period($admin);
    $args = fn ($p) => ['period' => $p->public_id, '--actor' => $admin->email, '--confirm' => $p->public_id];

    expect(e39_artisan('creator-revenue:finalize', $args($draft))[0])->toBe(1)->and($draft->refresh()->status)->toBe('draft');

    $fresh = e39_review($admin, ['starts_at' => '2026-11-19 00:00:00', 'ends_at' => '2026-11-20 11:30:00']);

    expect(e39_artisan('creator-revenue:finalize', $args($fresh))[0])->toBe(1)->and($fresh->refresh()->status)->toBe('review');
});

test('BA18-06: nothing about E39 is scheduled - closing a period is always a human decision', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

    expect($commands)->not->toContain('creator-revenue')->not->toContain('creator-finance')->not->toContain('creator-payout');

    $kernel = file_get_contents(base_path('routes/console.php'));

    expect($kernel)->not->toContain('creator-revenue:finalize');
});

// ───────────────────────────── BA19: المصالحة ─────────────────────────────

test('BA19-01: a healthy ledger reconciles clean and the command exits 0', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-R-1001');
    e39_request($creator, '50.00');

    [$code, $out] = e39_artisan('creator-finance:reconcile');

    expect($code)->toBe(0)->and($out)->toContain('متسقة');
});

test('BA19-02: a drifted snapshot is reported, exits 1, and is repaired only by --fix-balances', function () {
    [, , $account] = e39_funded_creator(3);
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['available_minor' => 7, 'reserved_minor' => 3]);
    $ledgerBefore = DB::table('creator_earning_entries')->get()->map(fn ($r) => (array) $r)->all();

    [$code, $out] = e39_artisan('creator-finance:reconcile');

    expect($code)->toBe(1)->and($out)->toContain('snapshot_drift')->and($account->refresh()->available_minor)->toBe(7);

    [$fixCode, $fixOut] = e39_artisan('creator-finance:reconcile', ['--fix-balances' => true, '--actor' => e39_admin()->email]);

    expect($fixCode)->toBe(0)->and($fixOut)->toContain('أُصلح')->and($account->refresh()->available_minor)->toBe(100000)->and($account->reserved_minor)->toBe(0)
        ->and(DB::table('creator_earning_entries')->get()->map(fn ($r) => (array) $r)->all())->toBe($ledgerBefore)
        ->and(OperationalAuditLog::query()->where('action', 'creator_finance_balances_fixed')->count())->toBe(1);
});

test('BA19-03: reconcile never fixes anything unless asked and a plain run never writes', function () {
    [, , $account] = e39_funded_creator(3);
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['available_minor' => 7]);
    $before = e39_fingerprint();

    e39_artisan('creator-finance:reconcile');
    e39_artisan('creator-finance:reconcile', ['--limit' => 1]);

    expect(e39_fingerprint())->toBe($before);
});

test('BA19-04: findings the command cannot fix safely stay reported and keep the exit code at 1 even with --fix-balances', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');
    DB::table('creator_payouts')->where('id', $payout->id)->update(['status' => 'paid', 'open_account_id' => null]);

    $report = app(CreatorFinanceReconciler::class)->run(500, true);
    $codes = collect($report['findings'])->pluck('code')->all();
    [$code] = e39_artisan('creator-finance:reconcile', ['--fix-balances' => true]);

    expect($codes)->toContain('reserved_vs_open_payouts')->toContain('paid_vs_paid_payouts')->toContain('payout_missing_settlement')->and($report['remaining'])->toBeGreaterThan(0)->and($code)->toBe(1)
        ->and($payout->refresh()->status)->toBe('paid');
});

test('BA19-05: a tampered period total, a missing ledger line and a wrong open marker are each detected', function () {
    [$creator, , , $period] = e39_funded_creator(3);
    $payout = e39_request($creator, '100.00');

    DB::table('creator_revenue_periods')->where('id', $period->id)->update(['allocated_minor' => 99999]);
    DB::table('creator_payouts')->where('id', $payout->id)->update(['open_account_id' => null]);
    $codes = collect(app(CreatorFinanceReconciler::class)->run()['findings'])->pluck('code')->all();

    expect($codes)->toContain('period_pool_mismatch')->toContain('period_allocation_totals')->toContain('period_ledger_mismatch')->toContain('payout_open_marker');
});

test('BA19-06: an entry in another currency than its account is detected', function () {
    [, , $account] = e39_funded_creator(3);
    DB::table('creator_earning_entries')->where('creator_earning_account_id', $account->id)->update(['currency' => 'EUR']);
    $codes = collect(app(CreatorFinanceReconciler::class)->run()['findings'])->pluck('code')->all();

    expect($codes)->toContain('currency_mismatch');
});

test('BA19-07: the limit bounds how many accounts are checked and a bad actor is refused', function () {
    $admin = e39_admin();

    for ($i = 0; $i < 4; $i++) {
        e39_creator($admin);
    }

    expect(app(CreatorFinanceReconciler::class)->run(2)['checked_accounts'])->toBe(2)->and(app(CreatorFinanceReconciler::class)->run(500)['checked_accounts'])->toBe(4)
        ->and(e39_artisan('creator-finance:reconcile', ['--actor' => 'nobody@example.test'])[0])->toBe(1);
});

// ───────────────────────────── BA20: التحليلات والإشعارات ─────────────────────────────

test('BA20-01: analytics numbers are exact aggregates of the accounts, payouts and periods', function () {
    [$creator, , $account, $period] = e39_funded_creator(3);
    $admin = e39_admin();
    $paid = e39_request($creator, '200.00');
    e39_payouts()->approve($paid, $admin);
    e39_payouts()->settle($paid->refresh(), $admin, 'REF-S-1001');
    e39_request($creator, '100.00');
    $o = app(CreatorFinanceAnalytics::class)->overview();

    expect($o['currency'])->toBe('USD')->and($o['accounts'])->toBe(1)
        ->and($o['liability'])->toBe(['available_minor' => 70000, 'reserved_minor' => 10000, 'owed_minor' => 80000, 'paid_lifetime_minor' => 20000])
        ->and($o['account_states']['enabled'])->toBe(1)->and($o['payouts']['paid']['count'])->toBe(1)->and($o['payouts']['paid']['total_minor'])->toBe(20000)
        ->and($o['payouts']['requested']['count'])->toBe(1)->and($o['recent_periods'][0]['public_id'])->toBe($period->public_id)->and($o['oldest_open_payout_hours'])->toBe(0);
});

test('BA20-02: analytics contain no creator identity and use a fixed number of queries', function () {
    [$creator, $profile] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_request($creator, '100.00');

    DB::enableQueryLog();
    $few = (function () {
        DB::flushQueryLog();
        $o = app(CreatorFinanceAnalytics::class)->overview();

        return [count(DB::getQueryLog()), $o];
    })();

    for ($i = 0; $i < 5; $i++) {
        e39_creator($admin);
    }

    DB::flushQueryLog();
    app(CreatorFinanceAnalytics::class)->overview();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few[0])->and(json_encode($few[1]))->not->toContain($profile->slug)->not->toContain($creator->email)->not->toContain((string) $creator->name);
});

test('BA20-03: a statement notification is sent once per creator at close and never repeats on a rerun', function () {
    $admin = e39_admin();
    [$creator] = e39_creator($admin);
    e39_loose_policy();
    e39_solve([e39_work($creator)], 3);
    $period = e39_review($admin);
    e39_periods()->finalize($period, $admin);
    e39_periods()->finalize($period->refresh(), $admin);

    expect(e39_notes($creator, 'creator_statement_ready'))->toHaveCount(1);
});

test('BA20-04: notifications never carry amounts, references or internal notes', function () {
    [$creator] = e39_funded_creator(3);
    $admin = e39_admin();
    $payout = e39_request($creator, '123.45');
    e39_payouts()->approve($payout, $admin, 'ملاحظة داخلية سرية');
    e39_payouts()->settle($payout->refresh(), $admin, 'SECRET-REF-9001');
    $json = \Illuminate\Notifications\DatabaseNotification::query()->get()->map(fn ($n) => json_encode($n->data, JSON_UNESCAPED_UNICODE))->implode("\n");

    expect($json)->not->toContain('123')->not->toContain('SECRET-REF-9001')->not->toContain('ملاحظة داخلية سرية')->not->toContain('$')
        ->and(e39_notes($creator, 'creator_payout_approved'))->toHaveCount(1)->and(e39_notes($creator, 'creator_payout_paid'))->toHaveCount(1);
});

test('BA20-05: notifications can be switched off and a frozen creator receives none, without affecting the money', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    config(['creator_monetization.notifications.enabled' => false]);
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);

    expect(e39_notes($creator, 'creator_payout_approved'))->toHaveCount(0)->and($payout->refresh()->status)->toBe('approved');

    config(['creator_monetization.notifications.enabled' => true]);
    $creator->forceFill(['is_frozen' => true])->save();
    e39_payouts()->reject($payout->refresh(), $admin, 'رُفض أثناء تجميد الحساب لأسباب إدارية');

    expect(e39_notes($creator, 'creator_payout_rejected'))->toHaveCount(0)->and($account->refresh()->available_minor)->toBe(100000);
});

test('BA20-06: a failing notification dispatcher never undoes an approval, a rejection or a payment', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $this->mock(NotificationDispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('mail down')));
    $a = e39_request($creator, '100.00');

    expect(e39_payouts()->approve($a, $admin)->status)->toBe('approved')->and(e39_payouts()->settle($a->refresh(), $admin, 'REF-T-1001')->status)->toBe('paid');

    $b = e39_request($creator, '100.00');

    expect(e39_payouts()->reject($b, $admin, 'رفض لأسباب تشغيلية معتمدة')->status)->toBe('rejected')->and($account->refresh()->paid_lifetime_minor)->toBe(10000);
});

// ───────────────────────────── BA21: الـSeeder والحراسة والتنظيم ─────────────────────────────

test('BA21-01: the demo seeder refuses production', function () {
    $this->app['env'] = 'production';
    $seeder = app(DemoE39Seeder::class);

    expect(fn () => $seeder->run())->toThrow(RuntimeException::class);
    expect(CreatorRevenuePeriod::query()->count())->toBe(0)->and(User::query()->where('email', DemoE39Seeder::ADMIN_EMAIL)->exists())->toBeFalse();
});

test('BA21-02: the demo seeder builds a closed period, a draft and an open payout, is idempotent and reconciles clean', function () {
    $this->seed(DemoE39Seeder::class);
    $first = e39_counts();
    $this->seed(DemoE39Seeder::class);
    $report = app(CreatorFinanceReconciler::class)->run();

    expect(e39_counts())->toBe($first)->and(CreatorRevenuePeriod::query()->where('status', 'finalized')->count())->toBe(1)->and(CreatorRevenuePeriod::query()->where('status', 'draft')->count())->toBe(1)
        ->and(CreatorPayout::query()->where('status', 'requested')->count())->toBe(1)->and($first['accounts'])->toBeGreaterThanOrEqual(1)->and($first['entries'])->toBeGreaterThanOrEqual(1)
        ->and($report['findings'])->toBe([])->and(User::query()->where('email', DemoE39Seeder::ADMIN_EMAIL)->first()->hasRole('administrator'))->toBeTrue();
});

test('BA21-03: the demo seeder touches no gems, wallets or sponsorship data and is not called by any other seeder', function () {
    $snapshot = fn () => [\App\Models\GemTransaction::query()->count(), \App\Models\CurrencyTransaction::query()->count(), DB::table('sponsorship_payments')->count(), DB::table('redemption_requests')->count()];
    $before = $snapshot();
    $this->seed(DemoE39Seeder::class);

    expect($snapshot())->toBe($before);

    foreach (glob(database_path('seeders/*.php')) as $file) {
        if (basename($file) === 'DemoE39Seeder.php') {
            continue;
        }

        expect(file_get_contents($file))->not->toContain('DemoE39Seeder');
    }
});

test('BA21-04: an account with an earnings history cannot be deleted - the guard lists it and the database refuses', function () {
    [$creator] = e39_funded_creator(3);
    $history = app(FinancialHistoryGuard::class)->historyFor($creator->refresh());

    expect($history)->not->toBe([])->and(implode(' ', $history))->toContain('أرباح');

    expect(fn () => DB::table('users')->where('id', $creator->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('BA21-05: the configuration is complete, typed and safe by default', function () {
    $c = config('creator_monetization');

    expect($c['enabled'])->toBeTrue()->and($c['payout']['provider'])->toBe('manual')->and($c['payout']['min_amount_minor'])->toBeInt()->toBeGreaterThan(0)->and($c['payout']['max_amount_minor'])->toBeGreaterThan($c['payout']['min_amount_minor'])
        ->and($c['allocation']['per_user_per_creator_cap'])->toBeGreaterThanOrEqual(1)->and($c['allocation']['max_total_units'])->toBe(2_000_000_000)->and($c['periods']['finalize_delay_hours'])->toBeGreaterThanOrEqual(0)
        ->and(array_keys($c['payout']['methods']))->toBe(['bank_transfer', 'e_wallet', 'cash_pickup', 'other'])->and(array_keys($c['adjustments']['reasons']))->toBe(['correction', 'goodwill', 'clawback', 'dispute', 'other'])
        ->and($c['payout']['require_separate_settler'])->toBeBool();

    $source = file_get_contents(config_path('creator_monetization.php'));

    foreach (['iban', 'swift', 'password', 'secret', 'api_key', 'stripe', 'paypal'] as $word) {
        expect(strtolower($source))->not->toContain($word);
    }
});

test('BA21-06: E39 adds three migrations, no package and no float or decimal column', function () {
    $migrations = glob(database_path('migrations/2026_11_15_*.php'));
    $sql = collect($migrations)->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect(count($migrations))->toBeLessThanOrEqual(5)->toBeGreaterThanOrEqual(3)->and($sql)->not->toMatch('/->(float|double|decimal|unsignedDecimal)\(/')
        ->and($sql)->not->toContain('onDelete(\'cascade\')')->not->toContain('->cascadeOnDelete()');

    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->not->toHaveKey('stripe/stripe-php')->not->toHaveKey('srmklive/paypal');
});

test('BA21-07: no E39 route mutates state through GET and every earnings route lives in the web group', function () {
    foreach (app('router')->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'creator.earnings')) {
            continue;
        }

        $mutating = str_contains($name, 'store') || str_contains($name, 'cancel');

        expect(in_array('GET', $route->methods(), true) && $mutating)->toBeFalse()->and(in_array('web', $route->gatherMiddleware(), true))->toBeTrue();
    }
});

test('BA21-08: the documentation, the runbook and the dedicated CI step are in place', function () {
    $doc = base_path('docs/creator-monetization-e39.md');
    $runbook = base_path('docs/creator-finance-operations-runbook.md');
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect(file_exists($doc))->toBeTrue()->and(file_exists($runbook))->toBeTrue()->and(file_get_contents($runbook))->toContain('creator-finance:reconcile')->toContain('creator-revenue:finalize')
        ->and(file_get_contents($doc))->toContain('SponsorshipPayment')->and($ci)->toContain('E39 creator finance on MySQL')->toContain('tests/Feature/CreatorFinanceE39/E39MysqlSchemaTest.php');
});

test('BA21-09: staff cannot reach the services without permission even when the page is skipped entirely', function () {
    [$creator, , $account, $period] = e39_funded_creator(3);
    $nobody = e39_plain_staff();
    $payout = e39_request($creator, '100.00');
    $attempts = [
        fn () => e39_periods()->create($nobody, ['label' => 'x', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-05 00:00:00', 'pool' => '10.00']),
        fn () => e39_periods()->cancel($period, $nobody, 'سبب كافٍ للإلغاء هنا'),
        fn () => e39_monetization()->enable($account->creatorProfile, $nobody),
        fn () => e39_monetization()->pause($account, $nobody, 'ملاحظة كافية للإيقاف'),
        fn () => e39_monetization()->placeHold($account, $nobody, 'investigation', 'ملاحظة كافية للتعليق'),
        fn () => e39_ledger()->adjust($account, $nobody, 'credit', '5.00', 'goodwill', 'محاولة بلا صلاحية كافية'),
        fn () => e39_payouts()->startReview($payout, $nobody),
        fn () => e39_payouts()->approve($payout, $nobody),
        fn () => e39_payouts()->reject($payout, $nobody, 'محاولة رفض بلا صلاحية'),
        fn () => e39_payouts()->settle($payout, $nobody, 'REF-NOPE-9'),
    ];
    $before = e39_fingerprint();

    foreach ($attempts as $i => $call) {
        expect(e39_error($call))->not->toBeNull("attempt {$i} should be refused");
    }

    expect(e39_fingerprint())->toBe($before);
});
