<?php

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Economy\Integrity\LedgerEffects;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\Economy\Integrity\WalletIntegrityAuditor;
use App\Services\GemWalletService;
use App\Services\PendingGemReleaseService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E46 - تدقيق سلامة الجواهر (قراءة فقط): أحكام مطابق / فرق مثبت / لا يمكن الجزم، المتاح والمعلَّق كلٌّ على حدة، العزل بين العملات، الأرصدة الافتتاحية،
| الدفعات الحتمية، الحركة الجارية، وانعدام أي كتابة أثناء الفحص.
*/

beforeEach(function () {
    $this->wallets = app(CurrencyWalletService::class);
    $this->gems = app(GemWalletService::class);
    $this->auditor = app(WalletIntegrityAuditor::class);
    $this->gem = e46_gem();
});

// ───────────── القاعدة الموثَّقة لأثر الأنواع ─────────────

test('E46-01: the documented ledger effects match what the real write paths do to each balance', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 100, 'solved_puzzle:1');            // earn_pending +100
    $this->gems->releasePendingToAvailable($user, 60, 'hold');     // release_available +60
    $this->gems->debitAvailable($user, 10, 'hint:1');              // redeem -10
    $this->gems->refundAvailable($user, 4, 'redemption_rejected:1'); // admin_adjustment +4
    $this->gems->adjustAvailable($user, -6, 'manual');             // admin_adjustment -6
    $this->gems->adjustAvailable($user, 9, 'manual');              // admin_adjustment +9

    $wallet = e46_wallet($user, $this->gem);
    $rows = CurrencyTransaction::query()->where('user_id', $user->id)->orderBy('id')->get(['type', 'amount'])->map(fn ($r) => ['type' => $r->type, 'amount' => (int) $r->amount])->all();
    $folded = LedgerEffects::fold($rows);

    expect($folded)->toBe(['available' => $wallet->available_balance, 'pending' => $wallet->pending_balance])
        ->and($wallet->available_balance)->toBe(57)   // 60 - 10 + 4 - 6 + 9
        ->and($wallet->pending_balance)->toBe(40);
});

test('E46-02: SUM(amount) alone is NOT enough: the same wallet matches by the documented rule but not by a naive sum', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 30, 'solved_puzzle:1');
    $this->gems->credit($user, 20, 'solved_puzzle:2');
    $this->gems->releasePendingToAvailable($user, 20, 'hold');

    $wallet = e46_wallet($user, $this->gem);
    $naive = e46_naive_sum($user, $this->gem);

    expect($naive)->toBe(70)                       // 30 + 20 + 20 : لا يساوي أيًّا من الرصيدين
        ->and($naive)->not->toBe($wallet->available_balance)->not->toBe($wallet->pending_balance)
        ->and($wallet->available_balance)->toBe(20)->and($wallet->pending_balance)->toBe(30);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and($case->derivedAvailable)->toBe(20)->and($case->derivedPending)->toBe(30);
});

// ───────────── مطابق وغير مطابق ─────────────

test('E46-03: a wallet driven only through the real services after a full life-cycle is matched', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 120, 'solved_puzzle:1');
    $this->gems->credit($user, 80, 'solved_puzzle:2');
    $this->gems->releasePendingToAvailable($user, 150, 'hold');
    $this->gems->debitAvailable($user, 40, 'hint:5');
    $this->gems->refundAvailable($user, 40, 'redemption_rejected:3');
    $this->gems->adjustAvailable($user, -7, 'تسوية');

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and($case->diffAvailable())->toBe(0)->and($case->diffPending())->toBe(0)
        ->and($case->reasons)->toBe([])
        ->and($case->ledgerRows)->toBe(6);
});

test('E46-04: a difference in AVAILABLE only is proven, and pending is reported separately as equal', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    $this->gems->releasePendingToAvailable($user, 50, 'hold');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['available_balance' => 53]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN)
        ->and($case->diffAvailable())->toBe(3)->and($case->diffPending())->toBe(0)
        ->and($case->reasons)->toBe([]);
});

test('E46-05: a difference in PENDING only is proven, and available is reported separately as equal', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['pending_balance' => 44]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN)
        ->and($case->diffPending())->toBe(-6)->and($case->diffAvailable())->toBe(0);
});

test('E46-06: a movement between the two balances (release) that was applied to one side only is a proven difference on both sides', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 40, 'solved_puzzle:1');
    $this->gems->releasePendingToAvailable($user, 40, 'hold');
    e46_age_everything();
    // خلل محاكى: المتاح صحيح لكن المعلَّق «عاد» 40 دون سجل
    e46_tamper($user, $this->gem, ['pending_balance' => 40]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN)
        ->and($case->diffPending())->toBe(40)->and($case->diffAvailable())->toBe(0);
});

// ───────────── الأرصدة الافتتاحية والمرحَّلة ─────────────

test('E46-07: a wallet with a balance but NO ledger rows is undetermined (possible opening balance), never a proven error', function () {
    $user = User::factory()->create();
    e46_tamper($user, $this->gem, ['available_balance' => 500, 'pending_balance' => 20, 'lifetime_earned' => 520]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($case->reasons)->toContain(WalletAuditCase::REASON_NO_LEDGER)
        ->and($case->ledgerRows)->toBe(0);
});

test('E46-08: a ledger that exists but cannot explain the wallet lifetime_earned (carried earnings) is undetermined', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    e46_age_everything();
    // رصيد مرحَّل: 100 جوهرة افتتاحية أضيفت للمعلّق وللعدّاد دون ledger
    e46_tamper($user, $this->gem, ['pending_balance' => 110, 'lifetime_earned' => 110]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($case->reasons)->toContain(WalletAuditCase::REASON_CARRIED_EARNINGS)
        ->and($case->diffPending())->toBe(100);
});

test('E46-09: the same diff WITH a lifetime_earned that the ledger fully explains is proven (no evidence of carried earnings)', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['pending_balance' => 110]); // lifetime_earned يبقى 10 = ما يفسّره الدفتر

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN)->and($case->diffPending())->toBe(100);
});

test('E46-10: lifetime_earned lower than what the ledger recorded makes the wallet history untrustworthy, so it is undetermined', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 30, 'solved_puzzle:1');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['available_balance' => 5, 'lifetime_earned' => 10]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($case->reasons)->toContain(WalletAuditCase::REASON_LIFETIME_BELOW_LEDGER);
});

test('E46-11: an empty wallet with no ledger is matched (zero equals zero), not an alarm', function () {
    $user = User::factory()->create();

    expect(e46_case($user, $this->gem)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

// ───────────── شذوذ الدفتر ─────────────

test('E46-12: an unknown ledger type is reported as an anomaly: matched numbers keep a note, a diff becomes undetermined', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 10, 'solved_puzzle:1');
    $this->gems->releasePendingToAvailable($user, 10, 'hold');
    e46_age_everything();

    // نوع غير معروف +5 على المتاح ولم تتأثر المحفظة ⇒ فرق، لكن الأثر غير مضمون
    e46_raw_ledger($user, $this->gem, 'mystery_type', 5);

    $diff = e46_case($user, $this->gem);

    expect($diff->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($diff->reasons)->toContain(WalletAuditCase::REASON_LEDGER_ANOMALY);

    e46_tamper($user, $this->gem, ['available_balance' => 15, 'lifetime_earned' => 15]);
    $same = e46_case($user, $this->gem);

    expect($same->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)->and($same->notes)->toContain(WalletAuditCase::NOTE_LEDGER_ANOMALY);
});

test('E46-13: a sign-rule violation (negative earn_pending) is an anomaly that blocks a proven verdict', function () {
    $user = User::factory()->create();
    e46_raw_ledger($user, $this->gem, CurrencyTransaction::TYPE_EARN_PENDING, -5);
    e46_raw_ledger($user, $this->gem, CurrencyTransaction::TYPE_EARN_PENDING, 20);
    e46_tamper($user, $this->gem, ['pending_balance' => 20, 'lifetime_earned' => 15]);

    $case = e46_case($user, $this->gem);

    expect($case->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($case->reasons)->toContain(WalletAuditCase::REASON_LEDGER_ANOMALY)
        ->and(LedgerEffects::violatesSignRule('earn_pending', -5))->toBeTrue()
        ->and(LedgerEffects::violatesSignRule('redeem', -5))->toBeFalse()
        ->and(LedgerEffects::violatesSignRule('redeem', 5))->toBeTrue()
        ->and(LedgerEffects::violatesSignRule('admin_adjustment', -5))->toBeFalse()
        ->and(LedgerEffects::violatesSignRule('admin_adjustment', 0))->toBeTrue();
});

test('E46-14: ledger rows with no wallet row are reported as undetermined (wallet_missing), never silently skipped', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 25, 'solved_puzzle:1');
    e46_age_everything();
    DB::table('wallets')->where('user_id', $user->id)->delete();

    $summary = $this->auditor->audit(e46_scope($user->id, [$this->gem->id]));

    expect($summary->scanned)->toBe(1)->and($summary->undetermined)->toBe(1)
        ->and($summary->reasonCounts)->toBe([WalletAuditCase::REASON_WALLET_MISSING => 1]);
});

// ───────────── عزل العملات ─────────────

test('E46-15: other currencies are fully isolated: a campaign currency never enters the default gems balance or verdict', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();

    $this->gems->credit($user, 50, 'solved_puzzle:1');                         // جواهر
    $this->wallets->creditPending($user, $event, 900, 'campaign_reward');       // عملة حملة
    $this->wallets->releasePending($user, $event, 400, 'campaign_hold');
    $this->wallets->debitAvailable($user, $event, 100, 'spend');
    e46_age_everything();

    $gems = e46_case($user, $this->gem);
    $campaign = e46_case($user, $event);

    expect($gems->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and($gems->derivedPending)->toBe(50)->and($gems->derivedAvailable)->toBe(0)
        ->and($campaign->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and($campaign->derivedPending)->toBe(500)->and($campaign->derivedAvailable)->toBe(300);

    // فرق في عملة الحملة لا يلوّث حكم الجواهر ولا العكس
    e46_tamper($user, $event, ['available_balance' => 999]);

    expect(e46_case($user, $this->gem)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and(e46_case($user, $event)->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN);
});

test('E46-16: the default scope audits only the default earned currency; an explicit currency or all-currencies scope reaches the others', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    $this->wallets->creditPending($user, $event, 70, 'campaign_reward');
    e46_age_everything();
    e46_tamper($user, $event, ['pending_balance' => 71]);

    $defaultOnly = $this->auditor->audit(e46_scope($user->id, [$this->gem->id]));
    $eventOnly = $this->auditor->audit(e46_scope($user->id, [$event->id]));
    $all = $this->auditor->audit(e46_scope($user->id, null));

    expect($defaultOnly->scanned)->toBe(1)->and($defaultOnly->provenDifferences)->toBe(0)
        ->and($eventOnly->scanned)->toBe(1)->and($eventOnly->provenDifferences)->toBe(1)
        ->and($all->scanned)->toBe(2)->and($all->provenDifferences)->toBe(1)
        ->and(array_keys($all->byCurrency))->toEqualCanonicalizing([$this->gem->id, $event->id]);
});

// ───────────── انتقال المعلَّق إلى المتاح والخصم والاسترداد (عبر المسارات الحقيقية) ─────────────

test('E46-17: pending → available through the real scheduled release keeps the wallet matched, and re-running it releases nothing twice', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 70, 'solved_puzzle:1');
    DB::table('currency_transactions')->where('user_id', $user->id)->update(['created_at' => now()->subDays(30)]);

    $service = app(PendingGemReleaseService::class);
    $first = $service->run();
    $second = $service->run();

    $wallet = e46_wallet($user, $this->gem);
    e46_age_everything();

    expect($first['released_gems'])->toBe(70)->and($second['released_gems'])->toBe(0)
        ->and($wallet->available_balance)->toBe(70)->and($wallet->pending_balance)->toBe(0)
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', 'release_available')->count())->toBe(1)
        ->and(e46_case($user, $this->gem)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

test('E46-18: release/release - a second release of the same pending amount is capped by the real pending balance and writes nothing', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 25, 'solved_puzzle:1');

    $a = $this->gems->releasePendingToAvailable($user, 25, 'hold');
    $b = $this->gems->releasePendingToAvailable($user, 25, 'hold');

    expect($a)->not->toBeNull()->and($b)->toBeNull()
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', 'release_available')->count())->toBe(1)
        ->and(e46_wallet($user, $this->gem)->available_balance)->toBe(25);

    e46_age_everything();
    expect(e46_case($user, $this->gem)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

test('E46-19: debit and refund: both are recorded with their sign and keep wallet and ledger matched', function () {
    $user = User::factory()->create();
    $this->wallets->creditAvailable($user, $this->gem, 100, 'seed');
    $this->wallets->debitAvailable($user, $this->gem, 30, 'store_purchase:x', null, CurrencyTransaction::TYPE_SPEND);
    $this->wallets->refund($user, $this->gem, 30, 'refund:x', null, CurrencyTransaction::TYPE_REFUND);
    $this->wallets->debitAvailable($user, $this->gem, 10, 'hint:1');

    e46_age_everything();
    $case = e46_case($user, $this->gem);

    expect(CurrencyTransaction::where('user_id', $user->id)->where('type', 'spend')->value('amount'))->toBe(-30)
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', 'refund')->value('amount'))->toBe(30)
        ->and($case->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and($case->derivedAvailable)->toBe(90);
});

// ───────────── الدفعات والترتيب الحتمي والذاكرة ─────────────

test('E46-20: batches are deterministic by wallet id, bounded by the chunk size, and the audit never loads all wallets at once', function () {
    $users = User::factory()->count(7)->create();

    foreach ($users as $i => $user) {
        $this->gems->credit($user, 10 + $i, 'solved_puzzle:'.$i);
    }

    e46_age_everything();
    // اثنان غير متطابقين
    e46_tamper($users[2], $this->gem, ['pending_balance' => 99]);
    e46_tamper($users[5], $this->gem, ['pending_balance' => 98]);

    $seen = [];
    $walletSelects = 0;
    DB::listen(function ($query) use (&$walletSelects) {
        // استعلام دفعة المحافظ فقط (مؤشر id > ؟)، لا قراءة الحالة المفردة في إعادة القراءة
        if (preg_match('/from [`"]wallets[`"] where .*[`"]id[`"] > \?/i', $query->sql) === 1) {
            $walletSelects++;
        }
    });

    $summary = $this->auditor->audit(e46_scope(null, [$this->gem->id], 3), function (WalletAuditCase $case) use (&$seen) {
        $seen[] = $case->walletId;
    });

    $sorted = $seen;
    sort($sorted);

    expect($seen)->toBe($sorted)                                // ترتيب تصاعدي بمعرّف المحفظة
        ->and($summary->scanned)->toBe(count($seen))
        ->and($summary->provenDifferences)->toBe(2)
        ->and($summary->matched)->toBe($summary->scanned - 2)
        ->and($walletSelects)->toBe((int) ceil($summary->scanned / 3) + (($summary->scanned % 3) === 0 ? 1 : 0)); // كل دفعة ≤ 3 محافظ
});

test('E46-21: the same audit run twice over unchanged data gives the identical sequence of cases (deterministic)', function () {
    foreach (User::factory()->count(5)->create() as $i => $user) {
        $this->gems->credit($user, 5 * ($i + 1), 'solved_puzzle:'.$i);
    }

    e46_age_everything();

    $collect = function () {
        $cases = [];
        $this->auditor->audit(e46_scope(null, [$this->gem->id], 2), function (WalletAuditCase $c) use (&$cases) {
            $cases[] = $c->toArray();
        });

        return $cases;
    };

    expect($collect())->toBe($collect());
});

test('E46-22: a single-user scope only ever touches that user', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->gems->credit($a, 10, 'solved_puzzle:1');
    $this->gems->credit($b, 20, 'solved_puzzle:2');
    e46_age_everything();
    e46_tamper($b, $this->gem, ['pending_balance' => 77]);

    $summaryA = $this->auditor->audit(e46_scope($a->id, [$this->gem->id]));
    $summaryB = $this->auditor->audit(e46_scope($b->id, [$this->gem->id]));

    expect($summaryA->scanned)->toBe(1)->and($summaryA->provenDifferences)->toBe(0)
        ->and($summaryB->scanned)->toBe(1)->and($summaryB->provenDifferences)->toBe(1);
});

// ───────────── الحركة الجارية لا تصنع إنذارًا كاذبًا ─────────────

test('E46-23: a difference on a wallet that moved inside the grace window is undetermined (in_flight), and proven once it is old enough', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    // فرق حسابي + المحفظة والدفتر «طازجان» الآن
    DB::table('wallets')->where('user_id', $user->id)->update(['pending_balance' => 49]);

    $fresh = e46_case($user, $this->gem, 120);

    expect($fresh->verdict)->toBe(WalletAuditCase::VERDICT_UNDETERMINED)
        ->and($fresh->reasons)->toContain(WalletAuditCase::REASON_IN_FLIGHT);

    e46_age_everything();
    DB::table('wallets')->where('user_id', $user->id)->update(['updated_at' => now()->subHour()]);

    expect(e46_case($user, $this->gem, 120)->verdict)->toBe(WalletAuditCase::VERDICT_PROVEN);
});

test('E46-24: a clean wallet is matched even while it is "fresh" (the snapshot makes the in-flight window irrelevant for matches)', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');

    expect(e46_case($user, $this->gem, 3600)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

test('E46-25: a case whose numbers change between the first pass and the re-read is never proven (in_flight), and one that settles becomes matched', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['pending_balance' => 40]);

    // يحاكي حركة تصل بين المرور الأول وإعادة القراءة (المعلَّق يتغيّر ثانيةً، لكنه ما زال مخالفًا)
    $moving = new class extends WalletIntegrityAuditor
    {
        public function run(\App\Services\Economy\Integrity\WalletAuditScope $scope, int $newPending): \App\Services\Economy\Integrity\WalletAuditSummary
        {
            $this->newPending = $newPending;

            return $this->audit($scope);
        }

        public int $newPending = 0;

        protected function recheck(WalletAuditCase $first, \App\Services\Economy\Integrity\WalletAuditScope $scope, \Carbon\CarbonInterface $startedAt): WalletAuditCase
        {
            DB::table('wallets')->where('id', $first->walletId)->update(['pending_balance' => $this->newPending, 'updated_at' => now()->subDays(3)]);

            return parent::recheck($first, $scope, $startedAt);
        }
    };

    $stillWrong = $moving->run(e46_scope($user->id, [$this->gem->id]), 41);
    expect($stillWrong->provenDifferences)->toBe(0)->and($stillWrong->undetermined)->toBe(1)
        ->and($stillWrong->reasonCounts)->toBe([WalletAuditCase::REASON_IN_FLIGHT => 1]);

    // الفرق يختفي عند إعادة القراءة ⇒ مطابق بملاحظة (لا يُحسب خطأ)
    e46_tamper($user, $this->gem, ['pending_balance' => 40]);
    $settled = $moving->run(e46_scope($user->id, [$this->gem->id]), 50);
    expect($settled->matched)->toBe(1)->and($settled->provenDifferences)->toBe(0)->and($settled->undetermined)->toBe(0);
});

// ───────────── قراءة فقط ─────────────

test('E46-26: the audit issues no INSERT/UPDATE/DELETE of any kind and takes no row locks', function () {
    $user = User::factory()->create();
    $this->gems->credit($user, 50, 'solved_puzzle:1');
    e46_age_everything();
    e46_tamper($user, $this->gem, ['pending_balance' => 3]);
    $before = [
        'wallets' => DB::table('wallets')->get()->toArray(),
        'ledger' => DB::table('currency_transactions')->get()->toArray(),
    ];

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->auditor->audit(e46_scope(null, null));
    $this->auditor->auditPair($user->id, $this->gem->id);

    $writes = array_values(array_filter($statements, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|truncate)\b/i', $sql) === 1 || stripos($sql, 'for update') !== false));

    expect($writes)->toBe([])
        ->and(DB::table('wallets')->get()->toArray())->toEqual($before['wallets'])
        ->and(DB::table('currency_transactions')->get()->toArray())->toEqual($before['ledger']);
});
