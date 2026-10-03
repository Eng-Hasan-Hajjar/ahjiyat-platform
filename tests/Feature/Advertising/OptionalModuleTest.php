<?php

use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\CurrencyTransaction;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\PlatformSettingsService;

/**
 * E14 الأهم (بند 634/691): الإعلانات غائبة كليًا (كتثبيت إنتاجي جديد
 * فعليًا) - المنصة تعمل بلا أي نقص، بلا أي حساب Google، بلا أي CMP.
 */
test('with ads fully disabled (the safe default), all core public pages boot and render normally with zero ad markup', function () {
    // لا إعداد صريح هنا إطلاقًا - نعتمد على القيم الافتراضية الآمنة بـconfig/platform.php
    $user = User::factory()->create(['email_verified_at' => now()]);

    $home = $this->get(route('home'));
    $home->assertOk()->assertDontSee('adsbygoogle', false)->assertDontSee('data-ad-placement', false);

    $this->get(route('leaderboard.index'))->assertOk()->assertDontSee('data-ad-placement', false);
    $this->get(route('puzzles.index'))->assertOk();
    $this->get(route('store.index'))->assertOk();

    $this->actingAs($user);
    $this->get(route('quests.show'))->assertOk()->assertDontSee('data-ad-placement', false);
    $this->get(route('progress.show'))->assertOk();

    expect(AdImpression::count())->toBe(0)->and(AdClick::count())->toBe(0);
});

test('ads disabled: an approved sponsor campaign still produces zero impressions on render', function () {
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create();
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->create();
    // ads_enabled يبقى false افتراضيًا - لم نُفعِّله هنا عمدًا.

    $this->get(route('home'))->assertOk();

    expect(AdImpression::count())->toBe(0);
});

// ===== Gameplay zero-ad proof (item 654) =====
test('gameplay pages never render an ad slot even when advertising is globally enabled', function () {
    app(PlatformSettingsService::class)->set('advertising', 'ads_enabled', true);
    app(PlatformSettingsService::class)->set('advertising', 'direct_sponsors_enabled', true);

    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create();
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->create();

    $puzzle = \App\Models\Puzzle::factory()->create();
    $response = $this->get(route('puzzles.show', $puzzle));

    $response->assertOk()->assertDontSee('data-ad-placement', false);
});

// ===== No economy coupling (item 652/178/527) =====
test('rendering and clicking a direct sponsor ad creates zero economy/progression mutations', function () {
    app(PlatformSettingsService::class)->set('advertising', 'ads_enabled', true);
    app(PlatformSettingsService::class)->set('advertising', 'direct_sponsors_enabled', true);

    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create();
    $campaign->placements()->attach($placement->id);
    $creative = SponsorCreative::factory()->for($campaign, 'campaign')->create();

    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user);

    $xpBefore = XpTransaction::count();
    $currencyBefore = CurrencyTransaction::count();
    $questBefore = UserQuestProgress::count();

    $this->get(route('home'))->assertOk();
    $this->get(route('ads.click', $creative));

    expect(XpTransaction::count())->toBe($xpBefore)
        ->and(CurrencyTransaction::count())->toBe($currencyBefore)
        ->and(UserQuestProgress::count())->toBe($questBefore)
        ->and(\App\Models\StorePurchase::count())->toBe(0);
});

// ===== No rewarded ads proof (item 653/179) =====
test('no advertising service references any reward-granting service', function () {
    $files = glob(app_path('Services/Advertising/*.php'));
    $forbidden = ['XpService', 'CurrencyWalletService', 'InventoryService', 'EntitlementService', 'StorePurchaseService', 'QuestService', 'StreakService'];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        foreach ($forbidden as $service) {
            expect($content)->not->toContain($service);
        }
    }
});
