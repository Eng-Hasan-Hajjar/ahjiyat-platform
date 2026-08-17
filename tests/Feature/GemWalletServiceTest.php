<?php

use App\Models\GemTransaction;
use App\Models\User;
use App\Services\GemWalletService;

beforeEach(function () {
    $this->wallet = app(GemWalletService::class);
    $this->user = User::factory()->create();
});

test('credit adds to pending balance and lifetime earned, and logs a transaction', function () {
    $this->wallet->credit($this->user, 30, 'solved_puzzle:1');

    $this->user->wallet->refresh();

    expect($this->user->wallet->pending_balance)->toBe(30)
        ->and($this->user->wallet->available_balance)->toBe(0)
        ->and($this->user->wallet->lifetime_earned)->toBe(30);

    expect(GemTransaction::where('user_id', $this->user->id)
        ->where('type', GemTransaction::TYPE_EARN_PENDING)
        ->where('amount', 30)
        ->exists())->toBeTrue();
});

test('releasePendingToAvailable moves gems from pending to available', function () {
    $this->wallet->credit($this->user, 50, 'solved_puzzle:1');

    $this->wallet->releasePendingToAvailable($this->user, 50, 'pending_hold_expired');

    $this->user->wallet->refresh();

    expect($this->user->wallet->pending_balance)->toBe(0)
        ->and($this->user->wallet->available_balance)->toBe(50);
});

test('releasePendingToAvailable never releases more than what is actually pending', function () {
    $this->wallet->credit($this->user, 20, 'solved_puzzle:1');

    // نطلب تحرير أكثر من المعلّق فعلياً - يجب أن يُحدَّ تلقائياً عند 20
    $this->wallet->releasePendingToAvailable($this->user, 999, 'pending_hold_expired');

    $this->user->wallet->refresh();

    expect($this->user->wallet->pending_balance)->toBe(0)
        ->and($this->user->wallet->available_balance)->toBe(20);
});

test('debitAvailable decreases available balance and logs a negative transaction', function () {
    $this->wallet->credit($this->user, 40, 'solved_puzzle:1');
    $this->wallet->releasePendingToAvailable($this->user, 40, 'pending_hold_expired');

    $this->wallet->debitAvailable($this->user, 15, 'hint:1');

    $this->user->wallet->refresh();

    expect($this->user->wallet->available_balance)->toBe(25);

    expect(GemTransaction::where('user_id', $this->user->id)
        ->where('type', GemTransaction::TYPE_REDEEM)
        ->where('amount', -15)
        ->exists())->toBeTrue();
});

test('debitAvailable throws when the available balance is insufficient', function () {
    expect(fn () => $this->wallet->debitAvailable($this->user, 100, 'hint:1'))
        ->toThrow(RuntimeException::class);

    $this->user->wallet->refresh();
    expect($this->user->wallet->available_balance)->toBe(0);
});

test('refundAvailable credits the available balance back', function () {
    $this->wallet->refundAvailable($this->user, 500, 'redemption_rejected:1');

    $this->user->wallet->refresh();
    expect($this->user->wallet->available_balance)->toBe(500);
});

test('dailyEarnedToday only sums pending-earn transactions created today', function () {
    $this->wallet->credit($this->user, 20, 'solved_puzzle:1');
    $this->wallet->credit($this->user, 10, 'solved_puzzle:2');

    // معاملة من الأمس - يجب ألا تُحتسب ضمن سقف اليوم
    GemTransaction::create([
        'user_id' => $this->user->id,
        'amount' => 999,
        'type' => GemTransaction::TYPE_EARN_PENDING,
        'reason' => 'solved_puzzle:old',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    expect($this->wallet->dailyEarnedToday($this->user))->toBe(30);
});

test('wallet operations for the same user are safe under concurrent-style sequential calls', function () {
    // نحاكي 5 عمليات إضافة متتالية على نفس المحفظة، وهي بالضبط الحالة
    // التي يحميها lockForUpdate من التعارض عند تزامن حقيقي عبر عدة طلبات.
    for ($i = 0; $i < 5; $i++) {
        $this->wallet->credit($this->user, 10, "solved_puzzle:{$i}");
    }

    $this->user->wallet->refresh();

    expect($this->user->wallet->pending_balance)->toBe(50)
        ->and($this->user->wallet->lifetime_earned)->toBe(50)
        ->and(GemTransaction::where('user_id', $this->user->id)->count())->toBe(5);
});