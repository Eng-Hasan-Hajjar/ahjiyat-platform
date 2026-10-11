<?php

use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Economy\CurrencyRegistry;
use App\Services\GemWalletService;
use App\Services\PendingGemReleaseService;
use Illuminate\Support\Facades\DB;

/**
 * E24 - مهمة تحرير الجواهر المعلّقة: نفس المعادلة القديمة، لكن بدفعات مجمّعة وبلا تخطٍّ.
 * التحرير نفسه يمرّ حصرًا عبر GemWalletService (لا يُكتب الرصيد هنا).
 */
function e24_user_with_pending(int $amount, ?int $ageDays = null): User
{
    $user = User::factory()->create();
    app(GemWalletService::class)->credit($user, $amount, 'solved_puzzle:e24');

    if ($ageDays !== null) {
        CurrencyTransaction::where('user_id', $user->id)
            ->where('type', CurrencyTransaction::TYPE_EARN_PENDING)
            ->update(['created_at' => now()->subDays($ageDays)]);
    }

    return $user;
}

function e24_wallet(User $user): \App\Models\Wallet
{
    return $user->wallets()->where('currency_id', app(CurrencyRegistry::class)->defaultEarnedCurrency()->id)->firstOrFail();
}

test('E24: releases expired pending gems for every candidate even when candidates exceed the chunk size (no skipped users)', function () {
    $hold = (int) config('gems.pending_hold_days');
    $users = collect(range(1, 7))->map(fn ($i) => e24_user_with_pending(10 * $i, $hold + 1));

    $stats = app(PendingGemReleaseService::class)->run(chunk: 2);

    expect($stats['candidates'])->toBe(7)
        ->and($stats['released_users'])->toBe(7)
        ->and($stats['released_gems'])->toBe(10 * (1 + 2 + 3 + 4 + 5 + 6 + 7));

    foreach ($users as $i => $user) {
        $wallet = e24_wallet($user);
        expect($wallet->pending_balance)->toBe(0)
            ->and($wallet->available_balance)->toBe(10 * ($i + 1));
    }
});

test('E24: gems still inside the hold period stay pending', function () {
    $hold = (int) config('gems.pending_hold_days');
    $fresh = e24_user_with_pending(25, 0);
    $old = e24_user_with_pending(40, $hold + 2);

    app(PendingGemReleaseService::class)->run();

    expect(e24_wallet($fresh)->pending_balance)->toBe(25)
        ->and(e24_wallet($fresh)->available_balance)->toBe(0)
        ->and(e24_wallet($old)->available_balance)->toBe(40)
        ->and(e24_wallet($old)->pending_balance)->toBe(0);
});

test('E24: a user with one old and one recent earn releases only the old amount', function () {
    $hold = (int) config('gems.pending_hold_days');
    $user = e24_user_with_pending(10, $hold + 1);
    app(GemWalletService::class)->credit($user, 5, 'solved_puzzle:recent'); // الآن => ضمن فترة التعليق

    app(PendingGemReleaseService::class)->run();

    expect(e24_wallet($user)->available_balance)->toBe(10)
        ->and(e24_wallet($user)->pending_balance)->toBe(5);
});

test('E24: running the release twice is idempotent (second run releases nothing and writes no transaction)', function () {
    $hold = (int) config('gems.pending_hold_days');
    $user = e24_user_with_pending(30, $hold + 1);

    $first = app(PendingGemReleaseService::class)->run();
    $txAfterFirst = CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count();
    $second = app(PendingGemReleaseService::class)->run();

    expect($first['released_gems'])->toBe(30)
        ->and($second['released_gems'])->toBe(0)
        ->and($second['candidates'])->toBe(0)
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count())->toBe($txAfterFirst)
        ->and(e24_wallet($user)->available_balance)->toBe(30);
});

test('E24: the release never exceeds what is actually pending (wallet authority clamps it)', function () {
    $hold = (int) config('gems.pending_hold_days');
    $user = e24_user_with_pending(50, $hold + 1);
    // جزء من المعلّق استُهلك/عُدِّل خارج المهمة: الرصيد المعلّق الفعلي أقل من مجموع earn_pending القديم.
    e24_wallet($user)->update(['pending_balance' => 20]);

    app(PendingGemReleaseService::class)->run();

    expect(e24_wallet($user)->pending_balance)->toBe(0)
        ->and(e24_wallet($user)->available_balance)->toBe(20);
});

test('E24: aggregate queries are per chunk, not per user (2 grouped sums per chunk)', function () {
    $hold = (int) config('gems.pending_hold_days');
    foreach (range(1, 6) as $i) {
        e24_user_with_pending(10, $hold + 1);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PendingGemReleaseService::class)->run(chunk: 3);
    $sums = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'sum(amount)'))->count();
    DB::disableQueryLog();

    // 6 مستخدمين، دفعتان => استعلاما مجموع لكل دفعة = 4 (كان 12: اثنان لكل مستخدم).
    expect($sums)->toBe(4);
});

test('E24: the scheduled task is registered once under its stable name and delegates to the service', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => $e->description === 'release-pending-gems');

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
