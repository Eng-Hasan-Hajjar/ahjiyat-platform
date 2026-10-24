<?php

use App\Filament\Pages\AhjiyatPassAnalyticsPage;
use App\Filament\Pages\AhjiyatPassBuilderPage;
use App\Filament\Pages\AhjiyatPassesPage;
use App\Filament\Pages\AhjiyatPassPurchasesPage;
use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassMission;
use App\Models\AhjiyatPassTier;
use App\Models\AhjiyatPassTierReward;
use App\Models\DigitalPurchase;
use App\Models\StoreItem;
use App\Services\AhjiyatPass\PassAnalyticsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E40 / AX14 الخصوصية، AX15 الإدارة (Filament): الصلاحيات، المنشئ، المكافآت غير الصالحة، قفل العتبات، التحليلات المجمّعة بلا ترتيب إنفاق.
*/

beforeEach(function () {
    e40_seed();
    Storage::fake('local');
});

const E40_ADMIN_PAGES = ['/admin/ahjiyat-passes', '/admin/ahjiyat-pass-purchases', '/admin/ahjiyat-pass-analytics'];

// ───────────────────────────── AX14: الخصوصية ─────────────────────────────

test('AX14-87: another user cannot see someone else’s purchase anywhere in the player area', function () {
    $b = e40_build();
    $owner = e40_player();
    $other = e40_player();
    $order = e40_premium()->submit($owner, e40_premium()->request($owner, $b['pass']), 'TRX-PRIVATE-77', 'ملاحظة خاصة بالمشتري');

    $this->actingAs($other)->get(route('pass.orders.show', $order->public_id))->assertNotFound();

    foreach ([route('pass.index'), route('pass.premium'), route('pass.archive')] as $url) {
        $response = $this->actingAs($other)->get($url);
        expect($response->getContent())->not->toContain($order->reference_code)->not->toContain('TRX-PRIVATE-77')->not->toContain('ملاحظة خاصة بالمشتري');
    }
});

test('AX14-88: the public profile of a premium player reveals no payment, price, reference or purchase status', function () {
    $b = e40_build(['price' => 1250, 'currency' => 'EUR']);
    $buyer = e40_player();
    $order = e40_buy_premium($buyer, $b['pass']);

    foreach ([null, e40_player()] as $viewer) {
        $request = $viewer === null ? $this : $this->actingAs($viewer);
        $html = preg_replace('#<svg\b.*?</svg>#s', '', $request->get(route('players.show', $buyer))->getContent());

        foreach ([$order->reference_code, 'TRX-', $order->public_id, 'digital_purchase', 'EUR'] as $secret) {
            expect($html)->not->toContain($secret);
        }

        expect($html)->not->toMatch('/(?<![\d.])12[.,]50(?!\d)/')->not->toMatch('/(?<![\d.])1250(?!\d)/');
    }
});

test('AX14-89: internal finance notes stay private — never shown to the buyer, in notifications or on the player page', function () {
    $b = e40_build();
    $buyer = e40_player();
    $secret = 'ملاحظة مالية داخلية سرية 998877';
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-NOTE-1', null);
    e40_premium()->verify($b['admin'], $order, $secret);

    foreach ([route('pass.index'), route('pass.premium')] as $url) {
        expect($this->actingAs($buyer)->get($url)->getContent())->not->toContain($secret);
    }

    expect($this->actingAs($buyer)->get(route('pass.orders.show', $order->public_id))->getContent())->not->toContain($secret)
        ->and(json_encode(DB::table('notifications')->get()->all(), JSON_UNESCAPED_UNICODE))->not->toContain($secret);
});

test('AX14-89b: a rejection shows the buyer only the public decision message, never the internal note', function () {
    $b = e40_build();
    $buyer = e40_player();
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-NOTE-2', null);
    e40_premium()->reject($b['admin'], $order, 'المرجع غير مطابق للتحويل.', 'اشتباه تكرار داخلي 5544');

    $html = $this->actingAs($buyer)->get(route('pass.orders.show', $order->public_id))->getContent();

    expect($html)->toContain('المرجع غير مطابق للتحويل.')->not->toContain('اشتباه تكرار داخلي 5544');
});

test('AX14-90: no card data is stored or collected anywhere in the schema, the form or the service', function () {
    foreach (['digital_purchases', 'digital_purchase_events', 'user_pass_progress'] as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            expect($column)->not->toMatch('/card|cvv|cvc|pan|iban|expiry|exp_month|exp_year|account_number|routing|swift/i');
        }
    }

    $b = e40_build();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);
    $html = $this->actingAs($user)->get(route('pass.orders.show', $order->public_id))->getContent();

    expect($html)->not->toMatch('/name="(card|cvv|cvc|number|pan|expiry)/i');
});

test('AX14-91: the order form asks for nothing secret — only reference, note and an optional proof file', function () {
    $b = e40_build();
    $user = e40_player();
    $order = e40_premium()->request($user, $b['pass']);
    $html = $this->actingAs($user)->get(route('pass.orders.show', $order->public_id))->getContent();

    preg_match_all('/<(?:input|textarea|select)[^>]*\sname="([^"]+)"/i', $html, $matches);
    $names = array_values(array_unique(array_filter($matches[1], fn ($n) => ! in_array($n, ['_token', '_method'], true))));
    sort($names);

    expect($names)->toBe(['payer_note', 'proof', 'transfer_reference'])
        ->and($html)->not->toMatch('/type="password"/i')->not->toMatch('/name="[^"]*(password|pin|otp|secret|token_code)[^"]*"/i');
});

test('AX14-92: payment proof is never exposed publicly — private disk, no player route, authorised and audited admin download only', function () {
    $b = e40_build();
    $buyer = e40_player();
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-PROOF-9', null, UploadedFile::fake()->image('proof.png', 30, 30));

    expect(Storage::disk('public')->exists($order->proof_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($order->proof_path))->toBeTrue();

    $names = Route::getRoutes()->getRoutesByName();
    expect(collect(array_keys($names))->filter(fn ($n) => str_starts_with($n, 'pass.') && str_contains($n, 'proof')))->toBeEmpty();

    $html = $this->actingAs($buyer)->get(route('pass.orders.show', $order->public_id))->getContent();
    expect($html)->not->toContain($order->proof_path)->not->toContain('storage/');

    foreach ([e40_plain_staff(), $buyer] as $outsider) {
        expect(e40_reason(fn () => e40_premium()->proofDownload($outsider, $order)))->toBe('forbidden');
    }

    $reviewer = e40_staff_with(['passes.view-purchases']);
    $response = e40_premium()->proofDownload($reviewer, $order);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and(DB::table('operational_audit_logs')->where('action', 'digital_purchase_proof_viewed')->count())->toBe(1);
});

test('AX14-93: the same proof file reused across orders raises a duplicate warning for reviewers (fraud signal, no auto-action)', function () {
    $b = e40_build();
    $one = e40_player();
    $two = e40_player();
    $source = UploadedFile::fake()->image('p.png', 30, 30);
    $bytes = file_get_contents($source->getRealPath());
    $file = fn () => UploadedFile::fake()->createWithContent('proof.png', $bytes);

    $a = e40_premium()->submit($one, e40_premium()->request($one, $b['pass']), 'TRX-DUP-1', null, $file());
    $c = e40_premium()->submit($two, e40_premium()->request($two, $b['pass']), 'TRX-DUP-2', null, $file());

    expect(e40_premium()->duplicateProofCount($a))->toBe(1)->and(e40_premium()->duplicateProofCount($c))->toBe(1)
        ->and($a->fresh()->status)->toBe('submitted')->and($c->fresh()->status)->toBe('submitted');
});

test('AX14-94: only the buyer’s own orders are listed in their archive of passes — player pages never join other users’ rows', function () {
    $b = e40_build();
    $one = e40_player();
    $two = e40_player();
    e40_premium()->request($one, $b['pass']);
    e40_premium()->request($two, $b['pass']);
    $twoOrder = DigitalPurchase::query()->where('user_id', $two->id)->first();

    expect($this->actingAs($one)->get(route('pass.index'))->getContent())->not->toContain($twoOrder->reference_code);
});

// ───────────────────────────── AX15: الإدارة ─────────────────────────────

test('AX15-93: an authorised administrator creates a draft through the admin page', function () {
    $admin = e40_admin();
    $this->actingAs($admin);

    Livewire::test(AhjiyatPassesPage::class)
        ->set('form.name', 'مسار الربيع')->set('form.internal_key', 'spring-pass')
        ->set('form.starts_at', now()->addDays(5)->format('Y-m-d\TH:i'))->set('form.ends_at', now()->addDays(35)->format('Y-m-d\TH:i'))
        ->set('form.premium_price', '4.99')->set('form.currency', 'USD')
        ->call('create');

    $pass = AhjiyatPass::query()->where('internal_key', 'spring-pass')->first();

    expect($pass)->not->toBeNull()->and($pass->status)->toBe('draft')->and($pass->premium_price_minor)->toBe(499)->and($pass->created_by)->toBe($admin->id)
        ->and($pass->locked_at)->toBeNull();
});

test('AX15-94: unauthorised staff are denied on every admin page and every service operation', function () {
    $staff = e40_plain_staff();

    foreach (E40_ADMIN_PAGES as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }

    $b = e40_build(['publish' => false]);
    $this->actingAs($staff);

    expect(e40_reason(fn () => e40_builder()->createDraft($staff, ['internal_key' => 'nope-pass', 'name' => 'x', 'starts_at' => now()->toDateTimeString()])))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_builder()->update($staff, $b['pass'], ['name' => 'x'])))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_builder()->generateTiers($staff, $b['pass'], 3, 10)))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_builder()->setTierReward($staff, $b['tiers'][1], 'free', e40_item())))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_builder()->attachMission($staff, $b['pass'], e40_quest(), 'easy')))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_life()->schedule($staff, $b['pass'])))->toBe('forbidden')
        ->and(e40_reason(fn () => e40_life()->deleteDraft($staff, $b['pass'])))->toBe('forbidden');
});

test('AX15-94b: granular permissions — a publisher cannot manage rewards, a reward manager cannot publish', function () {
    $b = e40_build(['publish' => false]);
    $publisher = e40_staff_with(['passes.publish']);
    $rewardManager = e40_staff_with(['passes.manage-rewards']);

    expect(e40_reason(fn () => e40_builder()->setTierReward($publisher, $b['tiers'][1], 'free', e40_item())))->toBe('forbidden');
    expect(e40_builder()->setTierReward($rewardManager, $b['tiers'][1], 'free', e40_item())->exists)->toBeTrue()
        ->and(e40_reason(fn () => e40_life()->schedule($rewardManager, $b['pass'])))->toBe('forbidden')
        ->and(e40_life()->schedule($publisher, $b['pass'])->locked_at)->not->toBeNull();
});

test('AX15-95: the builder attaches an existing quest to a draft with admin-set points', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'missions' => []])['pass'];
    $quest = e40_quest(['name' => 'مهمة المنشئ']);
    $this->actingAs($admin);

    Livewire::withQueryParams(['pass' => $draft->public_id])->test(AhjiyatPassBuilderPage::class)
        ->set('attach', ['quest' => (string) $quest->id, 'difficulty' => 'hard', 'points' => '150', 'max' => 4])
        ->call('attachQuest');

    $mission = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $draft->id)->first();

    expect($mission)->not->toBeNull()->and($mission->quest_definition_id)->toBe($quest->id)->and($mission->difficulty)->toBe('hard')
        ->and($mission->pass_points)->toBe(150)->and($mission->max_completions)->toBe(4);
});

test('AX15-95b: the builder can create a brand-new quest for the pass without any new evaluator', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'missions' => []])['pass'];
    $this->actingAs($admin);

    Livewire::withQueryParams(['pass' => $draft->public_id])->test(AhjiyatPassBuilderPage::class)
        ->set('nq', ['name' => 'حلّ ثلاث أحجيات', 'description' => '', 'period_type' => 'daily', 'condition_type' => 'puzzles_solved', 'target_value' => 3, 'scope_id' => '', 'difficulty' => 'medium', 'points' => '90', 'max' => 5])
        ->call('createQuest');

    $mission = AhjiyatPassMission::query()->where('ahjiyat_pass_id', $draft->id)->first();
    $quest = $mission?->quest;

    expect($quest)->not->toBeNull()->and($quest->condition_type)->toBe('puzzles_solved')->and($quest->target_value)->toBe(3)->and($quest->xp_reward)->toBe(0)
        ->and($quest->reward_store_item_id)->toBeNull();
});

test('AX15-96: the builder assigns store items to free and premium slots of a draft', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'tiers' => 3, 'free_tiers' => [], 'premium_tiers' => []])['pass'];
    $free = e40_item();
    $premium = e40_item();
    $this->actingAs($admin);

    $page = Livewire::withQueryParams(['pass' => $draft->public_id])->test(AhjiyatPassBuilderPage::class);
    $page->set('tiers.1.free_item', (string) $free->id)->set('tiers.1.premium_item', (string) $premium->id)->set('tiers.1.premium_qty', 2)->call('saveRewards');

    $tier2 = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $draft->id)->where('tier_number', 2)->first();
    $rewards = AhjiyatPassTierReward::query()->where('ahjiyat_pass_tier_id', $tier2->id)->get()->keyBy('track');

    expect($rewards['free']->store_item_id)->toBe($free->id)->and($rewards['premium']->store_item_id)->toBe($premium->id)->and($rewards['premium']->quantity)->toBe(2);
});

test('AX15-97: invalid rewards are refused — manual, consumable, inactive, expired, sellable and over-quantity', function () {
    $admin = e40_admin();
    $b = e40_build(['publish' => false, 'tiers' => 3, 'free_tiers' => [], 'premium_tiers' => []]);
    $tier = $b['tiers'][1];
    $priced = e40_item();
    \App\Models\StoreItemPrice::factory()->create(['store_item_id' => $priced->id, 'amount' => 100]);

    $cases = [
        'item_type (physical)' => ['item_type', StoreItem::factory()->manual()->create()],
        'item_type (consumable)' => ['item_type', StoreItem::factory()->create(['item_type' => StoreItem::TYPE_CONSUMABLE])],
        'item_fulfillment' => ['item_fulfillment', e40_item(['item_type' => StoreItem::TYPE_DIGITAL, 'fulfillment_type' => StoreItem::FULFILLMENT_MANUAL])],
        'item_entitlement' => ['item_entitlement', e40_entitlement_item(['entitlement_key' => null])],
        'item_inactive' => ['item_inactive', e40_item(['is_active' => false])],
        'item_expired' => ['item_expired', e40_item(['ends_at' => now()->subDay()])],
        'item_sellable' => ['item_sellable', $priced],
    ];

    foreach ($cases as $label => [$reason, $item]) {
        expect(e40_reason(fn () => e40_builder()->setTierReward($admin, $tier, 'free', $item)))->toBe($reason, $label);
    }

    expect(e40_reason(fn () => e40_builder()->setTierReward($admin, $tier, 'free', e40_item(), 99)))->toBe('quantity')
        ->and(e40_reason(fn () => e40_builder()->setTierReward($admin, $tier, 'vip', e40_item())))->toBe('track')
        ->and(AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $b['pass']->id)->count())->toBe(0);
});

test('AX15-98: a published pass is locked in the builder page too — thresholds, rewards and price cannot change', function () {
    $b = e40_build();
    $this->actingAs($b['admin']);
    $thresholds = AhjiyatPassTier::query()->where('ahjiyat_pass_id', $b['pass']->id)->orderBy('tier_number')->pluck('required_points')->all();

    $page = Livewire::withQueryParams(['pass' => $b['pass']->public_id])->test(AhjiyatPassBuilderPage::class);
    $page->set('tiers.2.points', '5')->call('saveTiers');
    $page->set('basics.premium_price_minor', 1)->call('saveBasics');
    $page->set('tiers.0.free_item', (string) e40_item()->id)->call('saveRewards');

    expect(AhjiyatPassTier::query()->where('ahjiyat_pass_id', $b['pass']->id)->orderBy('tier_number')->pluck('required_points')->all())->toBe($thresholds)
        ->and($b['pass']->fresh()->premium_price_minor)->toBe(500)
        ->and(AhjiyatPassTierReward::query()->where('ahjiyat_pass_id', $b['pass']->id)->count())->toBe(count(array_filter(array_merge(...array_map('array_values', array_map(fn ($r) => [$r['free'], $r['premium']], $b['rewards']))))));
});

test('AX15-99: analytics are aggregated numbers only and match the data', function () {
    $b = e40_build(['tiers' => 5, 'step' => 100, 'missions' => [['difficulty' => 'easy', 'points' => 100, 'max' => 14]]]);
    $a = e40_player();
    $c = e40_player();
    $d = e40_player();
    e40_grant($a, $b['pass'], $b['quests'][0], 3);
    e40_grant($c, $b['pass'], $b['quests'][0], 1);
    e40_progress()->ensure($d, $b['pass']);
    e40_buy_premium($a, $b['pass']);
    e40_claims()->collectAll($a, $b['pass']);

    $data = app(PassAnalyticsService::class)->forPass($b['pass']);

    expect($data['players'])->toBe(3)->and($data['premium_owners'])->toBe(1)->and($data['points_granted'])->toBe(400)
        ->and($data['revenue'])->toBe([['currency' => 'USD', 'minor' => 500, 'label' => $data['revenue'][0]['label'], 'count' => 1]])
        ->and($data['distribution'])->toBe([1 => 1, 2 => 1, 4 => 1]);
});

test('AX15-100: there is no spend leaderboard — no per-user data in analytics, no top-spender page or route', function () {
    $b = e40_build();
    $buyer = e40_player(null, ['name' => 'مشتري سري الاسم', 'email' => 'secret-buyer@example.test']);
    e40_buy_premium($buyer, $b['pass']);
    $data = app(PassAnalyticsService::class)->forPass($b['pass']);

    expect(json_encode($data, JSON_UNESCAPED_UNICODE))->not->toContain('secret-buyer')->not->toContain('مشتري سري الاسم');

    foreach (array_keys($data) as $key) {
        expect($key)->not->toMatch('/top|leader|rank|spender|buyers|users_list/i');
    }

    $this->actingAs(e40_admin());
    $html = Livewire::withQueryParams(['pass' => $b['pass']->public_id])->test(AhjiyatPassAnalyticsPage::class)->html();
    expect($html)->not->toContain('secret-buyer')->not->toContain('مشتري سري الاسم');

    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => preg_match('/pass.*(leader|top|spend|rank)/i', $r->uri().' '.$r->getName()))->count())->toBe(0);
});

test('AX15-101: the purchases queue is paginated and shows the right tab content', function () {
    $b = e40_build();
    config(['ahjiyat_pass.admin.purchases_per_page' => 3]);

    foreach (range(1, 8) as $i) {
        $u = e40_player();
        e40_premium()->submit($u, e40_premium()->request($u, $b['pass']), 'TRX-QUEUE-'.$i, null);
    }

    $this->actingAs(e40_staff_with(['passes.view-purchases', 'passes.verify-purchases']));
    $page = Livewire::test(AhjiyatPassPurchasesPage::class)->set('tab', 'submitted');
    $data = $page->instance()->data();

    expect($data['page']->perPage())->toBe(3)->and($data['page']->total())->toBe(8)->and(count($data['page']->items()))->toBe(3);
});

test('AX15-102: finance actions through the page respect permissions — a viewer can look but not verify', function () {
    $b = e40_build();
    $buyer = e40_player();
    $order = e40_premium()->submit($buyer, e40_premium()->request($buyer, $b['pass']), 'TRX-PERM-1', null);
    $this->actingAs(e40_staff_with(['passes.view-purchases']));

    Livewire::test(AhjiyatPassPurchasesPage::class)->call('show', $order->public_id)->call('verify');
    expect($order->fresh()->status)->toBe('submitted')->and(e40_row($buyer, $b['pass'])?->premium_unlocked_at)->toBeNull();

    $this->actingAs(e40_staff_with(['passes.view-purchases', 'passes.verify-purchases']));
    Livewire::test(AhjiyatPassPurchasesPage::class)->call('show', $order->public_id)->set('reviewNote', 'مطابقة المبلغ')->call('verify');

    expect($order->fresh()->status)->toBe('verified')->and(e40_row($buyer, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});

test('AX15-103: the lifecycle page schedules a ready draft, and explains problems for an unready one', function () {
    $admin = e40_admin();
    $ready = e40_build(['publish' => false]);
    $unready = e40_builder()->createDraft($admin, ['internal_key' => 'unready-pass', 'name' => 'ناقص', 'starts_at' => now()->addDay()->toDateTimeString(), 'premium_enabled' => false]);
    $this->actingAs($admin);

    Livewire::test(AhjiyatPassesPage::class)->call('show', $unready->public_id)->call('schedule');
    expect($unready->fresh()->status)->toBe('draft');

    Livewire::test(AhjiyatPassesPage::class)->call('show', $ready['pass']->public_id)->call('schedule');
    expect($ready['pass']->fresh()->status)->toBe('active');
});

test('AX15-104: allow-listed theme tokens only — arbitrary CSS or HTML is rejected', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false])['pass'];

    foreach ([['accent' => 'red;background:url(x)', 'background' => 'aurora'], ['accent' => 'amber', 'background' => '<script>'], ['accent' => 'neon', 'background' => 'aurora']] as $theme) {
        expect(e40_reason(fn () => e40_builder()->update($admin, $draft, ['theme' => $theme])))->toBe('theme');
    }

    $ok = e40_builder()->update($admin, $draft, ['theme' => ['accent' => 'violet', 'background' => 'ocean', 'tagline' => '<b>شعار</b>']]);
    expect($ok->themeAccent())->toBe('violet')->and($ok->tagline())->not->toContain('<b>');
});

test('AX15-105: the banner upload accepts a real image only and stores it under a random server-side name', function () {
    Storage::fake('public');
    $admin = e40_admin();
    $draft = e40_build(['publish' => false])['pass'];

    expect(e40_reason(fn () => e40_builder()->setBanner($admin, $draft, UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml'))))->not->toBeNull()
        ->and(e40_reason(fn () => e40_builder()->setBanner($admin, $draft, UploadedFile::fake()->create('shell.php', 5, 'application/x-php'))))->not->toBeNull()
        ->and(e40_reason(fn () => e40_builder()->setBanner($admin, $draft, UploadedFile::fake()->image('tiny.png', 50, 50))))->not->toBeNull();

    $saved = e40_builder()->setBanner($admin, $draft, UploadedFile::fake()->image('my banner ../x.png', 900, 300));

    expect($saved->banner_path)->toStartWith('ahjiyat-pass/')->and($saved->banner_path)->not->toContain('my banner')->and($saved->banner_path)->toEndWith('.png')
        ->and(Storage::disk('public')->exists($saved->banner_path))->toBeTrue();
});

test('AX15-106: a partial draft update (name or theme only) leaves the price untouched and does not re-validate it; touching price re-validates', function () {
    $admin = e40_admin();
    $draft = e40_build(['publish' => false, 'price' => 750, 'currency' => 'USD'])['pass'];

    $updated = e40_builder()->update($admin, $draft, ['name' => 'اسم جديد تمامًا']);
    expect($updated->name)->toBe('اسم جديد تمامًا')->and($updated->premium_price_minor)->toBe(750)->and($updated->currency)->toBe('USD');

    expect(e40_reason(fn () => e40_builder()->update($admin, $draft, ['premium_price_minor' => 0])))->toBe('price')
        ->and(e40_reason(fn () => e40_builder()->update($admin, $draft, ['currency' => 'XXX'])))->toBe('currency')
        ->and($draft->fresh()->premium_price_minor)->toBe(750);

    $disabled = e40_builder()->update($admin, $draft, ['premium_enabled' => false]);
    expect($disabled->premium_enabled)->toBeFalse();
});
