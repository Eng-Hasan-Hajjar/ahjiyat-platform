<?php

use App\Models\GemIntegrityFinding;
use App\Models\GemIntegrityRun;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\Economy\Integrity\GemIntegrityRunner;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\Economy\Integrity\WalletIntegrityAuditor;
use App\Services\GemWalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E46 - تسجيل نتيجة الفحص (الكتابة الوحيدة) وأمر gems:audit: لا أثر على الأرصدة ولا الدفتر، ونتيجة آخر فحص موثوق صحيحة.
*/

beforeEach(function () {
    $this->gems = app(GemWalletService::class);
    $this->gem = e46_gem();
    $this->runner = app(GemIntegrityRunner::class);
});

function e46_world(): array
{
    $good = User::factory()->create();
    $bad = User::factory()->create();
    $opening = User::factory()->create();
    app(GemWalletService::class)->credit($good, 10, 'solved_puzzle:1');
    app(GemWalletService::class)->credit($bad, 20, 'solved_puzzle:2');
    e46_age_everything();
    e46_tamper($bad, e46_gem(), ['pending_balance' => 5]);
    e46_tamper($opening, e46_gem(), ['available_balance' => 300, 'lifetime_earned' => 300]);

    return [$good, $bad, $opening];
}

test('E46-30: a run stores exact counters and only the non-matched cases, and never touches wallets or the ledger', function () {
    [$good, $bad, $opening] = e46_world();
    $before = [DB::table('wallets')->get()->toArray(), DB::table('currency_transactions')->get()->toArray()];

    $run = $this->runner->run(e46_scope(null, [$this->gem->id]));

    expect($run->status)->toBe(GemIntegrityRun::STATUS_COMPLETED)
        ->and($run->scope_kind)->toBe('currencies')
        ->and($run->scanned)->toBe(3)->and($run->matched)->toBe(1)
        ->and($run->proven_differences)->toBe(1)->and($run->undetermined)->toBe(1)
        ->and($run->reason_counts)->toBe([WalletAuditCase::REASON_NO_LEDGER => 1])
        ->and($run->findings_stored)->toBe(2)->and($run->findings_truncated)->toBeFalse()
        ->and($run->finished_at)->not->toBeNull()
        ->and(GemIntegrityFinding::query()->where('run_id', $run->id)->pluck('verdict', 'user_id')->all())
        ->toBe([$bad->id => 'proven_difference', $opening->id => 'undetermined'])
        ->and(DB::table('wallets')->get()->toArray())->toEqual($before[0])
        ->and(DB::table('currency_transactions')->get()->toArray())->toEqual($before[1]);

    $finding = GemIntegrityFinding::query()->where('user_id', $bad->id)->first();
    expect($finding->diffPending())->toBe(-15)->and($finding->diffAvailable())->toBe(0);
});

test('E46-31: the last TRUSTED result is the latest completed full-coverage run; user-scoped and failed runs never count; "never run" is null (not zero)', function () {
    expect(GemIntegrityRun::lastTrusted())->toBeNull();

    [$good, $bad] = e46_world();

    $this->runner->run(e46_scope($good->id, [$this->gem->id]));                 // نطاق مستخدم واحد: ليس موثوقًا
    expect(GemIntegrityRun::lastTrusted())->toBeNull();

    $trusted = $this->runner->run(e46_scope(null, [$this->gem->id]));
    expect(GemIntegrityRun::lastTrusted()->id)->toBe($trusted->id);

    $explode = new class extends WalletIntegrityAuditor
    {
        public function audit(\App\Services\Economy\Integrity\WalletAuditScope $scope, ?callable $onCase = null): \App\Services\Economy\Integrity\WalletAuditSummary
        {
            throw new RuntimeException('boom');
        }
    };
    $runner = new GemIntegrityRunner($explode);

    expect(fn () => $runner->run(e46_scope(null, [$this->gem->id])))->toThrow(RuntimeException::class, 'boom');

    $failed = GemIntegrityRun::query()->latest('id')->first();
    expect($failed->status)->toBe(GemIntegrityRun::STATUS_FAILED)->and($failed->error)->toContain('boom')
        ->and(GemIntegrityRun::lastTrusted()->id)->toBe($trusted->id);              // الفاشل لا يحلّ محلّ الموثوق
});

test('E46-32: stored findings are capped per verdict while the counters stay exact and the truncation is recorded', function () {
    $capped = new class(app(WalletIntegrityAuditor::class)) extends GemIntegrityRunner
    {
        public const MAX_FINDINGS_PER_VERDICT = 2;
    };

    foreach (User::factory()->count(5)->create() as $user) {
        $this->gems->credit($user, 10, 'solved_puzzle:x');
    }

    e46_age_everything();

    foreach (User::all() as $user) {
        e46_tamper($user, $this->gem, ['pending_balance' => 1]);
    }

    $run = $capped->run(e46_scope(null, [$this->gem->id]));

    expect($run->proven_differences)->toBe(5)->and($run->findings_stored)->toBe(2)->and($run->findings_truncated)->toBeTrue()
        ->and(GemIntegrityFinding::query()->where('run_id', $run->id)->count())->toBe(2);
});

test('E46-33: only the newest KEEP_RUNS runs are kept and their findings go with the old ones', function () {
    [$good, $bad] = e46_world();

    foreach (range(1, 4) as $i) {
        $this->runner->run(e46_scope(null, [$this->gem->id]));
    }

    $deleted = $this->runner->prune(2);

    expect($deleted)->toBe(2)->and(GemIntegrityRun::count())->toBe(2)
        ->and(GemIntegrityFinding::query()->whereNotIn('run_id', GemIntegrityRun::pluck('id'))->count())->toBe(0);
});

// ───────────── الأمر ─────────────

test('E46-34: gems:audit --no-save --json on clean data: exit 0, read-only flag, nothing saved', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    e46_age_everything();

    $exit = Artisan::call('gems:audit', ['--no-save' => true, '--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)->and($json['ok'])->toBeTrue()->and($json['read_only'])->toBeTrue()
        ->and($json['saved_run_id'])->toBeNull()->and($json['summary']['proven_differences'])->toBe(0)
        ->and(GemIntegrityRun::count())->toBe(0);
});

test('E46-35: gems:audit exits 1 only for a PROVEN difference (undetermined cases are shown but do not fail), saves the run and logs one operational entry', function () {
    [$good, $bad, $opening] = e46_world();

    $exit = Artisan::call('gems:audit', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)->and($json['ok'])->toBeFalse()
        ->and($json['summary']['proven_differences'])->toBe(1)->and($json['summary']['undetermined'])->toBe(1)
        ->and(array_column($json['cases'], 'verdict', 'user_id'))->toBe([$bad->id => 'proven_difference', $opening->id => 'undetermined'])
        ->and($json['saved_run_id'])->toBe(GemIntegrityRun::first()->id)
        ->and(OperationalAuditLog::where('action', 'gem_integrity_scan')->count())->toBe(1);

    // لا يوجد أي أمر إصلاح: لم يتغيّر رصيد المتأثر
    expect(e46_wallet($bad, $this->gem)->pending_balance)->toBe(5);

    // الحالات «لا يمكن الجزم» وحدها لا تُفشل الأمر
    e46_tamper($bad, $this->gem, ['pending_balance' => 20]);
    expect(Artisan::call('gems:audit', ['--json' => true, '--no-save' => true]))->toBe(0);
});

test('E46-36: the default scope is the default earned currency only; --currency and --all-currencies reach other currencies', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    app(\App\Services\Economy\CurrencyWalletService::class)->creditPending($user, $event, 30, 'campaign_reward');
    e46_age_everything();
    e46_tamper($user, $event, ['pending_balance' => 31]);

    expect(Artisan::call('gems:audit', ['--json' => true, '--no-save' => true]))->toBe(0);
    expect(Artisan::call('gems:audit', ['--json' => true, '--no-save' => true, '--currency' => [$event->internal_key]]))->toBe(1);
    expect(Artisan::call('gems:audit', ['--json' => true, '--no-save' => true, '--all-currencies' => true]))->toBe(1);
    expect(json_decode((function () {
        Artisan::call('gems:audit', ['--json' => true, '--no-save' => true, '--all-currencies' => true]);

        return Artisan::output();
    })(), true)['summary']['scanned'])->toBe(2);
});

test('E46-37: gems:audit rejects bad input without scanning', function () {
    expect(Artisan::call('gems:audit', ['--user' => 'abc', '--no-save' => true]))->toBe(2)
        ->and(Artisan::call('gems:audit', ['--currency' => ['no-such-currency'], '--no-save' => true]))->toBe(2)
        ->and(GemIntegrityRun::count())->toBe(0);
});

test('E46-38: a user-scoped run through the command is saved but is not the "trusted" last scan', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    e46_age_everything();

    Artisan::call('gems:audit', ['--user' => (string) $user->id, '--json' => true]);

    expect(GemIntegrityRun::count())->toBe(1)->and(GemIntegrityRun::first()->scope_user_id)->toBe($user->id)
        ->and(GemIntegrityRun::lastTrusted())->toBeNull();
});
