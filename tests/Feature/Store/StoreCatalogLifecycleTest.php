<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Support\Str;

/**
 * E11.2: قفل نطاق موحَّد لأي StoreItem مُستخدَمة فعليًا - كل الأنواع، لا
 * التجميلي فقط. هذه الملفات تختبر physical/digital/manual/entitlement -
 * الحالة التجميلية مُغطَّاة أصلاً بـtests/Feature/PlayerIdentity/Cosmetic*.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->inventory = app(InventoryService::class);
    $this->entitlements = app(EntitlementService::class);
    $this->purchases = app(StorePurchaseService::class);
    $this->wallets = app(CurrencyWalletService::class);
});

// ===== بند 13: عنصر physical/manual له شراء - محاولة تحويله لـdigital =====

test('E11.2 req 13: a physical/manual item with a purchase cannot change item_type - value stays unchanged in the DB', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->manual()->create(); // TYPE_PHYSICAL + FULFILLMENT_MANUAL
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect(fn () => $item->fresh()->update(['item_type' => StoreItem::TYPE_DIGITAL]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->item_type)->toBe(StoreItem::TYPE_PHYSICAL);
});

test('a physical/manual item with a purchase also cannot silently lose its type via fill()->save()', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->manual()->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    $fresh = $item->fresh();
    $fresh->fill(['item_type' => StoreItem::TYPE_ACCESS]);

    expect(fn () => $fresh->save())->toThrow(StoreItemInvariantViolation::class);
    expect($item->fresh()->item_type)->toBe(StoreItem::TYPE_PHYSICAL);
});

// ===== بند 14: عنصر inventory له شراء - محاولة تحويله لـmanual =====

test('E11.2 req 14: an inventory item with a purchase cannot change fulfillment_type to manual', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect(fn () => $item->fresh()->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->fulfillment_type)->toBe(StoreItem::FULFILLMENT_INVENTORY);
});

test('ownership alone (a direct grant with no purchase at all) is enough to lock fulfillment_type', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $this->inventory->grant($user, $item, 1, 'gift'); // بلا أي StorePurchase - منح مباشر

    expect(fn () => $item->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]))
        ->toThrow(StoreItemInvariantViolation::class);
});

// ===== بند 15: عنصر entitlement له امتياز مُمنوح - محاولة تغيير المفتاح =====

test('E11.2 req 15: an entitlement item with a granted entitlement cannot change entitlement_key', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip.2027')->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 100, 'test');
    $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect(fn () => $item->fresh()->update(['entitlement_key' => 'season.vip.2028']))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->entitlement_key)->toBe('season.vip.2027');
});

test('changing entitlement_key while entitlements exist would otherwise let a holder buy a duplicate under the new key - this is exactly what the lock prevents', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip.old')->create();
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect($this->entitlements->hasActive($user, 'season.vip.old'))->toBeTrue();

    // محاولة التلاعب نفسها محظورة عند الكتابة - لا مسار لإنشاء التعارض أصلاً.
    expect(fn () => $item->fresh()->update(['entitlement_key' => 'season.vip.new']))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('entitlement_key can still change freely before any entitlement is granted from it', function () {
    $item = StoreItem::factory()->entitlement('draft.key')->create();

    $item->update(['entitlement_key' => 'final.key']);

    expect($item->fresh()->entitlement_key)->toBe('final.key');
});

test('a granted-then-revoked entitlement still keeps entitlement_key locked - history is not erased by revocation', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $item = StoreItem::factory()->entitlement('season.vip')->create();
    $entitlement = $this->entitlements->grant($user, $item);
    $this->entitlements->revoke($entitlement, $admin, 'اختبار');

    expect(fn () => $item->update(['entitlement_key' => 'season.vip.changed']))
        ->toThrow(StoreItemInvariantViolation::class);
});

// ===== بند 16: عنصر غير مُستخدَم - التغيير مسموح إن كان صالحًا =====

test('E11.2 req 16: an unused item can freely change item_type and fulfillment_type to any valid combination', function () {
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);

    $item->update(['item_type' => StoreItem::TYPE_ACCESS, 'fulfillment_type' => StoreItem::FULFILLMENT_ENTITLEMENT, 'entitlement_key' => 'new.key']);

    $fresh = $item->fresh();
    expect($fresh->item_type)->toBe(StoreItem::TYPE_ACCESS)
        ->and($fresh->fulfillment_type)->toBe(StoreItem::FULFILLMENT_ENTITLEMENT)
        ->and($fresh->entitlement_key)->toBe('new.key');
});

test('an unused item still cannot change into an invalid combination - e.g. cosmetic with non-inventory fulfillment', function () {
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]);

    expect(fn () => $item->update(['item_type' => StoreItem::TYPE_COSMETIC]))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('an unused item can freely change entitlement_key too', function () {
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_ENTITLEMENT, 'entitlement_key' => 'a']);

    $item->update(['entitlement_key' => 'b']);

    expect($item->fresh()->entitlement_key)->toBe('b');
});

// ===== بند 9: grant_quantity - تأكيد فعلي أن الاسترجاع يعتمد Snapshot لا القيمة الحية =====

test('req 9 confirmation: changing grant_quantity after a purchase affects only future purchases - refund of the old one uses its own snapshot', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'grant_quantity' => 3]);
    $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id, 'amount' => 10]);
    $this->wallets->creditAvailable($user, $price->currency, 1000, 'test');
    $oldPurchase = $this->purchases->purchase($user, $item, $price, (string) Str::uuid());

    expect($this->inventory->quantityFor($user, $item))->toBe(3);

    // تغيير الكمية المستقبلية لا يُقفَل - يؤثر فقط على مشتريات جديدة.
    $item->update(['grant_quantity' => 10]);

    $secondUser = User::factory()->create();
    $this->wallets->creditAvailable($secondUser, $price->currency, 1000, 'test');
    $this->purchases->purchase($secondUser, $item, $price->fresh(), (string) Str::uuid());
    expect($this->inventory->quantityFor($secondUser, $item))->toBe(10);

    // استرجاع الشراء القديم يعتمد Snapshot (3) لا القيمة الحية (10 الآن).
    $admin = User::factory()->create();
    $this->purchases->refund($oldPurchase, $admin, 'اختبار');
    expect($this->inventory->quantityFor($user, $item))->toBe(0);
});

// ===== بند 11: الحذف =====

test('E11.2 req 11: a used store item cannot be deleted directly via Eloquent - a clear domain error, not a raw FK exception', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $this->inventory->grant($user, $item, 1, 'test');

    expect(fn () => $item->delete())->toThrow(StoreItemInvariantViolation::class);
    expect(StoreItem::find($item->id))->not->toBeNull();
});

test('an unused store item can be deleted normally', function () {
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);

    $item->delete();

    expect(StoreItem::find($item->id))->toBeNull();
});

// ===== بند 15 المتبقي: Filament UX يعكس القفل الجديد =====

test('an authorized admin sees the locked entitlement_key rendered by the resource form without a server error', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('administrator');
    $item = StoreItem::factory()->entitlement('season.vip')->create();
    $this->entitlements->grant($user, $item);

    expect($item->fresh()->isEntitlementKeyLocked())->toBeTrue();

    $this->actingAs($admin)->get("/admin/store-items/{$item->id}/edit")->assertOk();
});