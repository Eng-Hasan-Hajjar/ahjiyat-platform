<?php

require_once __DIR__.'/_support.php';

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\RedemptionRequest;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Str;

/*
| E26 (57-61) - سلطة الاقتصاد: السعر والمكافأة والرصيد والاستبدال تُحسب كلها من الخادم. العميل يرسل «ماذا يريد» فقط، لا «كم».
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

if (! function_exists('e26_priced_item')) {
    function e26_priced_item(int $amount = 100, array $itemAttrs = []): array
    {
        $item = StoreItem::factory()->create($itemAttrs + ['fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 1]);
        $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => $amount]);

        return [$item, $price];
    }

    /** مستخدم مؤهَّل للاستبدال بكل شروط config('gems.eligibility') (نفس نمط RedemptionServiceTest). */
    function e26_eligible_user(): User
    {
        $eligibility = config('gems.eligibility');
        $user = User::factory()->create(['created_at' => now()->subDays($eligibility['min_account_age_days'] + 1)]);

        for ($i = 0; $i < $eligibility['min_puzzles_solved']; $i++) {
            PuzzleAttempt::create(['user_id' => $user->id, 'puzzle_id' => Puzzle::factory()->create()->id, 'attempt_number' => 1, 'is_correct' => true]);
        }

        $user->wallet->update(['available_balance' => config('gems.min_redemption')]);

        return $user->fresh();
    }
}

test('57: replaying a purchase (same request key) never charges or grants twice, and another user cannot reuse the key', function () {
    [$item, $price] = e26_priced_item(100, ['grant_quantity' => 2]);
    $buyer = e26_user();
    $currency = $price->currency;
    app(CurrencyWalletService::class)->creditAvailable($buyer, $currency, 1000, 'e26');
    $key = (string) Str::uuid();

    foreach (range(1, 3) as $i) {
        $this->actingAs($buyer)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => $key]);
    }

    expect(StorePurchase::where('user_id', $buyer->id)->count())->toBe(1);
    expect(app(CurrencyWalletService::class)->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe(900);
    expect(app(\App\Services\Store\InventoryService::class)->quantityFor($buyer, $item))->toBe(2);

    // مستخدم آخر يعيد استعمال المفتاح نفسه: لا شراء له ولا خصم ولا تسليم ببيانات الأول.
    $other = e26_user();
    app(CurrencyWalletService::class)->creditAvailable($other, $currency, 1000, 'e26');
    $this->actingAs($other)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => $key]);

    expect(StorePurchase::where('user_id', $other->id)->count())->toBe(0);
    expect(app(CurrencyWalletService::class)->balanceFor($other, $currency)->refresh()->available_balance)->toBe(1000);
});

test('57b: solving the same puzzle correctly again never pays the reward twice', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب', 'gem_reward' => 8]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), ['answer' => 'الجواب']);
    }

    expect(e26_wallet_state($user)[1])->toBe(8);
    expect(PuzzleAttempt::where('user_id', $user->id)->where('puzzle_id', $puzzle->id)->where('is_correct', true)->count())->toBe(1);
});

test('58: a client-supplied price, amount or currency is ignored - the debit is always the stored price, and a foreign/inactive price id is refused', function () {
    [$item, $price] = e26_priced_item(500);
    [$cheapItem, $cheapPrice] = e26_priced_item(1);
    $buyer = e26_user();
    $currency = $price->currency;
    app(CurrencyWalletService::class)->creditAvailable($buyer, $currency, 5000, 'e26');
    $wallets = app(CurrencyWalletService::class);

    // حقول سعر مزوّرة بجانب الطلب الصحيح.
    $this->actingAs($buyer)->post(route('store.items.purchase', $item), [
        'price_id' => $price->id, 'request_key' => (string) Str::uuid(),
        'price' => 1, 'amount' => 1, 'price_amount' => 1, 'cost' => 0, 'currency_id' => 999, 'discount' => 100, 'quantity' => 99,
    ]);
    expect($wallets->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe(4500);
    expect(StorePurchase::where('user_id', $buyer->id)->value('price_amount'))->toBe(500);

    // سعر عنصر آخر أرخص: مرفوض (price_id يجب أن ينتمي لنفس العنصر) ولا خصم.
    $before = $wallets->balanceFor($buyer, $currency)->refresh()->available_balance;
    $this->actingAs($buyer)->post(route('store.items.purchase', $item), ['price_id' => $cheapPrice->id, 'request_key' => (string) Str::uuid()])->assertNotFound();
    expect($wallets->balanceFor($buyer, $currency)->refresh()->available_balance)->toBe($before);

    // سعر قديم غير فعّال (عنصر آخر بسعر موقوف): مرفوض ولا خصم.
    [$retiredItem, $retiredPrice] = e26_priced_item(1);
    $retiredPrice->forceFill(['is_active' => false])->save();
    $this->actingAs($buyer)->post(route('store.items.purchase', $retiredItem), ['price_id' => $retiredPrice->id, 'request_key' => (string) Str::uuid()]);
    expect($wallets->balanceFor($buyer, $retiredPrice->currency)->refresh()->available_balance)->toBe($before);
    expect(StorePurchase::where('user_id', $buyer->id)->count())->toBe(1);

    // رصيد غير كافٍ: لا شراء ولا رصيد سالب.
    $broke = e26_user();
    $this->actingAs($broke)->post(route('store.items.purchase', $item), ['price_id' => $price->id, 'request_key' => (string) Str::uuid()]);
    expect(StorePurchase::where('user_id', $broke->id)->count())->toBe(0);
    expect($wallets->balanceFor($broke, $currency)->refresh()->available_balance)->toBeGreaterThanOrEqual(0);
});

test('59: a client-supplied reward, XP, score or currency never changes what a puzzle pays', function () {
    $user = e26_user();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'الجواب', 'gem_reward' => 8]);

    $this->actingAs($user)->post(route('puzzles.attempt', $puzzle), [
        'answer' => 'الجواب',
        'gem_reward' => 9999, 'reward' => 9999, 'gems' => 9999, 'amount' => 9999, 'pending_balance' => 9999, 'available_balance' => 9999,
        'currency_id' => 99, 'is_correct' => 1, 'score' => 100000, 'xp' => 100000, 'points' => 100000, 'user_id' => User::factory()->create()->id,
    ]);

    expect(e26_wallet_state($user))->toBe([0, 8]);
    expect(PuzzleAttempt::where('user_id', $user->id)->count())->toBe(1);
    expect(Wallet::query()->max('pending_balance'))->toBeLessThanOrEqual(8);
});

test('60: balances and account flags cannot be mass-assigned through any HTTP form (register, profile, admin-looking fields)', function () {
    // التسجيل: حقول إضافية لا تنتج دورًا ولا توثيقًا ولا رصيدًا.
    $this->post('/register', [
        'name' => 'مخترق', 'email' => 'mass.assign@example.test', 'password' => 'Str0ng-Passw0rd-E26', 'password_confirmation' => 'Str0ng-Passw0rd-E26',
        'is_frozen' => 0, 'email_verified_at' => now()->toDateTimeString(), 'role' => 'administrator', 'roles' => ['administrator'], 'is_admin' => 1,
        'available_balance' => 99999, 'pending_balance' => 99999, 'lifetime_earned' => 99999, 'public_id' => 'FORGED',
    ])->assertRedirect();

    $registered = User::where('email', 'mass.assign@example.test')->firstOrFail();
    expect($registered->hasVerifiedEmail())->toBeFalse();
    expect($registered->hasAnyRole(['administrator', 'super-admin', 'content-manager']))->toBeFalse();
    expect($registered->can('admin.access'))->toBeFalse();
    expect($registered->public_id)->not->toBe('FORGED');
    expect(e26_wallet_state($registered))->toBe([0, 0]);

    // الملف الشخصي: نفس المبدأ.
    $user = e26_user();
    $before = e26_wallet_state($user);
    $this->actingAs($user)->patch('/profile', [
        'name' => 'اسم جديد', 'email' => $user->email,
        'is_frozen' => 1, 'available_balance' => 99999, 'pending_balance' => 99999, 'role' => 'super-admin', 'roles' => ['super-admin'],
        'email_verified_at' => null, 'password' => 'Hacked-Passw0rd-1', 'public_id' => 'FORGED2',
    ]);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('اسم جديد');
    expect($fresh->is_frozen)->not->toBeTrue();
    expect($fresh->hasVerifiedEmail())->toBeTrue();
    expect($fresh->hasAnyRole(['administrator', 'super-admin']))->toBeFalse();
    expect($fresh->public_id)->not->toBe('FORGED2');
    expect(\Illuminate\Support\Facades\Hash::check('Hacked-Passw0rd-1', $fresh->password))->toBeFalse();
    expect(e26_wallet_state($user))->toBe($before);

    // User::fillable لا يحوي أي حقل صلاحية/حالة/رصيد.
    $fillable = (new User)->getFillable();
    foreach (['is_frozen', 'email_verified_at', 'public_id', 'remember_token', 'role', 'roles', 'is_admin'] as $dangerous) {
        expect($fillable)->not->toContain($dangerous);
    }
});

test('60b: wallet and ledger rows are written only by the economy/service layer - no controller, request, middleware or Livewire class touches them', function () {
    $offenders = [];

    foreach (e26_php_files(['app/Http', 'app/Livewire', 'app/Console']) as $file) {
        $code = e26_php_code($file);

        if (preg_match('/\bWallet::(create|update|insert|upsert|where|query|firstOrCreate|updateOrCreate)\b|->wallet->(update|fill|forceFill|increment|decrement|save)\s*\(|CurrencyTransaction::(create|insert|upsert)\b/', $code, $m)) {
            // التسجيل ينشئ محفظة فارغة بالعملة الافتراضية فقط (لا قيم من الطلب) وهو المكان الوحيد المسموح.
            if (e26_relative($file) === 'app/Http/Controllers/Auth/RegisteredUserController.php') {
                continue;
            }
            $offenders[] = e26_relative($file).' => '.$m[0];
        }
    }

    expect($offenders)->toBe([]);
});

test('61: redemption is refused for guests, unverified, frozen, ineligible, over-balance and malformed requests - and writes nothing', function () {
    $count = fn () => RedemptionRequest::count();

    // ضيف.
    $this->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->assertRedirect(route('login'));

    // غير موثَّق البريد.
    $unverified = e26_eligible_user();
    $unverified->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($unverified->fresh())->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x']);

    // مجمَّد.
    $frozen = e26_freeze(e26_eligible_user());
    $this->actingAs($frozen)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x']);

    // حساب جديد غير مؤهَّل (عمر الحساب/الألغاز) رغم الرصيد.
    $new = User::factory()->create();
    $new->wallet->update(['available_balance' => 50000]);
    $this->actingAs($new)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => 'x'])->assertSessionHas('error');

    expect($count())->toBe(0);

    // مؤهَّل لكن بطلبات غير صالحة: أكبر من الرصيد، دون الحد الأدنى، سالب، صفر، نص، عشري، وصف طويل.
    $eligible = e26_eligible_user();
    $balance = e26_wallet_state($eligible)[0];
    foreach ([$balance + 1, 1, -5000, 0, 'abc', '1e9', 10000.5] as $bad) {
        \Illuminate\Support\Facades\Cache::flush();   // حد الاستبدال (5/ساعة) يُختبر بـ56؛ هنا نفحص التحقق من الصحة لا الحد
        $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => $bad, 'reward_description' => 'x'])->assertSessionHasErrors('gems_amount');
    }
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => 10000, 'reward_description' => str_repeat('ب', 501)])->assertSessionHasErrors('reward_description');

    expect($count())->toBe(0);
    expect(e26_wallet_state($eligible)[0])->toBe($balance);
});

test('61b: a valid redemption debits exactly once for the authenticated user (a forged user_id is ignored) and an immediate repeat is refused', function () {
    $eligible = e26_eligible_user();
    $victim = e26_eligible_user();
    $amount = (int) config('gems.min_redemption');

    $this->actingAs($eligible)->post(route('redemption.store'), ['gems_amount' => $amount, 'reward_description' => 'مكافأة', 'user_id' => $victim->id, 'status' => 'fulfilled', 'reviewed_by' => $victim->id])
        ->assertRedirect(route('redemption.index'));

    $row = RedemptionRequest::firstOrFail();
    expect($row->user_id)->toBe($eligible->id)->and($row->status)->toBe(RedemptionRequest::STATUS_PENDING)->and($row->reviewed_by)->toBeNull();
    expect(e26_wallet_state($eligible)[0])->toBe(0);
    expect(e26_wallet_state($victim)[0])->toBe($amount);

    // تكرار الإرسال (نقر مزدوج): الرصيد صفر الآن، فلا طلب ثانٍ.
    $this->actingAs($eligible->fresh())->post(route('redemption.store'), ['gems_amount' => $amount, 'reward_description' => 'مكافأة']);
    expect(session()->has('errors') || session()->has('error'))->toBeTrue();
    expect(RedemptionRequest::count())->toBe(1);
    expect(e26_wallet_state($eligible)[0])->toBe(0);
});
