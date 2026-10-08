<?php

require_once __DIR__.'/UiTestHelpers.php';
require_once __DIR__.'/../Social/SocialTestHelpers.php';

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\UserInventoryItem;
use App\Services\Economy\CurrencyWalletService;
use App\Support\WalletReasonLabel;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

function uiTexts(DOMXPath $x, string $query): array
{
    $out = [];

    foreach ($x->query($query) as $node) {
        $out[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    return $out;
}

dataset('wallet reasons', [
    'solved puzzle' => ['solved_puzzle:76', 'حلّ أحجية'],
    'daily quest' => ['quest:daily_solve_2', 'مهمة يومية'],
    'weekly quest' => ['quest:weekly_solve_1', 'مهمة أسبوعية'],
    'other quest' => ['quest:something_else', 'إكمال مهمة'],
    'achievement' => ['achievement:first_puzzle', 'مكافأة إنجاز'],
    'store purchase' => ['store_purchase:demo-frame-gold', 'شراء من المتجر'],
    'hint' => ['hint:12', 'شراء تلميح'],
    'redemption' => ['redemption_request:9', 'طلب استبدال'],
    'level' => ['level:7', 'ترقية إلى المستوى 7'],
    'competition reward' => ['competitive_event_reward', 'جائزة منافسة'],
]);

test('W1: known wallet reason codes become readable Arabic labels and the code is preserved', function (string $code, string $label) {
    $d = WalletReasonLabel::describe($code);

    expect($d['label'])->toBe($label)->and($d['raw'])->toBe($code)->and($d['known'])->toBeTrue();
})->with('wallet reasons');

test('W2: unknown or free-text reasons are shown exactly as stored and empty reasons never crash', function () {
    expect(WalletReasonLabel::describe('تسوية يدوية من الإدارة'))->toBe(['label' => 'تسوية يدوية من الإدارة', 'raw' => 'تسوية يدوية من الإدارة', 'known' => false])
        ->and(WalletReasonLabel::describe('level:abc')['known'])->toBeFalse()
        ->and(WalletReasonLabel::describe(null)['label'])->toBe('معاملة')
        ->and(WalletReasonLabel::describe('  ')['known'])->toBeFalse();
});

test('W3: the wallet page lists readable labels with the raw code as secondary text, and stays free of emoji', function () {
    $user = e16User();
    $currency = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency();
    $wallets = app(CurrencyWalletService::class);
    $wallets->creditAvailable($user, $currency, 50, 'solved_puzzle:76');
    $wallets->creditAvailable($user, $currency, 20, 'تسوية يدوية من الإدارة');

    $html = $this->actingAs($user)->get(route('wallet.index'))->assertOk()->getContent();
    $x = uiXpath($html);
    $labels = uiTexts($x, '//*[@data-tx-label]');

    expect($labels)->toContain('حلّ أحجية')->toContain('تسوية يدوية من الإدارة')
        ->and(uiTexts($x, '//*[@data-tx-raw]'))->toBe(['solved_puzzle:76'])
        ->and($x->query('//h1')->length)->toBe(1)
        ->and(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', implode(' ', uiTexts($x, '//main'))))->toBe(0);
});

test('W4: an empty wallet history shows one unified empty state with a clear action', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('wallet.index'))->assertOk()->getContent());

    expect(uiTexts($x, '//h3'))->toContain('لا توجد معاملات بعد')
        ->and(uiAttrs($x, '//main//a[contains(@class,"btn-gem")]', 'href'))->toContain(route('puzzles.index'));
});

test('I1: the inventory shows real entitlements (the old template repeated inventory items there)', function () {
    $user = e16User();
    $item = StoreItem::factory()->entitlement('season.vip')->create(['name' => 'تذكرة الموسم']);
    UserEntitlement::factory()->create(['user_id' => $user->id, 'store_item_id' => $item->id, 'key' => 'season.vip']);
    $owned = StoreItem::factory()->create(['name' => 'حزمة مقتنى']);
    UserInventoryItem::factory()->create(['user_id' => $user->id, 'store_item_id' => $owned->id, 'quantity' => 2]);

    $x = uiXpath($this->actingAs($user)->get(route('inventory.index'))->assertOk()->getContent());
    $ent = uiTexts($x, '//*[@data-entitlement]');

    expect($ent)->toHaveCount(1)
        ->and($ent[0])->toContain('تذكرة الموسم')->toContain('فعّال')->not->toContain('حزمة مقتنى')
        ->and(implode(' ', uiTexts($x, '//*[@data-inventory="items"]')))->toContain('حزمة مقتنى');
});

test('I2: a revoked entitlement is labelled as cancelled and an empty inventory uses the unified empty states', function () {
    $user = e16User();
    $item = StoreItem::factory()->entitlement('season.vip')->create(['name' => 'تذكرة ملغاة']);
    UserEntitlement::factory()->create(['user_id' => $user->id, 'store_item_id' => $item->id, 'key' => 'season.vip', 'revoked_at' => now()]);

    $ent = uiTexts(uiXpath($this->actingAs($user)->get(route('inventory.index'))->assertOk()->getContent()), '//*[@data-entitlement]');
    expect($ent[0])->toContain('ملغى');

    $empty = uiTexts(uiXpath($this->actingAs(e16User())->get(route('inventory.index'))->assertOk()->getContent()), '//h3');
    expect($empty)->toContain('لم تحصل على عناصر بعد')->toContain('لا توجد امتيازات حاليًا')->toContain('لا توجد مشتريات بعد');
});

test('R1: the redemption page keeps its eligibility note and uses the shared empty state', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('redemption.index'))->assertOk()->getContent());

    expect(uiTexts($x, '//h3'))->toContain('لا توجد طلبات استبدال بعد')
        ->and($x->query('//*[@role="note"]')->length)->toBe(1)
        ->and(uiAttrs($x, '//main//a', 'href'))->toContain(route('redemption.create'));
});

test('T1: the teams index action chips use SVG icons instead of emoji and keep every link', function () {
    $user = e16User();
    $html = $this->actingAs($user)->get(route('teams.index'))->assertOk()->getContent();
    $x = uiXpath($html);
    $hrefs = uiAttrs($x, '//main//a', 'href');

    expect($hrefs)->toContain(route('teams.leaderboard'))->toContain(route('team-championships.index'))
        ->toContain(route('teams.challenges.index'))->toContain(route('teams.invitations'))
        ->toContain(route('teams.mine'))->toContain(route('teams.create'))
        ->and(preg_match('/[🏅🏆⚔✉]/u', implode(' ', uiTexts($x, '//main//header//a'))))->toBe(0);
});

test('T2: an empty team search keeps the exact legacy empty-state sentence', function () {
    $html = $this->actingAs(e16User())->get(route('teams.index', ['q' => 'zzzz-no-such-team']))->assertOk()->getContent();

    expect(uiTexts(uiXpath($html), '//h3'))->toContain('لا فرق مطابقة بعد.');
});

test('T3: the championships index uses the shared header with a back link to the teams', function () {
    $x = uiXpath($this->actingAs(e16User())->get(route('team-championships.index'))->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)
        ->and(uiAttrs($x, '//main//header//a', 'href'))->toContain(route('teams.index'))
        ->and(uiTexts($x, '//h3'))->toContain('لا بطولات جارية الآن');
});

test('B1: the store page shows its shared header and quick links only to signed-in players', function () {
    $guest = uiXpath($this->get(route('store.index'))->assertOk()->getContent());
    $auth = uiXpath($this->actingAs(e16User())->get(route('store.index'))->assertOk()->getContent());

    expect(uiAttrs($guest, '//main//header//a', 'href'))->not->toContain(route('inventory.index'))
        ->and(uiAttrs($auth, '//main//header//a', 'href'))->toContain(route('inventory.index'))->toContain(route('wallet.index'))
        ->and($guest->query('//h1')->length)->toBe(1);
});
