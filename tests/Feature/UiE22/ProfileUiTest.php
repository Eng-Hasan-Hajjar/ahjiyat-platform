<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\StoreItem;
use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

function uiOwn(User $user, StoreItem $item, bool $equip = false): StoreItem
{
    app(InventoryService::class)->grant($user, $item, 1, 'ui-test');
    $equip && app(CosmeticLoadoutService::class)->equip($user, $item);

    return $item;
}

test('P1: the owner sees the identity and account CTAs in the profile hero; a visitor never does', function () {
    $owner = e16User();
    $visitor = e16User();

    $own = uiXpath($this->actingAs($owner)->get(route('players.show', $owner))->assertOk()->getContent());
    $other = uiXpath($this->actingAs($visitor)->get(route('players.show', $owner))->assertOk()->getContent());

    expect(uiAttrs($own, '//*[@id="profile-hero"]//a[@data-cta="customize-identity"]', 'href'))->toBe([route('profile.customize')])
        ->and(uiAttrs($own, '//*[@id="profile-hero"]//a[@data-cta="edit-account"]', 'href'))->toBe([route('profile.edit')])
        ->and($other->query('//*[@data-cta="customize-identity"]')->length)->toBe(0)
        ->and($other->query('//*[@data-cta="edit-account"]')->length)->toBe(0);
});

test('P2: the message CTA appears only for accepted friends - not strangers, not blocked users, not the owner', function () {
    [$a, $b] = chatFriends();
    $stranger = e16User();
    $cta = fn (User $viewer, User $profile) => uiAttrs(uiXpath($this->actingAs($viewer)->get(route('players.show', $profile))->getContent()), '//*[@data-cta="message"]', 'href');

    expect($cta($b, $a))->toBe([route('messages.direct', $a)])
        ->and($cta($stranger, $a))->toBe([])
        ->and($cta($a, $a))->toBe([]);

    chatBlocks()->block($a, $b);

    expect($cta($b, $a))->toBe([])->and($cta($a, $b))->toBe([]);
});

test('P3: the hero shows the identity facts - name, level, public team, friends count - and no private data', function () {
    $owner = e16User(['name' => 'ليلى الحمدان']);
    $team = e19Team($owner, ['name' => 'فريق الأمل']);
    e16Befriend($owner, e16User());

    $response = $this->actingAs(e16User())->get(route('players.show', $owner))->assertOk()
        ->assertSee('ليلى الحمدان')->assertSee('المستوى')->assertSee('فريق الأمل')->assertSee('الأصدقاء 1')
        ->assertDontSee($owner->email)->assertDontSee('رصيد الجواهر');

    expect(uiAttrs(uiXpath($response->getContent()), '//*[@id="profile-hero"]//a', 'href'))->toContain(route('teams.show', $team));
});

test('P4: a private profile stays hidden from others, and its owner gets a note linking to the privacy settings', function () {
    $owner = e16User(['profile_visibility' => User::VISIBILITY_PRIVATE]);

    $this->actingAs(e16User())->get(route('players.show', $owner))->assertNotFound();

    $x = uiXpath($this->actingAs($owner)->get(route('players.show', $owner))->assertOk()->getContent());
    expect(uiAttrs($x, '//*[@role="note"]//a', 'href'))->toBe([route('profile.edit').'#privacy']);
});

test('P5: the customization page names the five slots (the background is the cover) and explains empty slots with a store link', function () {
    $user = e16User();
    $html = $this->actingAs($user)->get(route('profile.customize'))->assertOk()->getContent();
    $x = uiXpath($html);

    expect($x->query('//*[@role="tab"]')->length)->toBe(5)->and($x->query('//*[@role="tabpanel"]')->length)->toBe(5);

    foreach (['الصورة الرمزية', 'الإطار', 'الشارة', 'اللقب', 'الغلاف'] as $label) {
        expect($html)->toContain($label);
    }

    expect(uiAttrs($x, '//*[@role="tabpanel"]//a', 'href'))->toContain(route('store.index'))
        ->and($html)->toContain('لا تملك أي عنصر');
});

test('P6: owned cosmetics keep the same equip and unequip forms and routes - and the live preview uses the real slot names', function () {
    $user = e16User();
    $avatar = uiOwn($user, StoreItem::factory()->cosmeticAvatar()->create());
    $frame = uiOwn($user, StoreItem::factory()->cosmeticFrame()->create(), equip: true);

    $html = $this->actingAs($user)->get(route('profile.customize'))->assertOk()->getContent();
    $x = uiXpath($html);
    $actions = uiAttrs($x, '//article//form', 'action');

    expect($actions)->toContain(route('profile.cosmetics.equip', $avatar))->toContain(route('profile.cosmetics.unequip', StoreItem::SLOT_FRAME))
        ->and($x->query('//article//form//input[@name="_token"]')->length)->toBe(2)
        ->and($x->query('//article//form//input[@name="_method"][@value="DELETE"]')->length)->toBe(1)
        ->and($html)->toContain('profile_frame')->toContain('profile_background')->toContain('المجهَّز حاليًا: '.$frame->name);
});

test('P7: equipping through the unchanged route still changes the loadout and the profile reflects it', function () {
    $user = e16User();
    $avatar = uiOwn($user, StoreItem::factory()->cosmeticAvatar()->create());

    $this->actingAs($user)->post(route('profile.cosmetics.equip', $avatar))->assertRedirect();

    expect(app(CosmeticLoadoutService::class)->equippedForSlot($user, StoreItem::SLOT_AVATAR)?->id)->toBe($avatar->id);
    $this->actingAs($user)->get(route('players.show', $user))->assertOk()->assertSee($avatar->image_path, false);
});

test('P8: the settings tabs link account, identity and privacy, mark the current page, and the privacy anchor and form are unchanged', function () {
    $user = e16User();
    $account = uiXpath($this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent());
    $identity = uiXpath($this->actingAs($user)->get(route('profile.customize'))->getContent());

    expect(uiAttrs($account, '//nav[@aria-label="إعدادات الملف الشخصي"]/a', 'href'))->toBe([route('profile.edit'), route('profile.customize'), route('profile.edit').'#privacy'])
        ->and(uiAttrs($account, '//nav[@aria-label="إعدادات الملف الشخصي"]/a[@aria-current="page"]', 'href'))->toBe([route('profile.edit')])
        ->and(uiAttrs($identity, '//nav[@aria-label="إعدادات الملف الشخصي"]/a[@aria-current="page"]', 'href'))->toBe([route('profile.customize')])
        ->and($account->query('//*[@id="privacy"]')->length)->toBe(1)
        ->and(uiAttrs($account, '//*[@id="privacy"]//form', 'action'))->toBe([route('profile.visibility.update')]);
});

test('P9: identity customization stays behind email verification, and unverified players do not get its tab', function () {
    $user = e16User(['email_verified_at' => null]);

    $this->actingAs($user)->get(route('profile.customize'))->assertRedirect();

    $x = uiXpath($this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent());
    expect(uiAttrs($x, '//nav[@aria-label="إعدادات الملف الشخصي"]/a', 'href'))->toBe([route('profile.edit'), route('profile.edit').'#privacy']);
});

test('P10: an equipped background becomes the hero cover, and without one the cover falls back to the theme gradient', function () {
    $user = e16User();
    $cover = fn () => uiAttrs(uiXpath($this->actingAs($user)->get(route('players.show', $user))->getContent()), '//*[@data-profile-cover]', 'style');

    expect($cover())->toBe(['']);

    uiOwn($user, StoreItem::factory()->cosmeticBackground()->create(), equip: true);

    expect($cover()[0])->toContain('background-image');
});
