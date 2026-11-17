<?php

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Economy\Integrity\RedemptionCommitments;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\RedemptionService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E46 - الالتزامات المرتبطة بالاستبدال: ما يمكن إثباته من redemption_requests مقابل الدفتر، بلا «ميزانية» مخمَّنة وبلا عدّ مزدوج.
*/

beforeEach(function () {
    $this->commitments = app(RedemptionCommitments::class);
    $this->redemptions = app(RedemptionService::class);
    $this->admin = User::factory()->admin()->create();
});

test('E46-60: with no requests the summary is empty, the budget is null (never zero) and every footprint is zero', function () {
    $summary = $this->commitments->summary();

    expect($summary['budget'])->toBeNull()
        ->and($summary['currencies'])->toBe([])
        ->and($summary['footprints'])->toBe(['duplicate_refunds' => 0, 'rejected_without_refund' => 0, 'refunded_but_not_rejected' => 0]);
});

test('E46-61: only open requests (pending/approved) are committed, grouped per currency, and each real request is backed by exactly one debit row', function () {
    $event = Currency::factory()->create(['is_redeemable' => true]);

    $pending = e46_request(e46_eligible_user());
    $approved = e46_request(e46_eligible_user());
    $this->redemptions->approve($approved, $this->admin);
    $fulfilled = e46_request(e46_eligible_user());
    $this->redemptions->approve($fulfilled, $this->admin);
    $this->redemptions->markFulfilled($fulfilled->fresh(), $this->admin);
    $rejected = e46_request(e46_eligible_user());
    $this->redemptions->reject($rejected, $this->admin, 'no');
    $eventOpen = e46_request(e46_eligible_user(), $event, (int) config('gems.min_redemption'));

    $summary = $this->commitments->summary();
    $min = (int) config('gems.min_redemption');

    expect(array_keys($summary['currencies']))->toBe([e46_gem()->id, $event->id])
        ->and($summary['currencies'][e46_gem()->id])->toBe([
            'currency_id' => e46_gem()->id, 'open_requests' => 2, 'open_gems' => 2 * $min, 'backed_requests' => 2, 'unbacked_requests' => 0,
        ])
        ->and($summary['currencies'][$event->id])->toBe([
            'currency_id' => $event->id, 'open_requests' => 1, 'open_gems' => $min, 'backed_requests' => 1, 'unbacked_requests' => 0,
        ]);
});

test('E46-62: no double counting: committed gems are NOT part of any wallet balance and the wallet still matches its ledger', function () {
    $user = e46_eligible_user();
    $request = e46_request($user);
    $min = (int) config('gems.min_redemption');

    $wallet = e46_wallet($user, e46_gem());
    e46_age_everything();
    $case = e46_case($user, e46_gem());

    expect($wallet->available_balance)->toBe(0)                       // خُصمت لحظة الطلب
        ->and($this->commitments->summary()['currencies'][e46_gem()->id]['open_gems'])->toBe($min)
        ->and((int) CurrencyTransaction::query()->where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_REDEEM)->sum('amount'))->toBe(-$min)   // MySQL يعيد SUM نصًّا
        ->and($case)->not->toBeNull()
        ->and($case->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

test('E46-63: an open request with no matching debit row is shown as unbacked, never silently counted as backed', function () {
    $user = e46_eligible_user();
    RedemptionRequest::create([
        'user_id' => $user->id, 'currency_id' => e46_gem()->id, 'gems_amount' => 10000, 'reward_description' => 'x', 'status' => RedemptionRequest::STATUS_PENDING,
    ]);

    $row = $this->commitments->summary()['currencies'][e46_gem()->id];

    expect($row['open_requests'])->toBe(1)->and($row['backed_requests'])->toBe(0)->and($row['unbacked_requests'])->toBe(1);
});

test('E46-64: a debit row with a different amount or user does not make a request "backed"', function () {
    $user = e46_eligible_user();
    $other = User::factory()->create();
    $request = RedemptionRequest::create([
        'user_id' => $user->id, 'currency_id' => e46_gem()->id, 'gems_amount' => 10000, 'reward_description' => 'x', 'status' => RedemptionRequest::STATUS_PENDING,
    ]);
    $morph = $request->getMorphClass();

    foreach ([[$user->id, -9000], [$other->id, -10000]] as [$uid, $amount]) {
        DB::table('currency_transactions')->insert([
            'user_id' => $uid, 'currency_id' => e46_gem()->id, 'amount' => $amount, 'type' => 'redeem', 'reason' => 'redemption_request:'.$request->id,
            'reference_type' => $morph, 'reference_id' => $request->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect($this->commitments->summary()['currencies'][e46_gem()->id]['backed_requests'])->toBe(0);
});

test('E46-65: footprints stay at zero for a clean reject and a clean fulfil', function () {
    $rejected = e46_request(e46_eligible_user());
    $this->redemptions->reject($rejected, $this->admin, 'no');

    $fulfilled = e46_request(e46_eligible_user());
    $this->redemptions->approve($fulfilled, $this->admin);
    $this->redemptions->markFulfilled($fulfilled->fresh(), $this->admin);

    expect($this->commitments->summary()['footprints'])->toBe(['duplicate_refunds' => 0, 'rejected_without_refund' => 0, 'refunded_but_not_rejected' => 0]);
});

test('E46-66: a rejected request with no refund row is reported (rejected_without_refund)', function () {
    $request = e46_request(e46_eligible_user());
    DB::table('redemption_requests')->where('id', $request->id)->update(['status' => RedemptionRequest::STATUS_REJECTED]);

    expect($this->commitments->summary()['footprints'])->toBe(['duplicate_refunds' => 0, 'rejected_without_refund' => 1, 'refunded_but_not_rejected' => 0]);
});

test('E46-67: the summary is strictly read-only', function () {
    e46_request(e46_eligible_user());
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    $this->commitments->summary();

    expect(array_values(array_filter($statements, fn ($s) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b|for update/i', $s) === 1)))->toBe([]);
});
