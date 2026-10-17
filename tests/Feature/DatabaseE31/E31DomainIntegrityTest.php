<?php

use App\Models\CurrencyTransaction;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E31 - سلامة النطاق التي تعتمد عليها MySQL والإعادة بعد الإيقاف المتبادل (deadlock): قيود الفرادة هي خط الدفاع الأخير، والحارس المالي يمنع محو التاريخ.
| تعمل على أي سائق (SQLite افتراضيًا وMySQL في مصفوفة التكافؤ): أخطاء الفرادة تُرفع بالنوع نفسه UniqueConstraintViolationException.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

// ───────────────────────────── الحارس المالي وحذف المستخدم (U2/U3) ─────────────────────────────

test('W-1: a user with no financial history is deletable by a super admin; self-deletion and non-super-admins are refused', function () {
    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $other = User::factory()->create();
    $second = User::factory()->create();
    $second->assignRole('super-admin');
    $plain = User::factory()->create();

    expect(app(UserPolicy::class)->delete($super, $other))->toBeTrue()
        ->and(app(UserPolicy::class)->delete($plain, $other))->toBeFalse()
        ->and(fn () => app(UserPolicy::class)->delete($super, $super))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('W-2: any ledger entry, wallet balance, redemption or reward history makes the policy refuse deletion (freeze instead)', function () {
    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $backup = User::factory()->create();
    $backup->assignRole('super-admin');
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $guard = app(FinancialHistoryGuard::class);

    $withBalance = User::factory()->create();
    $wallets->creditAvailable($withBalance, $currency, 50, 'e31_history');

    $withLedgerOnly = User::factory()->create();
    $wallets->creditAvailable($withLedgerOnly, $currency, 10, 'e31_history');
    $wallets->debitAvailable($withLedgerOnly, $currency, 10, 'e31_history'); // الرصيد صفر لكن الدفتر ممتلئ

    $clean = User::factory()->create();

    expect($guard->historyFor($withBalance))->toContain('دفتر العملات')->and($guard->historyFor($withBalance))->toContain('رصيد المحفظة')
        ->and($guard->historyFor($withLedgerOnly))->toBe(['دفتر العملات'])
        ->and($guard->hasHistory($clean))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $withBalance))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $withLedgerOnly))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $clean))->toBeTrue();
});

test('W-3: the guard reads every history table it lists (a table that does not exist would fail loudly, not silently pass)', function () {
    foreach (array_keys(FinancialHistoryGuard::HISTORY_TABLES) as $table) {
        expect(DB::getSchemaBuilder()->hasColumn($table, 'user_id'))->toBeTrue($table);
    }
});

// ───────────────────────────── المحفظة والدفتر ─────────────────────────────

test('W-4: a failed wallet operation leaves no partial change - the balance and the ledger are untouched', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditAvailable($user, $currency, 30, 'e31_seed');
    $ledgerBefore = CurrencyTransaction::where('user_id', $user->id)->count();

    expect(fn () => $wallets->debitAvailable($user, $currency, 31, 'e31_overdraw'))->toThrow(RuntimeException::class);

    expect($wallets->balanceFor($user, $currency)->available_balance)->toBe(30)
        ->and(CurrencyTransaction::where('user_id', $user->id)->count())->toBe($ledgerBefore);
});

test('W-5: replaying an idempotency key returns the first transaction and never credits twice; a key reused with another amount is refused', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);

    $first = $wallets->creditPending($user, $currency, 40, 'e31_replay', null, 'e31-key-'.$user->id);
    $replay = $wallets->creditPending($user, $currency, 40, 'e31_replay', null, 'e31-key-'.$user->id);

    expect($replay->id)->toBe($first->id)
        ->and($wallets->balanceFor($user, $currency)->pending_balance)->toBe(40)
        ->and(CurrencyTransaction::where('idempotency_key', 'e31-key-'.$user->id)->count())->toBe(1)
        ->and(fn () => $wallets->creditPending($user, $currency, 41, 'e31_replay', null, 'e31-key-'.$user->id))->toThrow(RuntimeException::class);
});

test('W-6: the database itself rejects a duplicate idempotency key and a second wallet row for the same user and currency', function () {
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditPending($user, $currency, 5, 'e31_unique', null, 'e31-dup-key');

    $tx = (array) DB::table('currency_transactions')->where('idempotency_key', 'e31-dup-key')->first();
    unset($tx['id']);
    expect(fn () => DB::table('currency_transactions')->insert($tx))->toThrow(UniqueConstraintViolationException::class);

    $wallet = (array) DB::table('wallets')->where('user_id', $user->id)->where('currency_id', $currency->id)->first();
    unset($wallet['id']);
    expect(fn () => DB::table('wallets')->insert($wallet))->toThrow(UniqueConstraintViolationException::class);
});

// ───────────────────────────── المتجر: سباق نقر مزدوج ─────────────────────────────

test('W-7: a double-click race on the same request key resolves to the existing purchase instead of a 500 - and other unique violations still surface', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 100]);
    app(CurrencyWalletService::class)->creditAvailable($user, $price->currency, 500, 'e31_store');
    $key = (string) Str::uuid();

    // الطلب «الفائز» يلتزم قبل أن يصل الخاسر إلى القيد الفريد؛ نحاكي ذلك: الخاسر يرمي خطأ الفرادة بعد أن صار الصف موجودًا.
    $racing = new class(app(CurrencyWalletService::class), app(\App\Services\Store\InventoryService::class), app(\App\Services\Store\EntitlementService::class)) extends StorePurchaseService
    {
        public ?StorePurchase $winner = null;

        public bool $conflictWithoutRow = false;

        protected function purchaseLocked(User $user, StoreItem $item, StoreItemPrice $price, string $requestKey): StorePurchase
        {
            if (! $this->conflictWithoutRow) {
                $this->winner = (new StorePurchaseService($this->wallets, $this->inventory, $this->entitlements))->purchase($user, $item, $price, $requestKey);
            }

            throw new UniqueConstraintViolationException('testing', 'insert into store_purchases', [], new PDOException('1062 duplicate'));
        }
    };

    // الفحص الأول لا يرى شيئًا (نحذف الصف الفائز لحظة الفحص عبر نسخة لا تتجاوز purchaseLocked) فنصل إلى الفرع المراد
    $result = $racing->purchase($user, $item, $price, $key);

    // ملاحظة: الفائز أُنشئ داخل purchaseLocked، فاستُعيد هنا عبر الفرع catch
    expect($result->id)->toBe($racing->winner->id)
        ->and(StorePurchase::where('request_key', $key)->count())->toBe(1)
        ->and(app(CurrencyWalletService::class)->balanceFor($user, $price->currency)->available_balance)->toBe(400);

    // خطأ فرادة آخر لا صلة له بـrequest_key (لا صف بالمفتاح) يُعاد رميه ولا يُبتلع
    $other = (string) Str::uuid();
    $racing->conflictWithoutRow = true;
    expect(fn () => $racing->purchase($user, $item, $price, $other))->toThrow(UniqueConstraintViolationException::class);
});

// ───────────────────────────── التفاعلات المجتمعية ─────────────────────────────

test('W-8: duplicate rating, favorite and follow rows are rejected by the database; anonymous (NULL user) ratings may repeat', function () {
    $creator = e28_creator();
    e28_category();
    $work = e28_published($creator, 'text');
    $fan = User::factory()->create();
    $profileId = (int) DB::table('creator_profiles')->where('user_id', $creator->id)->value('id');
    $now = now()->toDateTimeString();

    DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => 4, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('community_puzzle_favorites')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'created_at' => $now]);
    DB::table('creator_follows')->insert(['user_id' => $fan->id, 'creator_profile_id' => $profileId, 'created_at' => $now]);

    expect(fn () => DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => 5, 'created_at' => $now, 'updated_at' => $now]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('community_puzzle_favorites')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'created_at' => $now]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('creator_follows')->insert(['user_id' => $fan->id, 'creator_profile_id' => $profileId, 'created_at' => $now]))->toThrow(UniqueConstraintViolationException::class);

    // تقييمان مجهولان (user_id NULL بعد حذف مستخدمين) لا يتصادمان: NULL لا يتساوى بـNULL في القيد الفريد على SQLite وMySQL
    DB::table('community_puzzle_ratings')->insert([
        ['user_id' => null, 'creator_puzzle_id' => $work->id, 'rating' => 3, 'created_at' => $now, 'updated_at' => $now],
        ['user_id' => null, 'creator_puzzle_id' => $work->id, 'rating' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);

    expect(DB::table('community_puzzle_ratings')->where('creator_puzzle_id', $work->id)->whereNull('user_id')->count())->toBe(2);
});

test('W-9: on MySQL the rating range CHECK constraint is enforced by the engine (1..5 only); SQLite relies on the service validation', function () {
    e31_requires_mysql(); // الترحيل يضيف القيد على mysql/mariadb/pgsql فقط؛ SQLite لا يدعم ADD CONSTRAINT

    $creator = e28_creator();
    e28_category();
    $work = e28_published($creator, 'text');
    $fan = User::factory()->create();
    $now = now()->toDateTimeString();

    foreach ([0, 6] as $bad) {
        expect(fn () => DB::table('community_puzzle_ratings')->insert(['user_id' => $fan->id, 'creator_puzzle_id' => $work->id, 'rating' => $bad, 'created_at' => $now, 'updated_at' => $now]))->toThrow(\Illuminate\Database\QueryException::class);
    }
});

// ───────────────────────────── الرعاية ─────────────────────────────

test('W-10: sponsorship contributions - a repeated (provider, external_reference) is rejected, while many contributions without a reference are allowed (NULL-friendly unique)', function () {
    e30_seed();
    $supporter = e30_supporter();
    $program = e30_program();
    $first = e30_confirmed($supporter, $program, '5');
    $second = e30_confirmed($supporter, $program, '6');

    $row = fn (int $id) => (function () use ($id) {
        $r = (array) DB::table('sponsorship_contributions')->find($id);
        unset($r['id']);

        return $r;
    })();

    // بلا مرجع خارجي: صفّان NULL متجاوران مقبولان
    DB::table('sponsorship_contributions')->where('id', $first->id)->update(['external_reference' => null]);
    DB::table('sponsorship_contributions')->where('id', $second->id)->update(['external_reference' => null]);
    expect(DB::table('sponsorship_contributions')->whereNull('external_reference')->count())->toBeGreaterThanOrEqual(2);

    // مرجع خارجي مكرر لدى المزوّد نفسه: مرفوض
    DB::table('sponsorship_contributions')->where('id', $first->id)->update(['external_reference' => 'e31-ext-1']);
    $dup = $row($second->id);
    $dup['provider'] = DB::table('sponsorship_contributions')->where('id', $first->id)->value('provider');
    $dup['external_reference'] = 'e31-ext-1';

    expect(fn () => DB::table('sponsorship_contributions')->insert($dup))->toThrow(UniqueConstraintViolationException::class);
});

test('W-11: a sponsorship bonus source key is unique - the same source can never pay twice at the database level', function () {
    e30_seed();
    $supporter = e30_supporter();
    $program = e30_program();
    $user = User::factory()->create();
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $now = now()->toDateTimeString();
    $bonus = [
        'user_id' => $user->id, 'sponsorship_program_id' => $program->id, 'currency_id' => $currency->id,
        'source_key' => 'e31-source-1',
    ];

    $columns = collect(DB::getSchemaBuilder()->getColumns('sponsorship_reward_bonuses'))->keyBy('name');
    foreach ($columns as $name => $column) {
        if (! array_key_exists($name, $bonus) && $name !== 'id' && ! $column['nullable'] && $column['default'] === null) {
            $bonus[$name] = match (true) {
                str_contains($column['type_name'], 'int') => 1,
                str_contains($column['type_name'], 'char') || str_contains($column['type_name'], 'text') => 'e31',
                str_contains($column['type_name'], 'json') => '{}',
                default => $now,
            };
        }
    }

    DB::table('sponsorship_reward_bonuses')->insert($bonus);

    expect(fn () => DB::table('sponsorship_reward_bonuses')->insert($bonus))->toThrow(UniqueConstraintViolationException::class);
    expect($supporter->id)->toBeInt();
});
