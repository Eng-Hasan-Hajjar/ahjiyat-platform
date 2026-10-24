<?php

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceProviderState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E41 (BE16) - حدود التجارة في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن «مرجع جلسة/دفعة/استرداد واحد لكل مزوّد» و«حدث مزوّد واحد» و«طلب مفتوح واحد» قيود قاعدة بيانات (مع تعايش NULL)،
| وسياسة المفاتيح الأجنبية (سجل المزوّد والتاريخ المالي لا يُحذفان تتابعيًا)، والنص العربي حتى حدود الأعمدة، ودقّة الأعداد الكبيرة،
| والفهارس المسماة، وعدم وجود float/decimal. السباق الحقيقي بين عمليات PHP في tools/e41/concurrency-probe.php.
*/

beforeEach(fn () => e41_seed());

function e41_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function e41_clone(string $table, int $id, array $override = []): void
{
    $row = (array) DB::table($table)->where('id', $id)->first();
    unset($row['id']);

    if (array_key_exists('public_id', $row)) {
        $row['public_id'] = (string) Str::ulid();
    }

    DB::table($table)->insert($override + $row);
}

/** مشترٍ مدفوع عبر المزوّد (سجل حدث + مراجع). */
function e41_db_world(): array
{
    $h = e41_paid();

    return $h + ['event' => CommerceProviderEvent::query()->firstOrFail()];
}

test('BE16-106: a provider session, payment and refund reference each belong to one purchase per provider — database constraints that let NULLs coexist', function () {
    $h = e41_db_world();
    $id = $h['purchase']->id;
    $row = DB::table('digital_purchases')->where('id', $id)->first();

    expect($row->provider_session_id)->not->toBeNull()->and($row->provider_payment_reference)->not->toBeNull();

    $unique = fn (array $o) => ['reference_code' => 'E41-'.strtoupper(Str::random(8)), 'request_key' => 'rk-'.Str::random(10), 'open_key' => null] + $o;

    expect(e41_violates(fn () => e41_clone('digital_purchases', $id, $unique(['provider_payment_reference' => 'other', 'provider_session_id' => $row->provider_session_id]))))->toBeTrue()
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $id, $unique(['provider_session_id' => 'other-sess', 'provider_payment_reference' => $row->provider_payment_reference]))))->toBeTrue()
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $id, $unique(['provider_session_id' => 'sess-r1', 'provider_payment_reference' => 'pay-r1', 'provider_refund_reference' => 'ref-1']))))->toBeFalse()
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $id, $unique(['provider_session_id' => 'sess-r2', 'provider_payment_reference' => 'pay-r2', 'provider_refund_reference' => 'ref-1']))))->toBeTrue()
        // مزوّد آخر قد يعيد استعمال المعرّف نفسه: القيد لكل مزوّد.
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $id, $unique(['provider' => 'other_pay', 'provider_session_id' => $row->provider_session_id, 'provider_payment_reference' => $row->provider_payment_reference]))))->toBeFalse();

    // NULL لا يتعارض: عدة طلبات يدوية بلا مراجع مزوّد.
    foreach (['a', 'b', 'c'] as $suffix) {
        e41_clone('digital_purchases', $id, $unique(['provider' => 'manual', 'provider_session_id' => null, 'provider_payment_reference' => null, 'provider_refund_reference' => null, 'status' => 'cancelled']));
    }

    expect(DB::table('digital_purchases')->where('provider', 'manual')->whereNull('provider_session_id')->count())->toBe(3);
});

test('BE16-107: a provider event id is recorded once per provider — the database refuses the duplicate, and another provider may reuse the id', function () {
    $h = e41_db_world();
    $event = $h['event'];

    expect(e41_violates(fn () => e41_clone('commerce_provider_events', $event->id)))->toBeTrue()
        ->and(e41_violates(fn () => e41_clone('commerce_provider_events', $event->id, ['provider' => 'other_pay'])))->toBeFalse()
        ->and(e41_violates(fn () => e41_clone('commerce_provider_events', $event->id, ['provider_event_id' => 'evt_new_1'])))->toBeFalse()
        ->and(DB::table('commerce_provider_events')->where('provider', 'sandbox')->where('provider_event_id', $event->provider_event_id)->count())->toBe(1);
});


test('BE16-107b: re-delivering the same signed event is a duplicate with no effect, and an event for an unknown purchase is kept with a NULL purchase id', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    $first = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    $snapshot = [DB::table('digital_purchases')->get()->toJson(), DB::table('commerce_provider_events')->count()];
    $second = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($first['result'])->toBe('applied')->and($second['result'])->toBe('duplicate')->and([DB::table('digital_purchases')->get()->toJson(), DB::table('commerce_provider_events')->count()])->toBe($snapshot);

    $unknown = e41_sandbox()->simulate('sbx_'.str_repeat('b', 24), CommerceProviderState::Paid);
    e41_webhooks()->handle('sandbox', $unknown['payload'], $unknown['headers']);

    expect(DB::table('commerce_provider_events')->whereNull('digital_purchase_id')->count())->toBe(1)->and(DB::table('commerce_provider_events')->whereNotNull('digital_purchase_id')->count())->toBe(1);
});

test('BE16-108: one open order per player is a database constraint and closed orders (NULL open key) coexist', function () {
    $b = e41_world();
    $user = e40_player($b['pass']);
    $open = e40_premium()->request($user, $b['pass']);

    expect(e41_violates(fn () => e41_clone('digital_purchases', $open->id, ['reference_code' => 'E41-DUP-OPEN', 'request_key' => 'rk-dup-open'])))->toBeTrue()
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $open->id, ['reference_code' => 'E41-CLOSED-1', 'request_key' => 'rk-closed-1', 'open_key' => null, 'status' => 'cancelled'])))->toBeFalse()
        ->and(e41_violates(fn () => e41_clone('digital_purchases', $open->id, ['reference_code' => 'E41-CLOSED-2', 'request_key' => 'rk-closed-2', 'open_key' => null, 'status' => 'cancelled'])))->toBeFalse();
});

test('BE16-109: Arabic text and emoji survive a round trip up to the new column limits', function () {
    $h = e41_db_world();
    $mixed = fn (int $n) => mb_substr(str_repeat('دفع 💳 ', $n), 0, $n);

    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update([
        'refund_requested_note' => $mixed(300), 'refund_requested_reason' => $mixed(32), 'refund_error' => $mixed(48), 'review_flag' => $mixed(32), 'review_resolution' => $mixed(24),
        'checkout_url' => 'https://pay.example/'.str_repeat('ب', 900),
    ]);
    DB::table('commerce_provider_events')->where('id', $h['event']->id)->update(['result_detail' => $mixed(48), 'event_type' => $mixed(64), 'provider_reference' => str_repeat('م', 128)]);

    $row = DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();
    $event = DB::table('commerce_provider_events')->where('id', $h['event']->id)->first();

    expect($row->refund_requested_note)->toBe($mixed(300))->and($row->refund_requested_reason)->toBe($mixed(32))->and($row->refund_error)->toBe($mixed(48))->and($row->review_flag)->toBe($mixed(32))
        ->and($row->review_resolution)->toBe($mixed(24))->and(mb_strlen($row->checkout_url))->toBe(920)
        ->and($event->result_detail)->toBe($mixed(48))->and($event->event_type)->toBe($mixed(64))->and($event->provider_reference)->toBe(str_repeat('م', 128));
});

test('BE16-110: big money stays exact — unsigned minor units and SUM aggregates', function () {
    $h = e41_db_world();
    $big = 9_007_199_254_740_991;

    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['amount_minor' => $big]);
    DB::table('commerce_provider_events')->where('id', $h['event']->id)->update(['amount_minor' => 4_000_000_000]);
    e41_clone('commerce_provider_events', $h['event']->id, ['provider_event_id' => 'evt_big_2', 'amount_minor' => 4_000_000_000]);

    expect((int) DB::table('digital_purchases')->where('id', $h['purchase']->id)->value('amount_minor'))->toBe($big)
        ->and((int) DB::table('commerce_provider_events')->sum('amount_minor'))->toBe(8_000_000_000);

    // UNSIGNED يُفرَض في MySQL فقط (SQLite لا يملك نوعًا غير موقَّع)؛ التحقق من عدم قبول سالب هناك.
    if (DB::getDriverName() === 'mysql') {
        expect(e41_violates(fn () => DB::table('commerce_provider_events')->where('id', $h['event']->id)->update(['amount_minor' => -1])))->toBeTrue();
    }
});

test('BE16-111: financial history is protected by foreign keys — the ledger and purchases are RESTRICT, staff references only cleared', function () {
    $h = e41_db_world();
    $admin = $h['build']['admin'];
    e40_premium()->refund($admin, $h['purchase']->fresh(), 'user_request', 'استرداد اختبار');
    $before = [DB::table('digital_purchases')->count(), DB::table('commerce_provider_events')->count(), DB::table('digital_purchase_events')->count()];

    expect(e41_violates(fn () => DB::table('digital_purchases')->where('id', $h['purchase']->id)->delete()))->toBeTrue()
        ->and(e41_violates(fn () => DB::table('users')->where('id', $h['user']->id)->delete()))->toBeTrue()
        ->and([DB::table('digital_purchases')->count(), DB::table('commerce_provider_events')->count(), DB::table('digital_purchase_events')->count()])->toBe($before);

    DB::table('users')->where('id', $admin->id)->delete();

    expect([DB::table('digital_purchases')->count(), DB::table('commerce_provider_events')->count()])->toBe([$before[0], $before[1]])
        ->and(DB::table('digital_purchases')->where('id', $h['purchase']->id)->value('refund_requested_by'))->toBeNull()
        ->and(DB::table('digital_purchases')->where('id', $h['purchase']->id)->value('verified_by'))->toBeNull();
});

test('BE16-112: no money column in the commerce tables is float/double/decimal, and the reference columns are bounded strings', function () {
    foreach (['digital_purchases', 'commerce_provider_events'] as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower((string) $column['type_name']);
            expect($type)->not->toContain('float')->not->toContain('double')->not->toContain('decimal')->not->toContain('numeric')->not->toContain('real');

            if (preg_match('/_minor$/', (string) $column['name'])) {
                expect(str_contains($type, 'int'))->toBeTrue("{$table}.{$column['name']} must be an integer type (is {$type})");
            }
        }
    }

    expect(Schema::hasColumn('commerce_provider_events', 'payload'))->toBeFalse()->and(Schema::hasColumn('commerce_provider_events', 'headers'))->toBeFalse()
        ->and(Schema::hasColumn('commerce_provider_events', 'payload_sha256'))->toBeTrue();
});

test('BE16-113: the named indexes that webhooks, reconciliation and the finance queue rely on exist and fit the identifier limit', function () {
    $expected = [
        'digital_purchases' => ['digital_purchases_provider_session_unique', 'digital_purchases_provider_payment_unique', 'digital_purchases_provider_refund_unique', 'digital_purchases_review_index', 'digital_purchases_provider_status_index', 'digital_purchases_status_expires_index'],
        'commerce_provider_events' => ['commerce_provider_events_event_unique', 'commerce_provider_events_reference_index', 'commerce_provider_events_purchase_index', 'commerce_provider_events_provider_time_index', 'commerce_provider_events_result_time_index'],
    ];

    foreach ($expected as $table => $names) {
        $present = collect(Schema::getIndexes($table))->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();

        foreach ($names as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64)->and(in_array(strtolower($name), $present, true))->toBeTrue("{$table} is missing {$name}");
        }
    }
});

test('BE16-114: timestamps keep second precision and the reconciliation window compares consistently', function () {
    $h = e41_db_world();
    DB::table('digital_purchases')->where('id', $h['purchase']->id)->update(['provider_paid_at' => '2026-12-10 11:59:59', 'checkout_expires_at' => '2026-12-10 12:30:00']);
    DB::table('commerce_provider_events')->where('id', $h['event']->id)->update(['received_at' => '2026-12-10 12:00:00']);

    $row = DB::table('digital_purchases')->where('id', $h['purchase']->id)->first();

    expect($row->provider_paid_at)->toBe('2026-12-10 11:59:59')->and($row->checkout_expires_at)->toBe('2026-12-10 12:30:00')
        ->and(DB::table('commerce_provider_events')->where('received_at', '>=', '2026-12-10 12:00:00')->count())->toBe(1)
        ->and(DB::table('commerce_provider_events')->where('received_at', '>', '2026-12-10 12:00:00')->count())->toBe(0)
        ->and(DigitalPurchase::query()->where('checkout_expires_at', '>', now())->count())->toBe(1);
});
