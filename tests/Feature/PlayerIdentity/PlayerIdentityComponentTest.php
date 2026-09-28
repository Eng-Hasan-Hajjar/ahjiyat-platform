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

test('E11.1 req 148: markup that reached storage through a raw write is still rendered escaped, never executable', function () {
    $user = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    $title = StoreItem::factory()->cosmeticTitle('لقب')->create();
    // الحارس يرفض الوسوم عند الكتابة (اختبار CosmeticDomainInvariantsTest) - هنا نحاكي بيانات خام سابقة لاختبار طبقة العرض وحدها.
    \Illuminate\Support\Facades\DB::table('store_items')->where('id', $title->id)->update(['cosmetic_text' => '<script>alert(1)</script>']);
    $this->inventory->grant($user, $title, 1, 'test');
    $this->loadouts->equip($user, $title->fresh());

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

test('x-player-identity merges caller classes with its default classes', function () {
    $html = \Illuminate\Support\Facades\Blade::render('<x-player-identity name="لاعب" class="anim-fade-up custom-marker" />');

    expect($html)->toContain('puzzle-card')
        ->and($html)->toContain('anim-fade-up')
        ->and($html)->toContain('custom-marker');
});

test('x-player-avatar merges caller classes with its default classes', function () {
    $html = \Illuminate\Support\Facades\Blade::render('<x-player-avatar name="لاعب" class="ring-4 custom-marker" />');

    expect($html)->toContain('inline-grid')
        ->and($html)->toContain('ring-4')
        ->and($html)->toContain('custom-marker');
});

test('identity views never use unescaped blade output', function () {
    foreach (['components/player-identity', 'components/player-avatar', 'players/show', 'profile/customize', 'leaderboard/index'] as $view) {
        expect(file_get_contents(resource_path("views/{$view}.blade.php")))->not->toContain('{!!');
    }
});