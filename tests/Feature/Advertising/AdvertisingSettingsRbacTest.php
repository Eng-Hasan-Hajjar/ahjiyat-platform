<?php

use App\Filament\Pages\PlatformSettingsPage;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\OperationalAuditLog;
use App\Models\PlatformSetting;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\PlatformSettingsService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->settings = app(PlatformSettingsService::class);
    $this->settings->set('advertising', 'ads_enabled', true);
    $this->settings->set('advertising', 'direct_sponsors_enabled', true);
    $this->settings->set('advertising', 'external_ads_enabled', false);
    $this->settings->set('advertising', 'max_ads_desktop', 3);
    $this->settings->set('advertising', 'max_ads_mobile', 1);
    $this->settings->set('general', 'site_name', 'الاسم الأصلي');
});

function e141sUser(array $permissions = [], ?string $role = null): User
{
    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function e141sAds(): array
{
    $s = app(PlatformSettingsService::class);
    $s->forgetCache();

    return $s->getGroup('advertising');
}

function e141sSiteName(): string
{
    app(PlatformSettingsService::class)->forgetCache();

    return app(PlatformSettingsService::class)->get('general', 'site_name');
}

test('E14.1-M/56: settings.update WITHOUT ads.settings.manage can edit general settings but a crafted advertising payload changes nothing (N/30)', function () {
    $this->actingAs(e141sUser(['settings.view', 'settings.update']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.general.site_name', 'اسم جديد من مستخدم عام')
        ->set('data.advertising.ads_enabled', false)           // حمولة معدَّلة يدويًا
        ->set('data.advertising.direct_sponsors_enabled', false)
        ->set('data.advertising.external_ads_enabled', true)
        ->set('data.advertising.max_ads_desktop', 4)
        ->call('save');

    expect(e141sSiteName())->toBe('اسم جديد من مستخدم عام')
        ->and(e141sAds())->toBe([
            'ads_enabled' => true, 'direct_sponsors_enabled' => true, 'external_ads_enabled' => false,
            'max_ads_desktop' => 3, 'max_ads_mobile' => 1,
        ]);
});

test('a crafted INVALID advertising value fails closed: validation error, nothing is saved anywhere', function () {
    $this->actingAs(e141sUser(['settings.view', 'settings.update']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.general.site_name', 'يجب ألا يُحفظ')
        ->set('data.advertising.max_ads_desktop', 99)
        ->call('save')
        ->assertHasErrors(['data.advertising.max_ads_desktop']);

    expect(e141sSiteName())->toBe('الاسم الأصلي')->and(e141sAds()['max_ads_desktop'])->toBe(3);
});

test('E14.1-56: ads.settings.manage WITHOUT settings.update can edit advertising settings only', function () {
    $this->actingAs(e141sUser(['settings.view', 'ads.settings.manage']));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.advertising.ads_enabled', false)
        ->set('data.general.site_name', 'محاولة تعديل عام بلا صلاحية')
        ->call('save');

    expect(e141sAds()['ads_enabled'])->toBeFalse()
        ->and(e141sSiteName())->toBe('الاسم الأصلي');
});

test('a user with neither permission gets 403 on save', function () {
    $this->actingAs(e141sUser(['settings.view']));

    Livewire::test(PlatformSettingsPage::class)->call('save')->assertForbidden();

    expect(e141sAds()['ads_enabled'])->toBeTrue();
});

test('E14.1-56: Super Admin bypass still works for both general and advertising settings', function () {
    $this->actingAs(e141sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.general.site_name', 'من المدير الأعلى')
        ->set('data.advertising.max_ads_desktop', 4)
        ->call('save');

    expect(e141sSiteName())->toBe('من المدير الأعلى')->and(e141sAds()['max_ads_desktop'])->toBe(4);
});

test('E14.1-O/57/32: the settings form hydrates the persisted advertising group', function () {
    $this->actingAs(e141sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)->assertFormSet([
        'advertising' => [
            'ads_enabled' => true, 'direct_sponsors_enabled' => true, 'external_ads_enabled' => false,
            'max_ads_desktop' => 3, 'max_ads_mobile' => 1,
        ],
    ]);
});

test('E14.1-P/33/58: saving an UNRELATED setting leaves the advertising settings untouched', function () {
    $this->actingAs(e141sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)
        ->set('data.general.site_name', 'تغيير اسم الموقع فقط')
        ->call('save');

    expect(e141sSiteName())->toBe('تغيير اسم الموقع فقط')
        ->and(e141sAds())->toBe([
            'ads_enabled' => true, 'direct_sponsors_enabled' => true, 'external_ads_enabled' => false,
            'max_ads_desktop' => 3, 'max_ads_mobile' => 1,
        ]);
});

test('E14.1-34: an authorized advertising change is audited with old/new values, and an unchanged save writes no entry', function () {
    $this->actingAs(e141sUser([], 'super-admin'));

    Livewire::test(PlatformSettingsPage::class)->call('save'); // لا تغيير
    expect(OperationalAuditLog::where('action', 'ads.settings.changed')->count())->toBe(0);

    Livewire::test(PlatformSettingsPage::class)->set('data.advertising.ads_enabled', false)->call('save');

    $entry = OperationalAuditLog::where('action', 'ads.settings.changed')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->metadata['changes']['ads_enabled'])->toBe(['old' => true, 'new' => false]);
});

test('E14.1-35: kill switch through the settings page makes the ad disappear immediately with no new impressions', function () {
    $placement = AdPlacement::factory()->known(AdPlacementRegistry::HOME_INLINE_PRIMARY)->create();
    $campaign = SponsorCampaign::factory()->approved()->create();
    $campaign->placements()->attach($placement->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly(['title' => 'إعلان الاختبار']);

    $this->actingAs(e141sUser([], 'super-admin'));
    $this->get(route('home'))->assertOk()->assertSee('إعلان الاختبار');
    $before = AdImpression::count();

    Livewire::test(PlatformSettingsPage::class)->set('data.advertising.ads_enabled', false)->call('save');

    $this->get(route('home'))->assertOk()->assertDontSee('إعلان الاختبار');
    expect(AdImpression::count())->toBe($before)->and(AdClick::count())->toBe(0);
});
