<?php

use App\Models\Currency;
use App\Models\CurrencyPack;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('currency packs still show as coming soon with no purchase form on the store page', function () {
    $premium = app(\App\Services\Economy\CurrencyRegistry::class)->primaryPremiumCurrency();
    CurrencyPack::factory()->create(['currency_id' => $premium->id, 'is_active' => true, 'name' => 'حزمة تجريبية']);

    $response = $this->get(route('store.index'));

    $response->assertOk()->assertSee('حزمة تجريبية')->assertSee('سيتم تفعيل الشراء قريباً');
});

test('a guest sees a login prompt instead of a purchase form on an item detail page', function () {
    $item = StoreItem::factory()->create();
    StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

    $this->get(route('store.items.show', $item))
        ->assertOk()
        ->assertSee('سجّل الدخول للشراء');
});

test('an authenticated verified user sees a working purchase form on an item detail page', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create();
    StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

    $this->actingAs($user)
        ->get(route('store.items.show', $item))
        ->assertOk()
        ->assertSee(route('store.items.purchase', $item), false);
});

test('a sold-out item shows no purchase action', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['stock_limit' => 1]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $seller = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($seller, $price->currency, 100, 'test');
    app(\App\Services\Store\StorePurchaseService::class)->purchase($seller, $item, $price, (string) Str::uuid());

    $this->actingAs($user)
        ->get(route('store.items.show', $item))
        ->assertOk()
        ->assertSee('نفدت الكمية')
        ->assertDontSee(route('store.items.purchase', $item), false);
});

test('no payment/checkout/webhook routes exist anywhere in the application except the E32 sponsorship donation surface', function () {
    // E32: المتجر والاقتصاد الداخلي (الجواهر) بلا دفع حقيقي. السطح الوحيد المسموح له بمسارات دفع هو دعم الرعاية بالتحويل اليدوي
    // (يتحقق منه مراجع مالي) + لوحة إدارته + Webhook المزوّدين المستقبلي المغلق افتراضيًا. قائمة السماح صريحة وضيقة بالمسار لا بالكلمة.
    // E41: التجارة الاستهلاكية (شراء المسار المميز) سطح دفع ثانٍ مسموح بمساره الضيق فقط: بدء الدفع المستضاف وwebhook المزوّد. لا يشمل أي مسار للمتجر (store.*).
    $e32Surface = ['support/', 'payments/staff/', 'api/payments/webhooks/', 'admin/sponsorship-payments', 'admin/payment-settings', 'commerce/checkout/', 'api/commerce/webhooks/'];

    $routeUris = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->map(fn ($route) => $route->uri())
        ->reject(fn ($uri) => collect($e32Surface)->contains(fn ($prefix) => str_starts_with($uri, $prefix)));

    foreach (['checkout', 'payment', 'webhook'] as $forbidden) {
        expect($routeUris->contains(fn ($uri) => str_contains($uri, $forbidden)))->toBeFalse();
    }

    // المتجر نفسه لا يحمل أي مسار دفع، حتى داخل قائمة السماح.
    expect(collect(\Illuminate\Support\Facades\Route::getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'store.') && preg_match('/checkout|payment|webhook/', $route->uri()))->count())->toBe(0);
});