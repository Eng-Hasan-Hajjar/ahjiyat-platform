<?php

use App\Models\StoreItem;
use App\Models\StoreItemPrice;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('E11.1 req 160: the store shows the cosmetic slot label on both the index and the detail page', function (string $slot, string $label) {
    $item = StoreItem::factory()->create([
        'item_type' => StoreItem::TYPE_COSMETIC,
        'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
        'cosmetic_slot' => $slot,
        'cosmetic_text' => $slot === StoreItem::SLOT_TITLE ? 'المحقق' : null,
        'image_path' => $slot === StoreItem::SLOT_TITLE ? null : 'store-items/label-test.png',
        'is_active' => true,
    ]);
    StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

    $this->get(route('store.index'))->assertOk()->assertSee($label);
    $this->get(route('store.items.show', $item))->assertOk()->assertSee($label);
})->with([
    'avatar' => [StoreItem::SLOT_AVATAR, 'صورة رمزية'],
    'frame' => [StoreItem::SLOT_FRAME, 'إطار'],
    'badge' => [StoreItem::SLOT_BADGE, 'شارة'],
    'title' => [StoreItem::SLOT_TITLE, 'لقب'],
    'background' => [StoreItem::SLOT_BACKGROUND, 'خلفية ملف'],
]);

test('a legacy cosmetic with no slot falls back to the generic cosmetic label', function () {
    $item = StoreItem::factory()->legacyCosmeticWithoutSlot()->create(['is_active' => true]);
    StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

    $this->get(route('store.index'))->assertOk()->assertSee('تجميلي');
});