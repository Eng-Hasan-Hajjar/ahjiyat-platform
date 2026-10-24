<?php

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\AhjiyatPass\PassDefinitionCache;
use App\Services\AhjiyatPass\PassReconciler;
use App\Services\AhjiyatPass\PassReminderService;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use Database\Seeders\DemoE40Seeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E40 / العمليات: أمر المصالحة، أمر الصيانة المجدول، التذكيرات، الإشعارات، الكاش، الـSeeder، الإعدادات، والصلاحيات.
*/

beforeEach(fn () => e40_seed());

/** [exit code, output] */
function e40_artisan(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

function e40_ledger_fingerprint(): string
{
    $parts = [];

    foreach (['pass_point_grants', 'user_pass_reward_claims', 'digital_purchases', 'digital_purchase_events', 'inventory_transactions'] as $table) {
        $parts[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    return md5(json_encode($parts));
}

function e40_report_counts(): array
{
    return app(PassReconciler::class)->run()['counts'];
}

function e40_ops_world(): array
{
    $b = e40_build(['tiers' => 6, 'step' => 100, 'free_tiers' => range(1, 6), 'premium_tiers' => range(1, 6), 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_reach($user, $b, 300);
    $order = e40_buy_premium($user, $b['pass'], $b['admin']);
    e40_claims()->collectAll($user, $b['pass']);

    return [$b, $user, $order];
}

// ───────────────────────────── المصالحة ─────────────────────────────

test('OPS-01: reconcile is clean on a healthy world and exits 0', function () {
    e40_ops_world();

    [$code, $out] = e40_artisan('ahjiyat-pass:reconcile');

    expect($code)->toBe(0)->and($out)->toContain('متسقة')->and(app(PassReconciler::class)->run()['findings'])->toBe([]);
});

test('OPS-02: reconcile is read-only by default — a drift is reported, exit code 1, nothing is changed', function () {
    [$b, $user] = e40_ops_world();
    DB::table('user_pass_progress')->where('user_id', $user->id)->update(['pass_points' => 9999]);
    $before = [e40_ledger_fingerprint(), DB::table('user_pass_progress')->get()->map(fn ($r) => (array) $r)->all()];

    [$code, $out] = e40_artisan('ahjiyat-pass:reconcile');

    expect($code)->toBe(1)->and($out)->toContain('points_mismatch')
        ->and([e40_ledger_fingerprint(), DB::table('user_pass_progress')->get()->map(fn ($r) => (array) $r)->all()])->toBe($before);
});

test('OPS-03: every drift class has its own code and severity', function () {
    // 1) النقاط ≠ الدفتر + الشريحة ≠ المتوقعة
    [$b, $user, $order] = e40_ops_world();
    $progress = DB::table('user_pass_progress')->where('user_id', $user->id)->first();
    DB::table('user_pass_progress')->where('id', $progress->id)->update(['pass_points' => 5, 'highest_tier' => 3]);
    expect(e40_report_counts())->toHaveKey('points_mismatch')->toHaveKey('tier_mismatch')->toHaveKey('claim_above_tier');

    DB::table('user_pass_progress')->where('id', $progress->id)->update(['pass_points' => $progress->pass_points, 'highest_tier' => $progress->highest_tier]);
    expect(e40_report_counts())->toBe([]);

    // 2) مميز بلا شراء محقَّق (حرج)
    DB::table('digital_purchases')->where('id', $order->id)->update(['status' => 'awaiting_payment', 'open_key' => 'x:'.$order->id]);
    $counts = e40_report_counts();
    expect($counts)->toHaveKey('premium_without_verified_purchase')->toHaveKey('premium_claim_without_purchase')->not->toHaveKey('verified_purchase_not_unlocked');
});

test('OPS-04: a verified purchase that never unlocked premium is a critical finding', function () {
    [$b, $user, $order] = e40_ops_world();
    DB::table('user_pass_progress')->where('user_id', $user->id)->update(['premium_unlocked_at' => null]);

    $report = app(PassReconciler::class)->run();
    $codes = collect($report['findings'])->keyBy('code');

    expect($codes)->toHaveKey('verified_purchase_not_unlocked')->and($codes['verified_purchase_not_unlocked']['severity'])->toBe('critical');
});

test('OPS-05: orphan claims, outside-window points, reused proofs, tier counts and unreachable tiers are detected', function () {
    [$b, $user] = e40_ops_world();

    DB::table('user_pass_reward_claims')->where('user_id', $user->id)->orderBy('id')->limit(1)->update(['inventory_transaction_id' => null, 'user_entitlement_id' => null]);
    DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->update(['total_tiers' => 3]);
    DB::table('ahjiyat_pass_missions')->where('ahjiyat_pass_id', $b['pass']->id)->update(['pass_points' => 1, 'max_completions' => 1]);
    Cache::flush();

    $a = e40_player();
    $c = e40_player();
    $sha = str_repeat('a', 64);
    foreach ([$a, $c] as $u) {
        $o = e40_premium()->submit($u, e40_premium()->request($u, $b['pass']), 'TRX-R-'.$u->id, null);
        DB::table('digital_purchases')->where('id', $o->id)->update(['proof_sha256' => $sha]);
    }

    $counts = e40_report_counts();

    expect($counts)->toHaveKey('claim_unlinked')->toHaveKey('tier_count_mismatch')->toHaveKey('unreachable_tiers')->toHaveKey('proof_reused');

    $outside = DB::table('pass_point_grants')->whereNotNull('user_quest_progress_id')->first();
    DB::table('user_quest_progress')->where('id', $outside->user_quest_progress_id)->update(['completed_at' => now()->subYears(2)]);

    expect(e40_report_counts())->toHaveKey('grant_outside_window');
});

test('OPS-06: two live passes at once are flagged critical (multiple_active) even though the database guard normally prevents it', function () {
    $b = e40_build();
    $other = e40_build(['key' => 'second-pass', 'publish' => false]);
    DB::table('ahjiyat_passes')->where('id', $other['pass']->id)->update(['locked_at' => now(), 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'claim_ends_at' => now()->addDays(15)]);

    $codes = collect(app(PassReconciler::class)->run()['findings'])->keyBy('code');

    expect($codes)->toHaveKey('multiple_active')->and($codes['multiple_active']['severity'])->toBe('critical');
});

test('OPS-07: --repair rebuilds only derived columns (pass_points, highest_tier) and never touches the ledger, claims or purchases; a second run is clean', function () {
    [$b, $user] = e40_ops_world();
    $real = DB::table('user_pass_progress')->where('user_id', $user->id)->first();
    DB::table('user_pass_progress')->where('user_id', $user->id)->update(['pass_points' => 17, 'highest_tier' => 1]);
    $ledger = e40_ledger_fingerprint();

    [$code, $out] = e40_artisan('ahjiyat-pass:reconcile', ['--repair' => true]);
    $row = DB::table('user_pass_progress')->where('user_id', $user->id)->first();

    expect($row->pass_points)->toBe($real->pass_points)->and($row->highest_tier)->toBe($real->highest_tier)->and(e40_ledger_fingerprint())->toBe($ledger)
        ->and($out)->toContain('أُصلح');

    [$again, $outAgain] = e40_artisan('ahjiyat-pass:reconcile');
    expect($again)->toBe(0)->and($outAgain)->toContain('متسقة');
});

test('OPS-08: --repair does not "fix" the critical drifts — premium ownership and claims stay as evidence for a human', function () {
    [$b, $user, $order] = e40_ops_world();
    DB::table('user_pass_progress')->where('user_id', $user->id)->update(['premium_unlocked_at' => null]);
    $purchases = DB::table('digital_purchases')->get()->map(fn ($r) => (array) $r)->all();
    $claims = DB::table('user_pass_reward_claims')->count();

    [$code] = e40_artisan('ahjiyat-pass:reconcile', ['--repair' => true]);

    expect($code)->toBe(1)->and(DB::table('digital_purchases')->get()->map(fn ($r) => (array) $r)->all())->toBe($purchases)->and(DB::table('user_pass_reward_claims')->count())->toBe($claims)
        ->and(DB::table('user_pass_progress')->where('user_id', $user->id)->value('premium_unlocked_at'))->toBeNull();
});

test('OPS-09: the number of findings is capped and flagged as truncated', function () {
    config(['ahjiyat_pass.reconcile.max_findings' => 3]);
    $b = e40_build();

    foreach (range(1, 6) as $_) {
        $u = e40_player($b['pass']);
        DB::table('user_pass_progress')->where('user_id', $u->id)->update(['pass_points' => 77]);
    }

    $report = app(PassReconciler::class)->run();

    expect($report['findings'])->toHaveCount(3)->and($report['truncated'])->toBeTrue()->and($report['counts']['points_mismatch'])->toBeGreaterThan(3);
});

// ───────────────────────────── الصيانة المجدولة ─────────────────────────────

test('OPS-10: the maintenance command is scheduled every 15 minutes and nothing else about the pass is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'ahjiyat-pass'));

    expect($events)->toHaveCount(1)->and((string) $events->first()->command)->toContain('ahjiyat-pass:process')->and($events->first()->expression)->toBe('*/15 * * * *');

    $console = file_get_contents(base_path('routes/console.php'));
    expect($console)->not->toContain("Schedule::command('ahjiyat-pass:reconcile")->not->toContain('--repair');
});

test('OPS-11: process activates a scheduled pass at its start, ends it at its end, and is idempotent', function () {
    $b = e40_build(['starts' => now()->addDay(), 'days' => 30]);
    $id = $b['pass']->id;

    expect($b['pass']->fresh()->status)->toBe('scheduled');

    Carbon::setTestNow(now()->addDay()->addHour());
    [$code, $out] = e40_artisan('ahjiyat-pass:process');
    expect($code)->toBe(0)->and($out)->toContain('فُعِّل: 1')->and(AhjiyatPass::query()->find($id)->status)->toBe('active')->and(DB::table('ahjiyat_passes')->where('id', $id)->value('live_slot'))->toBe(1);

    [, $again] = e40_artisan('ahjiyat-pass:process');
    expect($again)->toContain('فُعِّل: 0');

    Carbon::setTestNow($b['pass']->ends_at->copy()->addMinute());
    [, $ended] = e40_artisan('ahjiyat-pass:process');
    expect($ended)->toContain('انتهى: 1')->and(AhjiyatPass::query()->find($id)->status)->toBe('ended')->and(DB::table('ahjiyat_passes')->where('id', $id)->value('live_slot'))->toBeNull();

    [, $final] = e40_artisan('ahjiyat-pass:process');
    expect($final)->toContain('انتهى: 0');
});

test('OPS-12: process expires awaiting-payment orders after their window, and never touches submitted or verified ones', function () {
    $b = e40_build();
    $waiting = e40_player();
    $submitted = e40_player();
    $verified = e40_player();
    $w = e40_premium()->request($waiting, $b['pass']);
    $s = e40_premium()->submit($submitted, e40_premium()->request($submitted, $b['pass']), 'TRX-S-1', null);
    $v = e40_buy_premium($verified, $b['pass'], $b['admin']);

    [, $early] = e40_artisan('ahjiyat-pass:process');
    expect($early)->toContain('طلبات انتهت صلاحيتها: 0')->and($w->fresh()->status)->toBe('awaiting_payment');

    Carbon::setTestNow(now()->addHours(49));
    [, $late] = e40_artisan('ahjiyat-pass:process');

    expect($late)->toContain('طلبات انتهت صلاحيتها: 1')->and($w->fresh()->status)->toBe('expired')->and($w->fresh()->open_key)->toBeNull()
        ->and($s->fresh()->status)->toBe('submitted')->and($v->fresh()->status)->toBe('verified')
        ->and(e40_row($verified, $b['pass'])->premium_unlocked_at)->not->toBeNull();

    // الطلب المنتهي يسمح بطلب جديد.
    expect(e40_premium()->request($waiting->fresh(), $b['pass'])->status)->toBe('awaiting_payment');
});

test('OPS-13: process never grants points, premium, claims or value', function () {
    [$b, $user] = e40_ops_world();
    $before = e40_counts() + e40_economy($user);
    $foreign = e40_foreign_counts();

    Carbon::setTestNow(now()->addDays(40));
    e40_artisan('ahjiyat-pass:process');
    e40_artisan('ahjiyat-pass:process');

    expect(e40_counts() + e40_economy($user))->toBe($before)->and(e40_foreign_counts())->toBe($foreign);
});

// ───────────────────────────── التذكيرات ─────────────────────────────

/** مسار انتهى وبقيت ساعات قليلة على إغلاق مهلة الاستلام. */
function e40_in_closing_window(array $o = []): array
{
    $b = e40_build($o + ['tiers' => 4, 'step' => 100, 'free_tiers' => [1, 2, 3, 4], 'premium_tiers' => [1], 'grace' => 5, 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);

    return $b;
}

function e40_travel_to_closing(AhjiyatPass $pass, int $hoursLeft = 24): void
{
    Carbon::setTestNow($pass->claim_ends_at->copy()->subHours($hoursLeft));
}

test('OPS-14: the claim-window reminder reaches only players with something claimable, once, never the frozen', function () {
    $b = e40_in_closing_window(['free_tiers' => [2, 3, 4]]);
    $claimable = e40_player();
    e40_reach($claimable, $b, 200);
    $done = e40_player();
    e40_reach($done, $b, 100);
    e40_claims()->collectAll($done, $b['pass']);
    $none = e40_player($b['pass']);
    $frozen = e40_player();
    e40_reach($frozen, $b, 200);
    $frozen->forceFill(['is_frozen' => true])->save();

    e40_travel_to_closing($b['pass']);
    $first = app(PassReminderService::class)->run();
    $second = app(PassReminderService::class)->run();

    expect($first['passes'])->toBe(1)->and($first['sent'])->toBe(1)
        ->and(e40_notes($claimable, 'pass_claim_window_closing'))->toHaveCount(1)->and(e40_notes($done, 'pass_claim_window_closing'))->toHaveCount(0)
        ->and(e40_notes($none, 'pass_claim_window_closing'))->toHaveCount(0)->and(e40_notes($frozen, 'pass_claim_window_closing'))->toHaveCount(0)
        ->and($second['sent'])->toBe(0);
});

test('OPS-15: no reminder before the closing window, none after it closes, and none while the pass is still running', function () {
    $b = e40_in_closing_window();
    $user = e40_player();
    e40_reach($user, $b, 200);

    expect(app(PassReminderService::class)->run()['passes'])->toBe(0);

    Carbon::setTestNow($b['pass']->ends_at->copy()->addDay());
    expect(app(PassReminderService::class)->run()['passes'])->toBe(0);

    Carbon::setTestNow($b['pass']->claim_ends_at->copy()->addMinute());
    expect(app(PassReminderService::class)->run()['passes'])->toBe(0)->and(e40_notes($user, 'pass_claim_window_closing'))->toHaveCount(0);
});

test('OPS-16: reminders are scanned in bounded batches with a cursor — nobody is skipped and nobody gets two', function () {
    config(['ahjiyat_pass.notifications.reminder_batch' => 10]);
    $b = e40_in_closing_window();
    $users = collect(range(1, 25))->map(function () use ($b) {
        $u = e40_player();
        e40_reach($u, $b, 200);

        return $u;
    });

    e40_travel_to_closing($b['pass']);
    $runs = [];

    for ($i = 0; $i < 5; $i++) {
        $runs[] = app(PassReminderService::class)->run();
    }

    expect($runs[0]['scanned'])->toBe(10)->and($runs[1]['scanned'])->toBe(10)->and($runs[2]['scanned'])->toBe(5)->and(array_sum(array_column($runs, 'sent')))->toBe(25)
        ->and(e40_notes(null, 'pass_claim_window_closing'))->toHaveCount(25)
        ->and($users->every(fn ($u) => e40_notes($u, 'pass_claim_window_closing')->count() === 1))->toBeTrue();
});

test('OPS-17: reminders respect the notification kill switch and the global pass kill switch', function () {
    $b = e40_in_closing_window();
    $user = e40_player();
    e40_reach($user, $b, 200);
    e40_travel_to_closing($b['pass']);

    config(['ahjiyat_pass.notifications.enabled' => false]);
    expect(app(PassReminderService::class)->run())->toBe(['passes' => 0, 'scanned' => 0, 'sent' => 0]);

    config(['ahjiyat_pass.notifications.enabled' => true, 'ahjiyat_pass.enabled' => false]);
    expect(app(PassReminderService::class)->run())->toBe(['passes' => 0, 'scanned' => 0, 'sent' => 0]);

    config(['ahjiyat_pass.enabled' => true]);
    expect(app(PassReminderService::class)->run()['sent'])->toBe(1);
});

// ───────────────────────────── الإشعارات ─────────────────────────────

test('OPS-18: pass notifications carry no amounts, references or internal notes, link to the pass page and are not duplicated', function () {
    $b = e40_build(['price' => 1999, 'currency' => 'USD']);
    $buyer = e40_player();
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-NOTIFY-77', 'ملاحظة المشتري');
    e40_premium()->verify($b['admin'], $order, 'ملاحظة مراجعة سرية');
    $rejected = e40_player();
    $rejectedOrder = e40_premium()->submit($rejected, e40_premium()->request($rejected, $b['pass']), 'TRX-NOTIFY-78', null);
    e40_premium()->reject($b['admin'], $rejectedOrder, 'المرجع غير مطابق.', 'سبب داخلي');

    $notes = \Illuminate\Notifications\DatabaseNotification::query()->get();
    $all = json_encode($notes->map(fn ($n) => $n->data)->all(), JSON_UNESCAPED_UNICODE);

    expect(e40_notes($buyer, 'pass_premium_unlocked'))->toHaveCount(1)->and(e40_notes($rejected, 'pass_purchase_rejected'))->toHaveCount(1)
        ->and($all)->not->toContain('TRX-NOTIFY')->not->toContain('19.99')->not->toContain('1999')->not->toContain($order->reference_code)->not->toContain('ملاحظة مراجعة سرية')->not->toContain('سبب داخلي')
        ->and($notes->every(fn ($n) => str_contains((string) json_encode($n->data), 'pass')))->toBeTrue();

    // إعادة التحقق/الرفض لا تكرّر الإشعار (idempotency).
    app(\App\Services\AhjiyatPass\PassNotifier::class)->premiumUnlocked($order->fresh());
    expect(e40_notes($buyer, 'pass_premium_unlocked'))->toHaveCount(1);
});

test('OPS-19: a failing notification system never blocks or rolls back verification', function () {
    $this->mock(NotificationDispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('notification backend down')));
    $b = e40_build();
    $buyer = e40_player();
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-DOWN-1', null);

    $verified = e40_premium()->verify($b['admin'], $order);

    expect($verified->status)->toBe('verified')->and(e40_row($buyer, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});

test('OPS-20: a frozen buyer receives no pass notification', function () {
    $b = e40_build();
    $buyer = e40_player();
    $order = e40_buy_premium($buyer, $b['pass'], $b['admin']);
    \Illuminate\Notifications\DatabaseNotification::query()->delete();
    $buyer->forceFill(['is_frozen' => true])->save();

    app(\App\Services\AhjiyatPass\PassNotifier::class)->premiumUnlocked($order->fresh());
    app(\App\Services\AhjiyatPass\PassNotifier::class)->purchaseRejected($order->fresh());

    expect(e40_notes($buyer, 'pass_premium_unlocked'))->toHaveCount(0)->and(e40_notes($buyer, 'pass_purchase_rejected'))->toHaveCount(0)
        ->and(e40_reason(function () use ($b) {
            $other = e40_player();
            $o = e40_premium()->submit($other, e40_premium()->request($other, $b['pass']), 'TRX-FROZEN-V', null);
            $other->forceFill(['is_frozen' => true])->save();
            e40_premium()->verify($b['admin'], $o);
        }))->toBe('frozen');
});

// ───────────────────────────── الكاش ─────────────────────────────

test('OPS-21: the cache stores definitions only — never points, claims or premium ownership — and builder or lifecycle changes invalidate it', function () {
    [$b, $user] = e40_ops_world();
    $cache = app(PassDefinitionCache::class);
    $this->actingAs($user)->get(route('pass.index'))->assertSuccessful();
    $version = $cache->version();

    $track = $cache->track($b['pass']);
    $serialized = json_encode($track->toArray());

    expect($serialized)->not->toContain('pass_points')->not->toContain('premium_unlocked')->not->toContain('claimed')->not->toContain((string) $user->email);

    e40_life()->end($b['admin'], $b['pass'], 'اختبار الإبطال');

    expect($cache->version())->toBeGreaterThan($version)->and($cache->current())->toBeNull();
});

test('OPS-22: a stale cache never serves an ended pass nor hides a started one (time-bounded validity)', function () {
    $b = e40_build(['starts' => now()->addHours(2), 'days' => 30]);
    $cache = app(PassDefinitionCache::class);

    expect($cache->current())->toBeNull();

    Carbon::setTestNow(now()->addHours(3));
    expect($cache->current()?->id)->toBe($b['pass']->id);

    Carbon::setTestNow($b['pass']->ends_at->copy()->addSecond());
    expect($cache->current())->toBeNull();
});

// ───────────────────────────── Seeder ─────────────────────────────

test('OPS-23: the demo seeder refuses production and creates nothing', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(DemoE40Seeder::class)->run())->toThrow(RuntimeException::class);
    expect(AhjiyatPass::query()->count())->toBe(0)->and(User::query()->where('email', DemoE40Seeder::ADMIN_EMAIL)->exists())->toBeFalse();
});

test('OPS-24: the demo seeder builds a complete pass, is idempotent and reconciles clean', function () {
    $this->seed(DemoE40Seeder::class);
    $first = e40_counts();
    $this->seed(DemoE40Seeder::class);

    $pass = AhjiyatPass::query()->where('internal_key', DemoE40Seeder::PASS_KEY)->first();
    $premium = User::query()->where('email', DemoE40Seeder::PREMIUM_EMAIL)->first();

    expect(e40_counts())->toBe($first)->and($first['tiers'])->toBe(24)->and($first['rewards'])->toBe(25)->and($first['missions'])->toBe(6)->and($first['purchases'])->toBe(1)
        ->and($pass->status)->toBe('active')->and($pass->locked_at)->not->toBeNull()
        ->and(e40_row($premium, $pass)->premium_unlocked_at)->not->toBeNull()
        ->and(app(PassReconciler::class)->run()['findings'])->toBe([])
        ->and(User::query()->where('email', DemoE40Seeder::ADMIN_EMAIL)->first()->hasRole('administrator'))->toBeTrue();
});

test('OPS-25: the demo seeder grants no gems, no cash and no rewards outside the pass, and no other seeder calls it', function () {
    $snapshot = fn () => [DB::table('currency_transactions')->count(), DB::table('sponsorship_payments')->count(), DB::table('redemption_requests')->count(), DB::table('wallets')->sum('available_balance')];
    $before = $snapshot();
    $this->seed(DemoE40Seeder::class);

    expect($snapshot())->toBe($before);

    foreach (glob(database_path('seeders/*.php')) as $file) {
        if (basename($file) === 'DemoE40Seeder.php') {
            continue;
        }

        expect(file_get_contents($file))->not->toContain('DemoE40Seeder');
    }
});

// ───────────────────────────── الإعدادات والصلاحيات ─────────────────────────────

test('OPS-26: the shipped configuration is safe by default — enabled, manual provider, cosmetics-only rewards, bounded limits, admin-only grants', function () {
    $c = config('ahjiyat_pass');

    expect($c['enabled'])->toBeTrue()->and($c['premium']['provider'])->toBe('manual')->and($c['rewards']['allow_sellable_items'])->toBeFalse()
        ->and($c['rewards']['allowed_item_types'])->not->toContain('consumable')->not->toContain('physical')
        ->and($c['premium']['min_price_minor'])->toBeGreaterThan(0)->and($c['premium']['max_price_minor'])->toBeGreaterThan($c['premium']['min_price_minor'])
        ->and($c['limits']['max_reward_quantity'])->toBeLessThanOrEqual(10)->and($c['purchase']['expiry_hours'])->toBeGreaterThan(0)
        ->and($c['reconcile']['max_findings'])->toBeGreaterThan(0)->and($c['notifications']['reminder_batch'])->toBeGreaterThanOrEqual(10);
});

test('OPS-27: the eight pass permissions exist and are held by administrators only — never the broad staff or editor roles', function () {
    $expected = ['view', 'manage', 'manage-rewards', 'manage-missions', 'publish', 'view-purchases', 'verify-purchases', 'refund-purchases'];
    $group = array_keys((array) config('permissions.ahjiyat_pass.permissions'));

    expect($group)->toBe(array_map(fn ($p) => 'passes.'.$p, $expected));

    $admin = e40_admin();
    foreach ($group as $permission) {
        expect($admin->can($permission))->toBeTrue($permission);
    }

    $plain = e40_plain_staff();
    foreach ($group as $permission) {
        expect($plain->can($permission))->toBeFalse($permission);
    }

    foreach (['moderator', 'editor', 'support'] as $role) {
        $role = \Spatie\Permission\Models\Role::query()->where('name', $role)->first();

        if ($role !== null) {
            expect($role->permissions->pluck('name')->filter(fn ($n) => str_starts_with($n, 'passes.'))->all())->toBe([]);
        }
    }
});

test('OPS-28: the player pages and the pass tables are not reachable from any public or API endpoint', function () {
    $b = e40_build();
    $this->get('/pass')->assertRedirect(route('login'));

    foreach (['/api/pass', '/api/v1/pass', '/api/pass/progress'] as $url) {
        $this->getJson($url)->assertNotFound();
    }

    expect(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api') && preg_match('/pass/i', $r->uri()))->count())->toBe(0);
});

// ───────────────────────────── التوثيق ─────────────────────────────

test('OPS-29: the docs exist, cross-link each other and name every command, permission and reconcile code the code defines', function () {
    $design = file_get_contents(base_path('docs/ahjiyat-pass-e40.md'));
    $runbook = file_get_contents(base_path('docs/ahjiyat-pass-operations-runbook.md'));

    expect($design)->toContain('ahjiyat-pass-operations-runbook.md')->and($runbook)->toContain('ahjiyat-pass-e40.md')
        ->and($design.$runbook)->toContain('ahjiyat-pass:process')->toContain('ahjiyat-pass:reconcile')->toContain('--repair')->toContain('DemoE40Seeder')->toContain('AHJIYAT_PASS_ENABLED')
        ->toContain('tools/e40/concurrency-probe.php');

    foreach (array_keys((array) config('permissions.ahjiyat_pass.permissions')) as $permission) {
        expect($design)->toContain($permission);
    }

    $source = file_get_contents(base_path('app/Services/AhjiyatPass/PassReconciler.php'));
    preg_match_all("/\\\$add\\('([a-z_]+)'/", $source, $matches);

    expect(count(array_unique($matches[1])))->toBeGreaterThanOrEqual(15);

    foreach (array_unique($matches[1]) as $code) {
        expect($runbook)->toContain('`'.$code.'`');
    }

    foreach (['إثبات', 'الاحتفاظ', 'reminder_batch'] as $honestLimit) {
        expect($runbook)->toContain($honestLimit);
    }
});

test('OPS-30: the real-concurrency probe is guarded — refuses production, non-MySQL and non-test databases', function () {
    $probe = file_get_contents(base_path('tools/e40/concurrency-probe.php'));

    expect($probe)->toContain("app()->environment('production')")->toContain("['mysql', 'mariadb']")->toContain('(test|testing)')->toContain('exit(2)')
        ->and(shell_exec('php -l '.escapeshellarg(base_path('tools/e40/concurrency-probe.php')).' 2>&1'))->toContain('No syntax errors');
});
