<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\StoreItem;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

/** عنصر تجميلي صالح لأي فتحة - يبني الحقول الإلزامية الخاصة بها. */
function e111ValidCosmeticAttributes(string $slot, array $overrides = []): array
{
    return array_merge([
        'item_type' => StoreItem::TYPE_COSMETIC,
        'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
        'cosmetic_slot' => $slot,
        'cosmetic_text' => $slot === StoreItem::SLOT_TITLE ? 'المحقق' : null,
        'image_path' => $slot === StoreItem::SLOT_TITLE ? null : 'store-items/domain-test.png',
    ], $overrides);
}

// ===== بند 6: التسليم =====

test('a cosmetic item can only be created with inventory fulfillment - manual and entitlement are rejected', function () {
    foreach ([StoreItem::FULFILLMENT_MANUAL, StoreItem::FULFILLMENT_ENTITLEMENT] as $badFulfillment) {
        expect(fn () => StoreItem::factory()->create([
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => $badFulfillment,
        ]))->toThrow(StoreItemInvariantViolation::class);
    }

    expect(StoreItem::count())->toBe(0);
});

test('an existing unused cosmetic cannot be switched to manual or entitlement fulfillment', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();

    expect(fn () => $item->update(['fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]))
        ->toThrow(StoreItemInvariantViolation::class);

    expect($item->fresh()->fulfillment_type)->toBe(StoreItem::FULFILLMENT_INVENTORY);
});

// ===== بند 7: قائمة الفتحات المسموحة =====

test('every allowed slot can be created, and an arbitrary slot string is rejected', function () {
    foreach (StoreItem::COSMETIC_SLOTS as $slot) {
        StoreItem::factory()->create(e111ValidCosmeticAttributes($slot));
    }

    expect(StoreItem::count())->toBe(count(StoreItem::COSMETIC_SLOTS));

    expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes('arbitrary-slot')))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(StoreItem::count())->toBe(count(StoreItem::COSMETIC_SLOTS));
});

test('changing an unused cosmetic to an arbitrary slot is rejected and the stored slot is unchanged', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create();

    expect(fn () => $item->update(['cosmetic_slot' => 'nope']))->toThrow(StoreItemInvariantViolation::class);
    expect($item->fresh()->cosmetic_slot)->toBe(StoreItem::SLOT_BADGE);
});

test('a legacy cosmetic with a null slot can still be created - it just stays non-equippable', function () {
    $item = StoreItem::factory()->legacyCosmeticWithoutSlot()->create();

    expect($item->cosmetic_slot)->toBeNull()
        ->and($item->isCosmeticEquippable())->toBeFalse();
});

// ===== بند 8: تنظيف غير التجميلي =====

test('a non-cosmetic item never keeps cosmetic data - it is cleared on write', function () {
    $item = StoreItem::factory()->create([
        'item_type' => StoreItem::TYPE_DIGITAL,
        'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
        'cosmetic_slot' => StoreItem::SLOT_AVATAR,
        'cosmetic_text' => 'نص عالق',
        'cosmetic_color' => '#AABBCC',
    ]);

    $fresh = $item->fresh();

    expect($fresh->cosmetic_slot)->toBeNull()
        ->and($fresh->cosmetic_text)->toBeNull()
        ->and($fresh->cosmetic_color)->toBeNull();
});

test('converting an unused cosmetic into a non-cosmetic clears its cosmetic fields', function () {
    $item = StoreItem::factory()->cosmeticTitle('لقب', '#ABCDEF')->create();

    $item->update(['item_type' => StoreItem::TYPE_DIGITAL]);

    $fresh = $item->fresh();

    expect($fresh->item_type)->toBe(StoreItem::TYPE_DIGITAL)
        ->and($fresh->cosmetic_slot)->toBeNull()
        ->and($fresh->cosmetic_text)->toBeNull()
        ->and($fresh->cosmetic_color)->toBeNull();
});

// ===== بنود 14-15: اللقب والنص =====

test('a title requires plain text - missing or blank text is rejected', function () {
    expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE, ['cosmetic_text' => null])))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE, ['cosmetic_text' => '   '])))
        ->toThrow(StoreItemInvariantViolation::class);

    expect(StoreItem::count())->toBe(0);
});

test('a title is limited to 60 characters', function () {
    StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE, ['cosmetic_text' => str_repeat('ب', 60)]));

    expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE, ['cosmetic_text' => str_repeat('ب', 61)])))
        ->toThrow(StoreItemInvariantViolation::class);
});

test('E11.1 req 148: markup in a title is rejected at the write path, and nothing is persisted', function () {
    foreach (['<script>alert(1)</script>', '<b>لقب</b>', 'لقب > آخر'] as $markup) {
        expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE, ['cosmetic_text' => $markup])))
            ->toThrow(StoreItemInvariantViolation::class);
    }

    expect(StoreItem::count())->toBe(0);
});

test('a non-title slot never keeps stray text or color', function () {
    $item = StoreItem::factory()->cosmeticBadge()->create(['cosmetic_text' => 'نص عالق', 'cosmetic_color' => '#AABBCC']);

    $fresh = $item->fresh();

    expect($fresh->cosmetic_text)->toBeNull()
        ->and($fresh->cosmetic_color)->toBeNull();
});

// ===== بند 12-13 (الاختبار 149): اللون على مستوى الموديل مباشرة =====

test('E11.1 req 149: valid hex colors are accepted by a direct model write', function (string $good) {
    $item = StoreItem::factory()->cosmeticTitle('لقب', $good)->create();

    expect($item->fresh()->cosmetic_color)->toBe($good);
})->with([
    'upper' => ['#AABBCC'],
    'purple' => ['#7C3AED'],
    'lower' => ['#aabbcc'],
]);

test('E11.1 req 149: invalid colors are rejected by a direct model write, and nothing is persisted', function (string $bad) {
    expect(fn () => StoreItem::factory()->cosmeticTitle('لقب', $bad)->create())
        ->toThrow(StoreItemInvariantViolation::class);

    expect(StoreItem::count())->toBe(0);
})->with([
    'named color' => ['red'],
    'rgb function' => ['rgb(255,0,0)'],
    'gradient' => ['linear-gradient(red,blue)'],
    'url' => ['url(javascript:alert(1))'],
    'expression' => ['expression(alert(1))'],
    'short hex' => ['#FFF'],
    'markup' => ['<script>'],
    'non-hex digits' => ['#GGGGGG'],
    'trailing space' => ['#AABBCC '],
    'trailing newline' => ["#AABBCC\n"],
]);

test('an invalid color on update is rejected and the stored color is unchanged', function () {
    $item = StoreItem::factory()->cosmeticTitle('لقب', '#AABBCC')->create();

    expect(fn () => $item->update(['cosmetic_color' => 'red']))->toThrow(StoreItemInvariantViolation::class);
    expect($item->fresh()->cosmetic_color)->toBe('#AABBCC');

    $item->fresh()->update(['cosmetic_color' => '#7C3AED']);
    expect($item->fresh()->cosmetic_color)->toBe('#7C3AED');
});

// ===== بند 17-18: الصورة + التوافق مع القديم =====

test('a non-title cosmetic slot requires an image, a title does not', function () {
    foreach ([StoreItem::SLOT_AVATAR, StoreItem::SLOT_FRAME, StoreItem::SLOT_BADGE, StoreItem::SLOT_BACKGROUND] as $slot) {
        expect(fn () => StoreItem::factory()->create(e111ValidCosmeticAttributes($slot, ['image_path' => null])))
            ->toThrow(StoreItemInvariantViolation::class);
    }

    $title = StoreItem::factory()->create(e111ValidCosmeticAttributes(StoreItem::SLOT_TITLE));

    expect($title->image_path)->toBeNull()
        ->and(StoreItem::count())->toBe(1);
});

test('removing the image from a non-title cosmetic is rejected', function () {
    $item = StoreItem::factory()->cosmeticFrame()->create();

    expect(fn () => $item->update(['image_path' => null]))->toThrow(StoreItemInvariantViolation::class);
    expect($item->fresh()->image_path)->not->toBeNull();
});

test('a legacy record that already violates the image rule can still be saved for unrelated changes', function () {
    $item = StoreItem::factory()->cosmeticAvatar()->create();

    // محاكاة بيانات قديمة غير مطابقة (كتابة خام تتجاوز الحارس عمدًا).
    DB::table('store_items')->where('id', $item->id)->update(['image_path' => null]);

    $item->fresh()->update(['name' => 'اسم جديد لعنصر قديم']);

    expect($item->fresh()->name)->toBe('اسم جديد لعنصر قديم');
});