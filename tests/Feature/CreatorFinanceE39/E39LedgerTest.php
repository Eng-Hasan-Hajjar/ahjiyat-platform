<?php

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CurrencyTransaction;
use App\Models\GemTransaction;
use App\Models\OperationalAuditLog;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorLedgerService;
use App\Services\CreatorFinance\CreatorMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e39_seed());

function e39_post(CreatorEarningAccount $account, string $type, int $a, int $r, int $p, string $key, array $meta = []): CreatorEarningEntry
{
    return DB::transaction(function () use ($account, $type, $a, $r, $p, $key, $meta) {
        $locked = app(CreatorLedgerService::class)->lockAccount((int) $account->id);

        return app(CreatorLedgerService::class)->post($locked, $type, $a, $r, $p, $key, $meta);
    });
}

// ───────────────────────────── BA6: الدفتر ─────────────────────────────

test('BA6-01: an entry whose movements do not match its type is refused before it reaches the database', function () {
    [, , $account] = e39_funded_creator(3);
    $bad = [
        ['revenue_share', -5, 0, 0], ['revenue_share', 5, 1, 0], ['adjustment_debit', 5, 0, 0], ['payout_reserve', -5, 4, 0],
        ['payout_release', 5, -4, 0], ['payout_settle', 1, -5, 5], ['payout_settle', 0, -5, 4], ['unknown_type', 1, 0, 0], ['revenue_share', 0, 0, 0],
    ];

    foreach ($bad as $i => [$type, $a, $r, $p]) {
        expect(fn () => e39_post($account->refresh(), $type, $a, $r, $p, 'bad:'.$i))->toThrow(LogicException::class);
    }

    expect(CreatorEarningEntry::query()->count())->toBe(1);
});

test('BA6-02: every valid movement shape is accepted and moves money between buckets without creating or destroying any', function () {
    [, , $account] = e39_funded_creator(3);
    e39_post($account, 'adjustment_credit', 500, 0, 0, 'v:1');
    e39_post($account->refresh(), 'adjustment_debit', -200, 0, 0, 'v:2');
    e39_post($account->refresh(), 'payout_reserve', -1000, 1000, 0, 'v:3');
    e39_post($account->refresh(), 'payout_release', 300, -300, 0, 'v:4');
    e39_post($account->refresh(), 'payout_settle', 0, -700, 700, 'v:5');
    $account->refresh();

    expect([$account->available_minor, $account->reserved_minor, $account->paid_lifetime_minor])->toBe([100000 + 500 - 200 - 1000 + 300, 0, 700]);
});

test('BA6-03: ledger entries are append only - no update and no delete, ever', function () {
    [, , $account] = e39_funded_creator(3);
    $entry = CreatorEarningEntry::query()->firstOrFail();

    expect(fn () => $entry->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->forceFill(['available_delta_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

test('BA6-04: the database itself rejects a duplicate source key', function () {
    [, , $account] = e39_funded_creator(3);
    $entry = CreatorEarningEntry::query()->firstOrFail();

    expect(fn () => DB::table('creator_earning_entries')->insert([
        'public_id' => (string) Str::ulid(), 'creator_earning_account_id' => $account->id, 'creator_profile_id' => $account->creator_profile_id, 'user_id' => $account->user_id,
        'type' => 'adjustment_credit', 'available_delta_minor' => 1, 'reserved_delta_minor' => 0, 'paid_delta_minor' => 0, 'currency' => 'USD', 'source_key' => $entry->source_key, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('BA6-05: posting the same key twice returns the first entry and never changes the snapshot again', function () {
    [, , $account] = e39_funded_creator(3);
    $first = e39_post($account, 'adjustment_credit', 700, 0, 0, 'idem:1');
    $second = e39_post($account->refresh(), 'adjustment_credit', 700, 0, 0, 'idem:1');

    expect($second->id)->toBe($first->id)->and($first->wasRecentlyCreated)->toBeTrue()->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($account->refresh()->available_minor)->toBe(100700)->and(CreatorEarningEntry::query()->count())->toBe(2);
});

test('BA6-06: reusing a key for a different movement is refused', function () {
    [, , $account] = e39_funded_creator(3);
    e39_post($account, 'adjustment_credit', 700, 0, 0, 'idem:2');

    expect(fn () => e39_post($account->refresh(), 'adjustment_credit', 701, 0, 0, 'idem:2'))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_post($account->refresh(), 'adjustment_debit', -700, 0, 0, 'idem:2'))->toThrow(CreatorFinanceException::class);
});

test('BA6-07: an overdraw is refused as a whole - no entry and no snapshot change', function () {
    [, , $account] = e39_funded_creator(3);
    $before = CreatorEarningEntry::query()->count();

    expect(fn () => e39_post($account, 'adjustment_debit', -100001, 0, 0, 'over:1'))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_post($account->refresh(), 'payout_reserve', -100001, 100001, 0, 'over:2'))->toThrow(CreatorFinanceException::class)
        ->and(CreatorEarningEntry::query()->count())->toBe($before)->and($account->refresh()->available_minor)->toBe(100000);
});

test('BA6-08: releasing or settling more than is reserved is refused', function () {
    [, , $account] = e39_funded_creator(3);
    e39_post($account, 'payout_reserve', -1000, 1000, 0, 'rs:1');

    expect(fn () => e39_post($account->refresh(), 'payout_release', 1001, -1001, 0, 'rs:2'))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_post($account->refresh(), 'payout_settle', 0, -1001, 1001, 'rs:3'))->toThrow(CreatorFinanceException::class);
});

test('BA6-09: after a mixed lifecycle the snapshot always equals what the ledger sums to', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $a = e39_request($creator, '100.00');
    e39_payouts()->reject($a, $admin, 'رفض تجريبي للاختبار');
    $b = e39_request($creator, '250.00');
    e39_payouts()->approve($b, $admin);
    e39_payouts()->settle($b->refresh(), $admin, 'REF-0001');
    e39_request($creator, '50.00');
    e39_ledger()->adjust($account->refresh(), $admin, 'credit', '12.34', 'goodwill', 'تعويض تجريبي للاختبار');

    $sums = e39_ledger()->ledgerBalances((int) $account->id);
    $account->refresh();

    expect([$account->available_minor, $account->reserved_minor, $account->paid_lifetime_minor])->toBe([$sums['available'], $sums['reserved'], $sums['paid']])
        ->and($sums)->toBe(['available' => 100000 - 25000 - 5000 + 1234, 'reserved' => 5000, 'paid' => 25000]);
});

test('BA6-10: ledger entries keep their period and allocation references and a stable source key', function () {
    [, $profile, $account, $period] = e39_funded_creator(3);
    $entry = CreatorEarningEntry::query()->firstOrFail();

    expect($entry->creator_revenue_period_id)->toBe($period->id)->and($entry->creator_revenue_allocation_id)->not->toBeNull()->and($entry->source_key)->toBe('revenue:'.$period->public_id.':'.$profile->id)
        ->and($entry->currency)->toBe('USD')->and($entry->user_id)->toBe($account->user_id);
});

test('BA6-11: money is parsed strictly into integer minor units', function () {
    foreach (['12' => 1200, '12.5' => 1250, '12.50' => 1250, '0.01' => 1, '٣٠٫٥٠' => 3050, ' 7 ' => 700, '999999999999' => 99999999999900] as $input => $minor) {
        expect(CreatorMoney::parse((string) $input, 'USD'))->toBe($minor);
    }

    foreach (['', '1,000', '1.000,5', '-1', '+1', '1e3', '1 000', '0', '0.00', '12.345', 'abc', '١٢,٥٠', '1.', '.5', '1٬000', '١٢٣٤٥٦٧٨٩٠١٢٣'] as $bad) {
        expect(fn () => CreatorMoney::parse($bad, 'USD'))->toThrow(CreatorFinanceException::class);
    }
});

test('BA6-12: parse honors the maximum and the currency exponent', function () {
    expect(fn () => CreatorMoney::parse('10.01', 'USD', 1000))->toThrow(CreatorFinanceException::class)
        ->and(CreatorMoney::parse('10.00', 'USD', 1000))->toBe(1000)
        ->and(fn () => CreatorMoney::parse('1', 'XXX'))->toThrow(CreatorFinanceException::class);
});

test('BA6-13: formatting and the major-unit text round-trip exactly', function () {
    expect(CreatorMoney::format(123456, 'USD'))->toBe('$1,234.56')->and(CreatorMoney::toMajorText(123456, 'USD'))->toBe('1234.56')->and(CreatorMoney::toMajorText(5, 'USD'))->toBe('0.05');

    foreach ([1, 99, 100, 123456789, 99999999999] as $minor) {
        expect(CreatorMoney::parse(CreatorMoney::toMajorText($minor, 'USD'), 'USD'))->toBe($minor);
    }
});

// ───────────────────────────── BA7: الحساب ─────────────────────────────

test('BA7-01: the account model refuses a negative balance in any bucket', function () {
    [, , $account] = e39_funded_creator(3);

    foreach (['available_minor', 'reserved_minor', 'paid_lifetime_minor'] as $column) {
        expect(fn () => $account->forceFill([$column => -1])->save())->toThrow(LogicException::class);
    }
});

test('BA7-02: owner and currency of an account never change, and an account is never deleted', function () {
    [$creator, , $account] = e39_funded_creator(3);

    expect(fn () => $account->forceFill(['currency' => 'EUR'])->save())->toThrow(LogicException::class)
        ->and(fn () => $account->forceFill(['user_id' => e16User()->id])->save())->toThrow(LogicException::class)
        ->and(fn () => $account->forceFill(['creator_profile_id' => 999])->save())->toThrow(LogicException::class)
        ->and(fn () => $account->delete())->toThrow(LogicException::class);
});

test('BA7-03: enabling twice yields one account per creator (idempotent) and the database enforces uniqueness', function () {
    $admin = e39_admin();
    [$creator, $profile, $account] = e39_creator($admin);
    $again = e39_monetization()->enable($profile, $admin);

    expect($again->id)->toBe($account->id)->and(CreatorEarningAccount::query()->count())->toBe(1);

    expect(fn () => DB::table('creator_earning_accounts')->insert([
        'public_id' => (string) Str::ulid(), 'creator_profile_id' => $profile->id, 'user_id' => $creator->id, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('BA7-04: the account currency is the platform accounting currency and follows the configured override', function () {
    [, , $usd] = e39_creator();
    config(['creator_monetization.accounting_currency' => 'TRY']);
    [, , $try] = e39_creator();
    config(['creator_monetization.accounting_currency' => 'ZZZ']);

    expect($usd->currency)->toBe('USD')->and($try->currency)->toBe('TRY')->and(CreatorMoney::currency())->toBe('USD');
});

test('BA7-05: rebuilding the snapshot repairs drift, writes an audit row and is a no-op when already right', function () {
    [, , $account] = e39_funded_creator(3);
    DB::table('creator_earning_accounts')->where('id', $account->id)->update(['available_minor' => 5, 'reserved_minor' => 9]);

    $fixed = e39_ledger()->rebuildSnapshot($account->refresh(), e39_admin());
    $again = e39_ledger()->rebuildSnapshot($account->refresh(), e39_admin());

    expect($fixed)->toBeTrue()->and($again)->toBeFalse()->and($account->refresh()->available_minor)->toBe(100000)->and($account->reserved_minor)->toBe(0)
        ->and(OperationalAuditLog::query()->where('action', 'creator_finance_balances_fixed')->count())->toBe(1);
});

test('BA7-06: ledger balances are computed by the database from the entries', function () {
    [, , $account] = e39_funded_creator(3);
    e39_post($account, 'adjustment_credit', 25, 0, 0, 'lb:1');

    expect(e39_ledger()->ledgerBalances((int) $account->id))->toBe(['available' => 100025, 'reserved' => 0, 'paid' => 0]);
});

// ───────────────────────────── BA11: التعديلات الإدارية ─────────────────────────────

test('BA11-01: a credit adjustment adds to the available balance with a structured reason, a note and the actor', function () {
    [, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $entry = e39_ledger()->adjust($account, $admin, 'credit', '12.34', 'goodwill', 'تعويض معتمد بقرار الإدارة');

    expect($entry->type)->toBe('adjustment_credit')->and($entry->available_delta_minor)->toBe(1234)->and($entry->reason_code)->toBe('goodwill')->and($entry->note)->toBe('تعويض معتمد بقرار الإدارة')
        ->and($entry->actor_id)->toBe($admin->id)->and($account->refresh()->available_minor)->toBe(101234);
});

test('BA11-02: a debit adjustment never exceeds the available balance and never touches reserved or paid', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_request($creator, '400.00');
    $account->refresh();

    expect(fn () => e39_ledger()->adjust($account, $admin, 'debit', '600.01', 'clawback', 'استرداد مبلغ حُسب بالخطأ'))->toThrow(CreatorFinanceException::class);

    $entry = e39_ledger()->adjust($account->refresh(), $admin, 'debit', '600.00', 'clawback', 'استرداد مبلغ حُسب بالخطأ');
    $account->refresh();

    expect($entry->reserved_delta_minor)->toBe(0)->and($entry->paid_delta_minor)->toBe(0)->and($account->available_minor)->toBe(0)->and($account->reserved_minor)->toBe(40000)->and($account->paid_lifetime_minor)->toBe(0);
});

test('BA11-03: the adjustment needs a valid direction, reason, note length and amount within the ceiling', function () {
    [, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $cases = [
        ['sideways', '1.00', 'goodwill', 'ملاحظة كافية الطول هنا'],
        ['credit', '1.00', 'made_up', 'ملاحظة كافية الطول هنا'],
        ['credit', '1.00', 'goodwill', 'قصيرة'],
        ['credit', '1.00', 'goodwill', str_repeat('ب', 501)],
        ['credit', 'abc', 'goodwill', 'ملاحظة كافية الطول هنا'],
        ['credit', '0', 'goodwill', 'ملاحظة كافية الطول هنا'],
        ['credit', '100000.01', 'goodwill', 'ملاحظة كافية الطول هنا'],
    ];

    foreach ($cases as [$direction, $amount, $reason, $note]) {
        expect(fn () => e39_ledger()->adjust($account, $admin, $direction, $amount, $reason, $note))->toThrow(CreatorFinanceException::class);
    }

    expect(CreatorEarningEntry::query()->count())->toBe(1);
});

test('BA11-04: nobody adjusts their own financial account', function () {
    $admin = e39_admin();
    [, $profile, $account] = e39_creator($admin);
    $profile->user->assignRole('administrator');

    expect(fn () => e39_ledger()->adjust($account, $profile->user->refresh(), 'credit', '5.00', 'goodwill', 'محاولة تعديل حسابي الخاص'))->toThrow(CreatorFinanceException::class);
});

test('BA11-05: the adjust permission is required in the service itself, not only in the page', function () {
    [, , $account] = e39_funded_creator(3);

    expect(fn () => e39_ledger()->adjust($account, e39_plain_staff(), 'credit', '5.00', 'goodwill', 'محاولة بلا صلاحية كافية'))->toThrow(CreatorFinanceException::class)
        ->and(fn () => e39_ledger()->adjust($account, e39_staff_with(['creator-finance.view']), 'credit', '5.00', 'goodwill', 'محاولة بصلاحية قراءة فقط'))->toThrow(CreatorFinanceException::class)
        ->and(e39_ledger()->adjust($account, e39_staff_with(['creator-finance.adjust']), 'credit', '5.00', 'goodwill', 'بصلاحية التعديل الصحيحة')->available_delta_minor)->toBe(500);
});

test('BA11-06: a request key makes a repeated submission a no-op and a malformed key is refused', function () {
    [, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $first = e39_ledger()->adjust($account, $admin, 'credit', '5.00', 'goodwill', 'إرسال أول لهذا التعديل', 'req_abcdefgh12');
    $second = e39_ledger()->adjust($account->refresh(), $admin, 'credit', '5.00', 'goodwill', 'إرسال أول لهذا التعديل', 'req_abcdefgh12');

    expect($second->id)->toBe($first->id)->and($account->refresh()->available_minor)->toBe(100500)
        ->and(fn () => e39_ledger()->adjust($account, $admin, 'credit', '5.00', 'goodwill', 'مفتاح طلب غير صالح هنا', 'bad key!'))->toThrow(CreatorFinanceException::class);
});

test('BA11-07: each adjustment is audited with direction, amount and reason', function () {
    [, , $account] = e39_funded_creator(3);
    e39_ledger()->adjust($account, e39_admin(), 'debit', '1.00', 'correction', 'تصحيح خطأ حسابي موثّق');
    $log = OperationalAuditLog::query()->where('action', 'creator_earnings_adjusted')->firstOrFail();

    expect($log->metadata['direction'])->toBe('debit')->and($log->metadata['amount_minor'])->toBe(100)->and($log->metadata['reason'])->toBe('correction');
});

test('BA11-08: adjustments are still possible on a paused or held account', function () {
    [, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    e39_monetization()->pause($account, $admin, 'إيقاف مؤقت للاختبار');
    e39_monetization()->placeHold($account->refresh(), $admin, 'investigation', 'تحقيق جارٍ في الحساب');

    expect(e39_ledger()->adjust($account->refresh(), $admin, 'credit', '1.00', 'goodwill', 'تعديل أثناء الإيقاف')->available_delta_minor)->toBe(100);
});

// ───────────────────────────── BA12: العزل عن الجواهر والاقتصاد ─────────────────────────────

test('BA12-01: finalization, payouts and adjustments never touch gems, currency transactions or wallets', function () {
    [$creator, , $account] = e39_funded_creator(3);
    $admin = e39_admin();
    $before = [GemTransaction::query()->count(), CurrencyTransaction::query()->count(), DB::table('wallets')->count(), DB::table('wallets')->sum('available_balance'), DB::table('wallets')->sum('pending_balance')];
    $payout = e39_request($creator, '100.00');
    e39_payouts()->approve($payout, $admin);
    e39_payouts()->settle($payout->refresh(), $admin, 'REF-ISO-1');
    e39_ledger()->adjust($account->refresh(), $admin, 'credit', '1.00', 'goodwill', 'تعديل لاختبار العزل');

    expect([GemTransaction::query()->count(), CurrencyTransaction::query()->count(), DB::table('wallets')->count(), DB::table('wallets')->sum('available_balance'), DB::table('wallets')->sum('pending_balance')])->toBe($before);
});
