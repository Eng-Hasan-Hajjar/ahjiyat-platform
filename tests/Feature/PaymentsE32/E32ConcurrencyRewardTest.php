<?php

use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipEventRewardPlan;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipRewardBonus;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentException;
use App\Services\PuzzleAttemptService;
use App\Services\Sponsorship\EventRewardPlanService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DatabaseE31/_support.php';
require_once __DIR__.'/../CompetitiveRewards/RewardTestHelpers.php';
require_once __DIR__.'/../CreatorSeriesE30/_support.php';

/*
| E32 (AK12 + AK13) - التزامن وسلامة قاعدة البيانات (محاكاة إعادة إرسال مزدوج على SQLite + اختبارات MySQL حقيقية تُتخطى بوضوح على غيرها)،
| وسلامة اقتصاد المكافآت: التمويل المحقَّق وحده يعزّز المكافآت الرسمية؛ المجتمع/السلاسل صفر؛ لا ميزة شخصية للداعم؛ خطة الحدث المقفلة ثابتة.
*/

beforeEach(function () {
    e32_seed();
    config(['gems.daily_earn_cap' => 100000]);
});

afterEach(fn () => Carbon::setTestNow());

function e32_play_as(?User $user = null, int $reward = 200): array
{
    $user ??= User::factory()->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'gem_reward' => $reward]);

    return [app(PuzzleAttemptService::class)->attempt($user, $puzzle, 'صح'), $user];
}

/** يشغّل استدعاءً ويعيد رمز خطأ MySQL إن رمى QueryException (وإلا 0). */
function e32_mysql_code(Closure $fn): int
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    return 0;
}

function e32_session(string $name): \Illuminate\Database\Connection
{
    config(['database.connections.'.$name => (array) config('database.connections.'.config('database.default'))]);
    DB::purge($name);

    return DB::connection($name);
}

// ------------------------------------------------------------------ AK12: التزامن (قابل للتشغيل على SQLite)

test('E32-93: a double submit of Verify with a STALE in-memory payment (two browser tabs) never creates a second contribution', function () {
    $program = e32_program();
    $payment = e32_submitted(null, $program, '20');
    $tabA = SponsorshipPayment::query()->findOrFail($payment->id);
    $tabB = SponsorshipPayment::query()->findOrFail($payment->id);   // نسخة قديمة: ما زالت submitted في الذاكرة

    e32_payments()->verify(e32_finance(), $tabA);
    $second = e32_payments()->verify(e32_finance(), $tabB);

    expect($tabB->status)->toBe('submitted')->and($second->status)->toBe('verified')->and(SponsorshipContribution::query()->count())->toBe(1)
        ->and(e32_funding($program))->toBe(2000)->and(e32_audit('contribution_materialized'))->toBe(1);

    // رفض بنسخة قديمة بعد التحقق لا يهدم المال المؤكَّد
    expect(fn () => e32_payments()->reject(e32_finance(), $tabB, 'other'))->toThrow(PaymentException::class);
    expect(e32_contribution($payment)->status)->toBe('confirmed');
});

test('E32-94: the database refuses two payments linked to one contribution and the model refuses re-linking', function () {
    $a = e32_verified();
    $b = e32_submitted();

    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['sponsorship_contribution_id' => $a->sponsorship_contribution_id]))->toThrow(QueryException::class);

    $model = SponsorshipPayment::query()->findOrFail($a->id);
    expect(function () use ($model, $b) {
        $model->forceFill(['sponsorship_contribution_id' => SponsorshipContribution::query()->where('id', '!=', $model->sponsorship_contribution_id)->value('id') ?? $b->id])->save();
    })->toThrow(PaymentException::class);

    // وفي الاتجاه الآخر: مساهمتان بالمرجع الخارجي نفسه لدى المزوّد نفسه مرفوضتان من قاعدة البيانات
    $other = e32_verified();
    $first = e32_contribution($a);
    expect(fn () => DB::table('sponsorship_contributions')->where('id', $other->sponsorship_contribution_id)->update(['external_reference' => $first->external_reference, 'provider' => $first->provider]))->toThrow(QueryException::class);
});

test('E32-95: reference, provider reference and request key are unique in the database; many NULL provider references are allowed', function () {
    $user = e16User();
    $a = e32_request($user, null, '10', false, 'USD', 'key-unique-0001');
    $b = e32_request($user);
    $c = e32_request(e16User());   // مراجع مزوّد NULL متعددة مسموحة (يدوي)

    expect($a->provider_reference)->toBeNull()->and($b->provider_reference)->toBeNull()->and($c->provider_reference)->toBeNull();
    expect(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['reference' => $a->reference]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('sponsorship_payments')->where('id', $b->id)->update(['request_key' => 'key-unique-0001']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('sponsorship_payments')->where('id', $c->id)->update(['public_id' => $a->public_id]))->toThrow(QueryException::class);

    // نفس مفتاح الطلب لمستخدم آخر مسموح (التفرد لكل مستخدم)
    expect(e32_request(e16User(), null, '10', false, 'USD', 'key-unique-0001')->exists)->toBeTrue();
});

test('E32-96: a double submit of Refund (stale copies, and E30 direct refund first) is safe and counted once', function () {
    $payment = e32_verified();
    $tabA = SponsorshipPayment::query()->findOrFail($payment->id);
    $tabB = SponsorshipPayment::query()->findOrFail($payment->id);

    e32_payments()->refund(e32_finance(), $tabA, 'استرداد من التبويب الأول');
    $again = e32_payments()->refund(e32_finance(), $tabB, 'استرداد من التبويب الثاني');

    expect($again->status)->toBe('refunded')->and(e32_audit('payment_refunded'))->toBe(1)->and(e32_notes($payment->user, 'support_refunded'))->toHaveCount(1)
        ->and(SponsorshipContribution::query()->where('status', 'refunded')->count())->toBe(1);
});

test('E32-97b: an audit failure in the middle of verification rolls back the contribution, the profile and the payment state together', function () {
    $user = e16User();
    $payment = e32_submitted($user);

    app()->bind(OperationalAuditService::class, fn () => new class extends OperationalAuditService
    {
        public function log(string $action, ?object $subject = null, array $metadata = [], ?User $actor = null): void
        {
            if ($action === 'payment_verified') {
                throw new RuntimeException('simulated failure after the contribution was confirmed');
            }

            parent::log($action, $subject, $metadata, $actor);
        }
    });
    app()->forgetInstance(\App\Services\Payments\SponsorshipPaymentService::class);
    app()->forgetInstance(\App\Services\Sponsorship\ContributionService::class);
    app()->forgetInstance(\App\Services\Sponsorship\SupporterService::class);
    $level = DB::transactionLevel();

    expect(fn () => e32_payments()->verify(e32_finance(), $payment))->toThrow(RuntimeException::class);

    expect(DB::transactionLevel())->toBe($level)->and($payment->fresh()->status)->toBe('submitted')->and($payment->fresh()->sponsorship_contribution_id)->toBeNull()
        ->and(SponsorshipContribution::query()->count())->toBe(0)->and(SupporterProfile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(e32_funding($payment->program))->toBe(0)->and(e32_notes($user, 'support_confirmed'))->toHaveCount(0);
});

test('E32-98: every financial mutation takes a row lock inside a transaction (structural guard) and the lock order is contribution then payment', function () {
    $source = (string) file_get_contents(app_path('Services/Payments/SponsorshipPaymentService.php'));

    foreach (['markTransferred', 'attachProof', 'cancelByOwner', 'requestClarification', 'cancel', 'verifyCore', 'rejectCore', 'refundCore', 'expireStale'] as $method) {
        preg_match('/function '.$method.'\(.*?\n    }\n/s', $source, $m);
        expect($m[0] ?? '')->toContain('DB::transaction')->and($m[0])->toContain('lockForUpdate');
    }

    preg_match('/function refundCore\(.*?\n    }\n/s', $source, $m);
    expect(strpos($m[0], 'SponsorshipContribution::query()->lockForUpdate()'))->toBeLessThan(strpos($m[0], 'SponsorshipPayment::query()->lockForUpdate()'));

    // لا مسار خارج الخدمة يكتب حالة الطلب مباشرة (المتحكمات/Filament/الأوامر تمرّ بالخدمة)
    foreach (['Http/Controllers/Support', 'Filament', 'Console/Commands'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($dir))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $code = (string) file_get_contents($file->getPathname());
                expect($code)->not->toMatch('/SponsorshipPayment::query\(\)[^;]*->(update|delete)\(/')->and($code)->not->toMatch('/->forceFill\(\[\s*\'status\'/');
            }
        }
    }
});

// ------------------------------------------------------------------ AK12: MySQL حقيقية (تُتخطى بوضوح على غيرها)

test('E32-MY1: [MySQL] the payments schema is InnoDB/utf8mb4 with RESTRICT foreign keys; users and programs with payments cannot be deleted', function () {
    e31_requires_mysql();

    $row = DB::selectOne('select engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_name = ?', ['sponsorship_payments']);
    expect(strtolower((string) $row->e))->toBe('innodb')->and((string) $row->c)->toStartWith('utf8mb4');

    $payment = e32_verified();
    expect(e32_mysql_code(fn () => DB::table('users')->where('id', $payment->user_id)->delete()))->toBe(1451)
        ->and(e32_mysql_code(fn () => DB::table('sponsorship_programs')->where('id', $payment->sponsorship_program_id)->delete()))->toBe(1451)
        ->and(e32_mysql_code(fn () => DB::table('sponsorship_contributions')->where('id', $payment->sponsorship_contribution_id)->delete()))->toBe(1451);
});

test('E32-MY2: [MySQL] two real sessions racing on the payment unique keys - the second waits (1205) then gets a duplicate (1062); never two rows', function () {
    e31_requires_mysql();

    $table = 'e32_race_probe_'.getmypid();
    $first = e32_session('e32_race_first');
    $second = e32_session('e32_race_second');
    // CREATE TABLE ... LIKE ينسخ الأعمدة والفهارس الفريدة الحقيقية لجدول الطلبات (بلا مفاتيح أجنبية) فيختبر قيودنا الفعلية.
    $first->statement("create table `{$table}` like `sponsorship_payments`");

    try {
        $row = fn (string $public, string $reference, string $providerRef, ?int $contribution) => [
            'public_id' => str_pad($public, 26, '0'), 'reference' => $reference, 'sponsorship_program_id' => 1, 'user_id' => 1, 'provider' => 'fakepay', 'provider_reference' => $providerRef,
            'amount_minor' => 100, 'currency' => 'USD', 'status' => 'awaiting_payment', 'is_anonymous' => 0, 'sponsorship_contribution_id' => $contribution,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ];

        $first->beginTransaction();
        $first->table($table)->insert($row('A', 'SUP-AAAAAAA2', 'ext-same', 7));
        $second->statement('set session innodb_lock_wait_timeout = 1');

        $whileOpen = e32_mysql_code(fn () => $second->table($table)->insert($row('B', 'SUP-BBBBBBB2', 'ext-same', 8)));
        $first->commit();
        $afterCommitProviderRef = e32_mysql_code(fn () => $second->table($table)->insert($row('B', 'SUP-BBBBBBB2', 'ext-same', 8)));
        $afterCommitContribution = e32_mysql_code(fn () => $second->table($table)->insert($row('C', 'SUP-CCCCCCC2', 'ext-other', 7)));
        $afterCommitReference = e32_mysql_code(fn () => $second->table($table)->insert($row('D', 'SUP-AAAAAAA2', 'ext-third', 9)));

        expect($whileOpen)->toBe(1205)->and($afterCommitProviderRef)->toBe(1062)->and($afterCommitContribution)->toBe(1062)->and($afterCommitReference)->toBe(1062)
            ->and((int) $first->table($table)->count())->toBe(1);
    } finally {
        $first->statement("drop table if exists `{$table}`");
        DB::purge('e32_race_first');
        DB::purge('e32_race_second');
    }
});

test('E32-MY3: [MySQL] Arabic text and emoji in notes and names round-trip byte for byte, and money columns stay integers', function () {
    e31_requires_mysql();

    $user = e16User();
    $payment = e32_request($user, null, '12.34', false, 'USD', null, 'لُغْزُ 💎 ‏مرحبا');
    $payment = e32_payments()->markTransferred($user, $payment, 'حوّلت 💎 من حساب عمر');
    $row = DB::selectOne('select display_name, payer_note, amount_minor from sponsorship_payments where id = ?', [$payment->id]);

    expect($row->display_name)->toBe('لُغْزُ 💎 ‏مرحبا')->and($row->payer_note)->toBe('حوّلت 💎 من حساب عمر')->and($payment->fresh()->amount_minor)->toBeInt()->toBe(1234);
});

// ------------------------------------------------------------------ AK13: سلامة اقتصاد المكافآت

test('E32-99: verified funding boosts an official eligible reward with a separate ledger row and a bonus snapshot', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '50'); // 5%

    [$result, $player] = e32_play_as();

    $rows = CurrencyTransaction::query()->where('user_id', $player->id)->orderBy('id')->get();
    expect($result['sponsor_bonus'])->toBe(10)->and($rows)->toHaveCount(2)->and($rows[1]->reason)->toStartWith('sponsor_bonus:')->and($rows[1]->amount)->toBe(10)->and(SponsorshipRewardBonus::query()->count())->toBe(1);
});

test('E32-100: unverified money of every kind (awaiting, submitted, rejected, cancelled, expired) gives no boost', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);

    e32_request(null, $program, '900');
    e32_submitted(null, $program, '900');
    e32_payments()->reject(e32_finance(), e32_submitted(null, $program, '900'), 'not_received');
    e32_payments()->cancel(e32_finance(), e32_submitted(null, $program, '900'), 'طلب مكرر');
    $stale = e32_request(null, $program, '900');
    $this->travel(49)->hours();
    e32_payments()->expireStale();

    [$result] = e32_play_as();
    expect($result['sponsor_bonus'])->toBe(0)->and($stale->fresh()->status)->toBe('expired')->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('E32-101: a community puzzle gets zero boost and never touches the economy even with verified funding', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '500');

    $work = e28_published(e28_creator(), 'text');
    $player = e16User();
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), 'إجابة خاطئة');
    app(\App\Services\Creators\CommunityPlayService::class)->attempt($player, $work->fresh(), (string) ($work->fresh()->answer_raw ?? 'صح'));

    expect(CurrencyTransaction::query()->where('user_id', $player->id)->count())->toBe(0)->and(SponsorshipRewardBonus::query()->count())->toBe(0);
});

test('E32-102: creator series get zero boost even with verified funding', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '500');

    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    e30s_published($creator, $works);
    $player = e16User();
    $before = e28_world($player);

    foreach ($works as $work) {
        $this->actingAs($player)->post(route('community.puzzles.attempt', $work->public_id), ['answer' => 'القلم'])->assertSessionHasNoErrors();
    }

    expect(SponsorshipRewardBonus::query()->count())->toBe(0)->and(e28_world($player))->toBe($before);
});

test('E32-103: a paying supporter (even a main sponsor) gets no personal reward advantage over an ordinary player', function () {
    $program = e32_program();
    e30_boost($program, '10', 100, 1000);
    $supporterUser = e16User();
    $paid = e32_verified($supporterUser, $program, '40'); // 4%
    e30_role($program, SupporterProfile::query()->findOrFail($paid->supporter_profile_id), 'main_sponsor');

    [$a] = e32_play_as($supporterUser);
    [$b, $ordinary] = e32_play_as();

    expect($a['sponsor_bonus'])->toBe(8)->and($b['sponsor_bonus'])->toBe(8)->and((int) $supporterUser->fresh()->wallet->pending_balance)->toBe((int) $ordinary->fresh()->wallet->pending_balance);
});

test('E32-104: a locked competition reward plan is unchanged by later verified payments (lock behaviour untouched)', function () {
    e17Freeze();
    $event = e18Event();
    $currency = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
    e18Rule($event, ['min_rank' => 1, 'max_rank' => 2, 'reward_type' => 'currency', 'currency_id' => $currency->id, 'amount' => 100]);
    $program = e30_program(['target_type' => 'competitive_event', 'target_id' => $event->id]);
    e30_boost($program, '10', 100, 1000);
    e32_verified(null, $program, '20');

    $plan = app(EventRewardPlanService::class)->lock($event->fresh(), e30_admin());
    expect($plan->boost_bps)->toBe(200)->and($plan->funding_snapshot_minor)->toBe(2000);

    e32_verified(null, $program, '500');
    e32_payments()->refund(e32_finance(), e32_verified(null, $program, '100'), 'استرداد بعد القفل');

    $fresh = SponsorshipEventRewardPlan::query()->findOrFail($plan->id);
    expect($fresh->boost_bps)->toBe(200)->and($fresh->funding_snapshot_minor)->toBe(2000);
});
