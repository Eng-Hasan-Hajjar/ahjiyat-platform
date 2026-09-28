<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->inventory = app(InventoryService::class);
    $this->loadouts = app(CosmeticLoadoutService::class);
});

test('E11.1 req 143: deactivating a store item stops selling it but the owner keeps it and its equipped state', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticAvatar()->create(['name' => 'أفاتار موسمي معطَّل']);
    $this->inventory->grant($user, $item, 1, 'test');
    $this->loadouts->equip($user, $item);

    $item->update(['is_active' => false]);

    expect($item->fresh()->isPurchasable())->toBeFalse()
        ->and($this->inventory->quantityFor($user, $item))->toBe(1)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_AVATAR)?->id)->toBe($item->id);

    // يمكن إزالته ثم إعادة تجهيزه - المتجر يؤثر على البيع فقط لا على الملكية.
    $this->loadouts->unequip($user, StoreItem::SLOT_AVATAR);
    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_AVATAR))->toBeNull();

    $this->loadouts->equip($user, $item->fresh());
    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_AVATAR)?->id)->toBe($item->id);

    $this->actingAs($user)->get(route('profile.customize'))->assertOk()->assertSee('أفاتار موسمي معطَّل');
});

test('E11.1 req 144: when the sale window ends the owner keeps the cosmetic, its loadout, and can re-equip it', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticFrame()->create(['name' => 'إطار انتهى بيعه']);
    $this->inventory->grant($user, $item, 1, 'test');
    $this->loadouts->equip($user, $item);

    $item->update(['ends_at' => now()->subDay()]);

    expect($item->fresh()->isPubliclyVisible())->toBeFalse()
        ->and($item->fresh()->isPurchasable())->toBeFalse()
        ->and($this->inventory->quantityFor($user, $item))->toBe(1)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME)?->id)->toBe($item->id);

    $this->loadouts->unequip($user, StoreItem::SLOT_FRAME);
    $this->loadouts->equip($user, $item->fresh());

    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME)?->id)->toBe($item->id);
});

test('a sale window that has not started yet also never affects an existing owner', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $item, 1, 'test');
    $this->loadouts->equip($user, $item);

    $item->update(['starts_at' => now()->addWeek()]);

    expect($this->loadouts->equippedForSlot($user, StoreItem::SLOT_BADGE)?->id)->toBe($item->id);
});