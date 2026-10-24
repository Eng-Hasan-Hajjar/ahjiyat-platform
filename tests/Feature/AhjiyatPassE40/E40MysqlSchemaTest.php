<?php

use App\Models\AhjiyatPass;
use App\Models\User;
use App\Services\AhjiyatPass\PassDefinitionCache;
use App\Services\AhjiyatPass\PassTrack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E40 (AX18) - حدود مسار الأحجيات في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن «تقدّم واحد لكل (لاعب، مسار)»، «شريحة واحدة لكل رقم»، «مكافأة واحدة لكل (شريحة، مسار)»، «مطالبة واحدة لكل (لاعب، مكافأة)»،
| «منح نقاط واحد لكل source_key»، «طلب مميز مفتوح واحد»، «مسار فعّال واحد» قيود قاعدة بيانات (مع تعايش NULL)، وسياسة المفاتيح الأجنبية (التاريخ المالي لا يُحذف تتابعيًا)،
| والنص العربي حتى حدود الأعمدة، ودقّة الأعداد الصحيحة الكبيرة، وحتمية حساب الشريحة، وعدم وجود float/decimal. السباق الحقيقي بين عمليات PHP في tools/e40/concurrency-probe.php.
*/

beforeEach(fn () => e40_seed());

function e40_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/** يكرّر صفًا بعد تغيير حقول معيّنة (لتفعيل قيد واحد فقط في كل مرة). */
function e40_clone_row(string $table, int $id, array $override = []): void
{
    $row = (array) DB::table($table)->where('id', $id)->first();
    unset($row['id']);

    if (array_key_exists('public_id', $row)) {
        $row['public_id'] = (string) Str::ulid();
    }

    DB::table($table)->insert($override + $row);
}

/** عالم صغير كامل: مسار منشور بـ6 شرائح، لاعب مميز له نقاط ومطالبات وطلب محقَّق. */
function e40_world(): array
{
    $b = e40_build(['tiers' => 6, 'step' => 100, 'free_tiers' => range(1, 6), 'premium_tiers' => range(1, 6), 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_reach($user, $b, 300);
    $order = e40_buy_premium($user, $b['pass'], $b['admin']);
    e40_claims()->collectAll($user, $b['pass']);

    return [$b, $user, $order];
}

test('AX18-111: one progress row per player and pass is a database constraint', function () {
    [$b, $user] = e40_world();
    $progress = DB::table('user_pass_progress')->first();

    expect(e40_violates(fn () => e40_clone_row('user_pass_progress', $progress->id)))->toBeTrue()
        ->and(DB::table('user_pass_progress')->count())->toBe(1)
        ->and(e40_violates(fn () => e40_clone_row('user_pass_progress', $progress->id, ['user_id' => e40_player()->id])))->toBeFalse();
});

test('AX18-112: one tier per number, one reward per tier and track, one mission per quest are database constraints', function () {
    [$b] = e40_world();
    $tier = DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $b['pass']->id)->first();
    $reward = DB::table('ahjiyat_pass_tier_rewards')->first();
    $mission = DB::table('ahjiyat_pass_missions')->first();

    expect(e40_violates(fn () => e40_clone_row('ahjiyat_pass_tiers', $tier->id)))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('ahjiyat_pass_tier_rewards', $reward->id)))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('ahjiyat_pass_missions', $mission->id)))->toBeTrue()
        ->and(DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $b['pass']->id)->count())->toBe(6)
        ->and(DB::table('ahjiyat_pass_tier_rewards')->count())->toBe(12)->and(DB::table('ahjiyat_pass_missions')->count())->toBe(1);
});

test('AX18-113: a reward can be claimed once per player — a duplicate claim row is refused by the database', function () {
    [$b, $user] = e40_world();
    $claim = DB::table('user_pass_reward_claims')->where('user_id', $user->id)->first();
    $inventoryBefore = DB::table('inventory_transactions')->count();
    $claims = DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count();

    expect($claims)->toBe(8)->and(e40_violates(fn () => e40_clone_row('user_pass_reward_claims', $claim->id)))->toBeTrue()
        ->and(DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count())->toBe($claims)
        ->and(DB::table('inventory_transactions')->count())->toBe($inventoryBefore);
});

test('AX18-114: a point source key grants points once — a duplicate source_key is refused', function () {
    [$b, $user] = e40_world();
    $grant = DB::table('pass_point_grants')->first();
    $sum = (int) DB::table('pass_point_grants')->sum('points');

    expect(e40_violates(fn () => e40_clone_row('pass_point_grants', $grant->id)))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('pass_point_grants', $grant->id, ['source_key' => 'pass:x:u:y:uqp:z'])))->toBeFalse()
        ->and((int) DB::table('pass_point_grants')->sum('points'))->toBe($sum + (int) $grant->points);
});

test('AX18-115: one open premium order, one reference code, one request key — and NULL open keys coexist while the single active slot is unique', function () {
    $b = e40_build();
    $user = e40_player();
    $open = e40_premium()->request($user, $b['pass']);
    $row = DB::table('digital_purchases')->where('id', $open->id)->first();

    expect($row->open_key)->not->toBeNull()
        ->and(e40_violates(fn () => e40_clone_row('digital_purchases', $open->id, ['reference_code' => 'REF-OTHER-1', 'request_key' => 'other-key'])))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('digital_purchases', $open->id, ['open_key' => null, 'request_key' => 'other-key'])))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('digital_purchases', $open->id, ['reference_code' => 'REF-OTHER-3', 'request_key' => 'other-key-3'])))->toBeTrue();

    // NULL لا يتعارض: عدّة طلبات مغلقة (open_key فارغ) للمستخدم نفسه مسموحة.
    foreach (['REF-CLOSED-1', 'REF-CLOSED-2', 'REF-CLOSED-3'] as $ref) {
        e40_clone_row('digital_purchases', $open->id, ['open_key' => null, 'request_key' => $ref, 'reference_code' => $ref, 'status' => 'cancelled']);
    }

    expect(DB::table('digital_purchases')->whereNull('open_key')->count())->toBe(3);

    // مسار فعّال واحد: live_slot فريد ويقبل عدّة NULL.
    $pass = DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->first();
    expect($pass->live_slot)->toBe(1)
        ->and(e40_violates(fn () => e40_clone_row('ahjiyat_passes', $b['pass']->id, ['internal_key' => 'second-live', 'slug' => 'second-live'])))->toBeTrue()
        ->and(e40_violates(fn () => e40_clone_row('ahjiyat_passes', $b['pass']->id, ['internal_key' => 'second-ended', 'slug' => 'second-ended', 'live_slot' => null, 'status' => 'ended'])))->toBeFalse()
        ->and(e40_violates(fn () => e40_clone_row('ahjiyat_passes', $b['pass']->id, ['internal_key' => 'third-ended', 'slug' => 'third-ended', 'live_slot' => null, 'status' => 'ended'])))->toBeFalse();
});

test('AX18-116: a refused duplicate claim leaves the connection usable and the next real claim still works with no partial grant', function () {
    $b = e40_build(['tiers' => 4, 'step' => 100, 'free_tiers' => [1, 2, 3, 4], 'premium_tiers' => [1], 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $user = e40_player();
    e40_reach($user, $b, 200);

    e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][2]['free']->id);
    $claim = DB::table('user_pass_reward_claims')->where('user_id', $user->id)->first();
    $inventory = DB::table('inventory_transactions')->count();

    expect(e40_violates(fn () => e40_clone_row('user_pass_reward_claims', $claim->id)))->toBeTrue();

    e40_claims()->claim($user, $b['pass'], (int) $b['rewards'][1]['free']->id);

    expect(DB::table('user_pass_reward_claims')->where('user_id', $user->id)->count())->toBe(2)
        ->and(DB::table('inventory_transactions')->count())->toBe($inventory + 1);
});

test('AX18-117: Arabic text (with emoji) survives a database round trip up to the exact column limits', function () {
    [$b, $user, $order] = e40_world();
    $mixed = fn (int $n) => mb_substr(str_repeat('أحجية 🧩 ', $n), 0, $n);

    DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->update(['name' => $mixed(120), 'subtitle' => $mixed(160), 'description' => $mixed(5000)]);
    DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $b['pass']->id)->where('tier_number', 3)->update(['label' => $mixed(60)]);
    DB::table('digital_purchases')->where('id', $order->id)->update([
        'product_name' => $mixed(160), 'payer_note' => $mixed(255), 'transfer_reference' => str_repeat('م', 120), 'decision_message' => $mixed(300), 'review_note' => $mixed(2000),
    ]);
    DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->orderBy('id')->limit(1)->update(['note' => $mixed(300)]);
    DB::table('user_pass_reward_claims')->where('user_id', $user->id)->orderBy('id')->limit(1)->update(['item_name' => $mixed(160)]);

    $pass = DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->first();
    $purchase = DB::table('digital_purchases')->where('id', $order->id)->first();

    expect(mb_strlen($pass->name))->toBe(120)->and($pass->name)->toBe($mixed(120))->and(mb_strlen($pass->subtitle))->toBe(160)->and($pass->description)->toBe($mixed(5000))
        ->and(DB::table('ahjiyat_pass_tiers')->where('ahjiyat_pass_id', $b['pass']->id)->where('tier_number', 3)->value('label'))->toBe($mixed(60))
        ->and($purchase->product_name)->toBe($mixed(160))->and($purchase->payer_note)->toBe($mixed(255))->and($purchase->transfer_reference)->toBe(str_repeat('م', 120))
        ->and($purchase->decision_message)->toBe($mixed(300))->and($purchase->review_note)->toBe($mixed(2000))
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->orderBy('id')->value('note'))->toBe($mixed(300))
        ->and(DB::table('user_pass_reward_claims')->where('user_id', $user->id)->orderBy('id')->value('item_name'))->toBe($mixed(160));
});

test('AX18-118: big integers stay exact — unsigned columns, SUM aggregates and the tier computation', function () {
    [$b, $user, $order] = e40_world();
    $progress = DB::table('user_pass_progress')->where('user_id', $user->id)->first();
    $mission = DB::table('ahjiyat_pass_missions')->first();
    $base = DB::table('pass_point_grants')->where('user_id', $user->id)->sum('points');
    $big = 4_000_000_000;

    foreach ([1, 2] as $i) {
        DB::table('pass_point_grants')->insert([
            'user_pass_progress_id' => $progress->id, 'user_id' => $user->id, 'ahjiyat_pass_id' => $b['pass']->id, 'ahjiyat_pass_mission_id' => $mission->id,
            'source_type' => 'mission', 'source_key' => 'big:'.$i, 'points' => $big, 'created_at' => now(),
        ]);
    }

    $sum = (int) DB::table('pass_point_grants')->where('user_pass_progress_id', $progress->id)->sum('points');
    DB::table('user_pass_progress')->where('id', $progress->id)->update(['pass_points' => 4_294_967_295]);
    DB::table('digital_purchases')->where('id', $order->id)->update(['amount_minor' => 9_007_199_254_740_991]);

    $track = app(PassDefinitionCache::class)->track($b['pass']);

    expect($sum)->toBe((int) $base + 2 * $big)->and((int) DB::table('user_pass_progress')->where('id', $progress->id)->value('pass_points'))->toBe(4_294_967_295)
        ->and((int) DB::table('digital_purchases')->where('id', $order->id)->value('amount_minor'))->toBe(9_007_199_254_740_991)
        ->and($track->tierAt(4_294_967_295))->toBe(6)->and($track->tierAt(PHP_INT_MAX))->toBe(6);
});

test('AX18-119: financial and progress history is protected by foreign keys — RESTRICT where value is involved, staff references only cleared', function () {
    [$b, $user, $order] = e40_world();
    $reward = DB::table('ahjiyat_pass_tier_rewards')->first();
    $claim = DB::table('user_pass_reward_claims')->where('user_id', $user->id)->first();
    $mission = DB::table('ahjiyat_pass_missions')->first();
    $before = e40_counts();

    expect(e40_violates(fn () => DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->delete()))->toBeTrue()
        ->and(e40_violates(fn () => DB::table('users')->where('id', $user->id)->delete()))->toBeTrue()
        ->and(e40_violates(fn () => DB::table('digital_purchases')->where('id', $order->id)->delete()))->toBeTrue()
        ->and(e40_violates(fn () => DB::table('store_items')->where('id', $reward->store_item_id)->delete()))->toBeTrue()
        ->and(e40_violates(fn () => DB::table('quest_definitions')->where('id', $mission->quest_definition_id)->delete()))->toBeTrue()
        ->and(e40_violates(fn () => DB::table('ahjiyat_pass_tier_rewards')->where('id', $claim->ahjiyat_pass_tier_reward_id)->delete()))->toBeTrue()
        ->and(e40_counts())->toBe($before);

    $staff = $b['admin'];
    DB::table('users')->where('id', $staff->id)->delete();

    expect(e40_counts())->toBe($before)->and(DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->value('created_by'))->toBeNull()
        ->and(DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->value('published_by'))->toBeNull()
        ->and(DB::table('digital_purchases')->where('id', $order->id)->value('verified_by'))->toBeNull()
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $order->id)->whereNull('actor_user_id')->count())->toBeGreaterThanOrEqual(1);
});

test('AX18-120: the tier computation is deterministic — independent of insert order and exact at every boundary', function () {
    $admin = e40_admin();
    $draft = e40_builder()->createDraft($admin, ['internal_key' => 'determinism-pass', 'name' => 'حتمية', 'starts_at' => now()->addDay()->toDateTimeString(), 'premium_enabled' => false]);
    $points = [0, 50, 51, 300, 301, 1000, 4_000_000_000];

    // إدراج بترتيب معكوس عمدًا: الترتيب المنطقي برقم الشريحة وليس بالمعرّف.
    foreach (array_reverse(array_keys($points)) as $index) {
        DB::table('ahjiyat_pass_tiers')->insert([
            'ahjiyat_pass_id' => $draft->id, 'tier_number' => $index + 1, 'required_points' => $points[$index], 'is_milestone' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Cache::flush();
    $track = app(PassDefinitionCache::class)->track($draft->fresh());

    expect(array_column($track->tiers, 'number'))->toBe([1, 2, 3, 4, 5, 6, 7])->and(array_column($track->tiers, 'points'))->toBe($points)->and($track->maxTier())->toBe(7)
        ->and($track->tierAt(0))->toBe(1)->and($track->tierAt(49))->toBe(1)->and($track->tierAt(50))->toBe(2)->and($track->tierAt(51))->toBe(3)->and($track->tierAt(299))->toBe(3)->and($track->tierAt(300))->toBe(4)
        ->and($track->tierAt(301))->toBe(5)->and($track->tierAt(999))->toBe(5)->and($track->tierAt(1000))->toBe(6)->and($track->tierAt(3_999_999_999))->toBe(6)->and($track->tierAt(4_000_000_000))->toBe(7);

    $again = PassTrack::fromArray($track->toArray());
    expect($again->tiers)->toBe($track->tiers)->and($again->tierAt(1000))->toBe($track->tierAt(1000));
});

test('AX18-121: every money and point column in the E40 tables is an integer type — no float, double or decimal', function () {
    $tables = ['ahjiyat_passes', 'ahjiyat_pass_tiers', 'ahjiyat_pass_tier_rewards', 'ahjiyat_pass_missions', 'user_pass_progress', 'pass_point_grants', 'user_pass_reward_claims', 'digital_purchases', 'digital_purchase_events'];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);

        foreach (Schema::getColumns($table) as $column) {
            $type = strtolower((string) $column['type_name']);
            expect($type)->not->toContain('float')->not->toContain('double')->not->toContain('decimal')->not->toContain('numeric')->not->toContain('real');

            if (preg_match('/(_minor|_points|^points$|^pass_points$|^quantity$|^highest_tier$|^tier_number$)/', (string) $column['name'])) {
                expect(str_contains($type, 'int'))->toBeTrue("{$table}.{$column['name']} must be an integer type (is {$type})");
            }
        }
    }
});

test('AX18-122: the named indexes the page, the queues and the idempotency guards rely on exist and fit the identifier limit', function () {
    $expected = [
        'ahjiyat_passes' => ['ahjiyat_passes_public_unique', 'ahjiyat_passes_key_unique', 'ahjiyat_passes_slug_unique', 'ahjiyat_passes_live_slot_unique', 'ahjiyat_passes_status_starts_index', 'ahjiyat_passes_window_index', 'ahjiyat_passes_claim_ends_index'],
        'ahjiyat_pass_tiers' => ['ahjiyat_pass_tiers_number_unique'],
        'ahjiyat_pass_tier_rewards' => ['ahjiyat_pass_rewards_tier_track_unique', 'ahjiyat_pass_rewards_pass_track_index'],
        'ahjiyat_pass_missions' => ['ahjiyat_pass_missions_pass_quest_unique'],
        'user_pass_progress' => ['user_pass_progress_user_pass_unique', 'user_pass_progress_pass_tier_index', 'user_pass_progress_pass_premium_index'],
        'pass_point_grants' => ['pass_point_grants_source_key_unique', 'pass_point_grants_progress_created_index', 'pass_point_grants_pass_mission_index'],
        'user_pass_reward_claims' => ['user_pass_claims_user_reward_unique', 'user_pass_claims_progress_index', 'user_pass_claims_pass_track_index'],
        'digital_purchases' => ['digital_purchases_public_unique', 'digital_purchases_open_key_unique', 'digital_purchases_reference_unique', 'digital_purchases_user_request_unique', 'digital_purchases_user_status_index', 'digital_purchases_status_created_index', 'digital_purchases_product_status_index'],
        'digital_purchase_events' => ['digital_purchase_events_purchase_index'],
    ];

    foreach ($expected as $table => $names) {
        $present = collect(Schema::getIndexes($table))->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();

        foreach ($names as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64)
                ->and(in_array(strtolower($name), $present, true))->toBeTrue("{$table} is missing {$name}");
        }
    }
});

test('AX18-123: the dates that drive the lifecycle keep second precision and compare consistently in the database', function () {
    $b = e40_build(['starts' => '2026-12-01 00:00:00', 'days' => 30, 'grace' => 5]);
    $pass = DB::table('ahjiyat_passes')->where('id', $b['pass']->id)->first();

    expect($pass->starts_at)->toBe('2026-12-01 00:00:00')->and($pass->ends_at)->toBe('2026-12-31 00:00:00')->and($pass->claim_ends_at)->toBe('2027-01-05 00:00:00')
        ->and(DB::table('ahjiyat_passes')->where('starts_at', '<=', '2026-12-10 12:00:00')->where('ends_at', '>', '2026-12-10 12:00:00')->count())->toBe(1)
        ->and(DB::table('ahjiyat_passes')->where('ends_at', '>', '2026-12-31 00:00:00')->count())->toBe(0);

    expect(AhjiyatPass::query()->where('claim_ends_at', '>', now())->count())->toBe(1)->and(User::query()->count())->toBeGreaterThanOrEqual(1);
});
