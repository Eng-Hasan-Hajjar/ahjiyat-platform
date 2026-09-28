<?php

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->loadouts = app(CosmeticLoadoutService::class);
    $this->inventory = app(InventoryService::class);
});

test('a user who owns an avatar can equip it, and the loadout points to that item', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');

    $loadout = $this->loadouts->equip($user, $avatar);

    expect($loadout->store_item_id)->toBe($avatar->id)
        ->and($loadout->slot)->toBe(StoreItem::SLOT_AVATAR)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_AVATAR)?->id)->toBe($avatar->id);
});

test('equip is rejected when the user does not own the item', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();

    expect(fn () => $this->loadouts->equip($user, $avatar))->toThrow(RuntimeException::class);
    expect(UserCosmeticLoadout::where('user_id', $user->id)->count())->toBe(0);
});

test('equip is rejected when the owned inventory row exists but quantity is zero', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');
    $this->inventory->revoke($user, $avatar, 1, 'test');

    expect(fn () => $this->loadouts->equip($user, $avatar))->toThrow(RuntimeException::class);
});

test('equip is rejected for a non-cosmetic inventory item', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY]);
    $this->inventory->grant($user, $item, 1, 'test');

    expect(fn () => $this->loadouts->equip($user, $item))->toThrow(RuntimeException::class);
});

test('equip is rejected for a cosmetic item whose fulfillment is not inventory', function () {
    $user = User::factory()->create();
    $item = StoreItem::factory()->create([
        'item_type' => StoreItem::TYPE_COSMETIC,
        'fulfillment_type' => StoreItem::FULFILLMENT_MANUAL,
        'cosmetic_slot' => StoreItem::SLOT_BADGE,
    ]);

    expect(fn () => $this->loadouts->equip($user, $item))->toThrow(RuntimeException::class);
});

test('equip is rejected safely for a legacy cosmetic with no slot defined', function () {
    $user = User::factory()->create();
    $legacy = StoreItem::factory()->legacyCosmeticWithoutSlot()->create();
    $this->inventory->grant($user, $legacy, 1, 'test');

    expect(fn () => $this->loadouts->equip($user, $legacy))->toThrow(RuntimeException::class);
});

test('equipping a second frame replaces the first - exactly one frame equipped, the new one active', function () {
    $user = User::factory()->create();
    $frameA = StoreItem::factory()->cosmeticFrame()->create();
    $frameB = StoreItem::factory()->cosmeticFrame()->create();
    $this->inventory->grant($user, $frameA, 1, 'test');
    $this->inventory->grant($user, $frameB, 1, 'test');

    $this->loadouts->equip($user, $frameA);
    $this->loadouts->equip($user, $frameB);

    expect(UserCosmeticLoadout::where('user_id', $user->id)->where('slot', StoreItem::SLOT_FRAME)->count())->toBe(1)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME)?->id)->toBe($frameB->id);
});

test('an avatar item can never be equipped into the frame slot - cross-slot is impossible by design', function () {
    $user = User::factory()->create();
    $avatar = StoreItem::factory()->cosmeticAvatar()->create();
    $this->inventory->grant($user, $avatar, 1, 'test');

    $this->loadouts->equip($user, $avatar);

    expect(UserCosmeticLoadout::where('user_id', $user->id)->first()->slot)->toBe(StoreItem::SLOT_AVATAR)
        ->and($this->loadouts->equippedForSlot($user, StoreItem::SLOT_FRAME))->toBeNull();
});

test('unequip clears the loadout row without touching ownership at all', function () {
    $user = User::factory()->create();
    $badge = StoreItem::factory()->cosmeticBadge()->create();
    $this->inventory->grant($user, $badge, 1, 'test');
    $this->loadouts->equip($user, $badge);

    $this->loadouts->unequip($user, StoreItem::SLOT_BADGE);

    expect(UserCosmeticLoadout::where('user_id', $user->id)->count())->toBe(0)
        ->and($this->inventory->quantityFor($user, $badge))->toBe(1);
});

test('loadoutFor returns all five slots with null for anything not equipped', function () {
    $user = User::factory()->create();

    $loadout = $this->loadouts->loadoutFor($user);

    expect($loadout)->toHaveKeys(StoreItem::COSMETIC_SLOTS);
    foreach ($loadout as $item) {
        expect($item)->toBeNull();
    }
});