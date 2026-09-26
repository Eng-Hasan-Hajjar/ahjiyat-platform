<?php

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('an active, available item appears on the public store index', function () {
    $item = StoreItem::factory()->create(['is_active' => true, 'name' => 'عنصر ظاهر']);
    StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

    $this->get(route('store.index'))->assertOk()->assertSee('عنصر ظاهر');
});

test('an inactive item is hidden from the public store index and its detail page 404s', function () {
    $item = StoreItem::factory()->create(['is_active' => false, 'name' => 'عنصر مخفي']);

    $this->get(route('store.index'))->assertOk()->assertDontSee('عنصر مخفي');
    $this->get(route('store.items.show', $item))->assertNotFound();
});

test('an item whose starts_at is in the future is hidden and unpurchasable', function () {
    $item = StoreItem::factory()->create(['is_active' => true, 'starts_at' => now()->addWeek(), 'name' => 'عنصر مستقبلي']);

    $this->get(route('store.index'))->assertOk()->assertDontSee('عنصر مستقبلي');
    $this->get(route('store.items.show', $item))->assertNotFound();
    expect($item->isPurchasable())->toBeFalse();
});

test('an item whose ends_at has passed is hidden and unpurchasable', function () {
    $item = StoreItem::factory()->create(['is_active' => true, 'ends_at' => now()->subDay(), 'name' => 'عنصر منتهٍ']);

    $this->get(route('store.index'))->assertOk()->assertDontSee('عنصر منتهٍ');
    $this->get(route('store.items.show', $item))->assertNotFound();
    expect($item->isPurchasable())->toBeFalse();
});