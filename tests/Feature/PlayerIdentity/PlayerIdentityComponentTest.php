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

test('a title containing script tags is rendered escaped, never as executable HTML', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $title = StoreItem::factory()->cosmeticTitle('<script>alert(1)</script>')->create();
    $this->inventory->grant($user, $title, 1, 'test');
    $this->loadouts->equip($user, $title);

    $response = $this->get(route('players.show', $user));

    $response->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});

test('a valid hex color is applied to the rendered title', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $title = StoreItem::factory()->cosmeticTitle('صياد الأسرار', '#1A2B3C')->create();
    $this->inventory->grant($user, $title, 1, 'test');
    $this->loadouts->equip($user, $title);

    $this->get(route('players.show', $user))->assertOk()->assertSee('#1A2B3C', false);
});

test('an invalid color value never reaches the rendered style attribute - falls back to a safe default', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $title = StoreItem::factory()->cosmeticTitle('لقب بلون خطر')->create();
    $title->forceFill(['cosmetic_color' => 'javascript:alert(1)'])->saveQuietly();
    $this->inventory->grant($user, $title, 1, 'test');
    $this->loadouts->equip($user, $title);

    $this->get(route('players.show', $user))
        ->assertOk()
        ->assertDontSee('javascript:alert(1)', false);
});

test('a profile with no cosmetics equipped renders cleanly with initials, no broken image', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC, 'name' => 'مستخدم عادي']);

    $this->get(route('players.show', $user))
        ->assertOk()
        ->assertSee('م')
        ->assertDontSee('.png"><', false);
});

test('a fully equipped profile renders the avatar, frame, badge, and title all together', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $avatar = StoreItem::factory()->cosmeticAvatar()->create(['image_path' => 'store-items/avatar-test.png', 'name' => 'أفاتار الاختبار']);
    $badge = StoreItem::factory()->cosmeticBadge()->create(['image_path' => 'store-items/badge-test.png', 'name' => 'شارة الاختبار']);
    $title = StoreItem::factory()->cosmeticTitle('بطل الأحجيات')->create();

    foreach ([$avatar, $badge, $title] as $item) {
        $this->inventory->grant($user, $item, 1, 'test');
        $this->loadouts->equip($user, $item);
    }

    $this->get(route('players.show', $user))
        ->assertOk()
        ->assertSee('avatar-test.png', false)
        ->assertSee('badge-test.png', false)
        ->assertSee('بطل الأحجيات');
});