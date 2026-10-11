<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformSettingsService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * E24 - إعدادات المنصة: قراءة الكاش مرة لكل طلب GET بدل عشرات المرات، إبطال فوري عند أي كاتب، وروابط الهوية البصرية بلا فحص ملف لكل صفحة.
 */
test('E24: one page render reads the settings cache entry once, not once per setting', function () {
    $this->get('/')->assertOk(); // يملأ الكاش

    $hits = 0;
    Event::listen(CacheHit::class, function (CacheHit $e) use (&$hits) {
        if ($e->key === 'platform_settings.all') {
            $hits++;
        }
    });

    $this->get('/')->assertOk();

    expect($hits)->toBeLessThanOrEqual(1);
});

test('E24: saving through the service is visible on the very next request', function () {
    $admin = User::factory()->create();
    $this->get('/')->assertOk(); // يملأ الكاش بالقيم الافتراضية

    app(PlatformSettingsService::class)->setMany('general', ['site_name' => 'اسم جديد E24'], $admin);

    $this->get('/')->assertOk()->assertSee('اسم جديد E24');
});

test('E24: writing the model directly (seeder/tinker/other code) also invalidates the cache', function () {
    $this->get('/')->assertOk();

    PlatformSetting::create(['group' => 'general', 'key' => 'site_name', 'value' => 'كاتب مباشر', 'type' => 'string']);
    $this->get('/')->assertOk()->assertSee('كاتب مباشر');

    PlatformSetting::where('key', 'site_name')->first()->delete();
    $this->get('/')->assertOk()->assertDontSee('كاتب مباشر');
});

test('E24: a missing platform_settings table still degrades to defaults (unchanged behavior)', function () {
    \Illuminate\Support\Facades\Schema::drop('platform_settings');

    expect(app(PlatformSettingsService::class)->get('general', 'site_name'))->toBe(config('platform.general.site_name.default'));
});

test('E24: branding URLs resolve only for files that really exist, and a path change is picked up at once', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/logo-a.png', 'x');
    $svc = app(PlatformSettingsService::class);

    $svc->setMany('branding', ['logo_main' => 'branding/logo-a.png', 'favicon' => 'branding/missing.ico']);
    $urls = $svc->brandingUrls();

    expect($urls['logo_main'])->toBe(Storage::url('branding/logo-a.png'))
        ->and($urls['favicon'])->toBeNull()
        ->and($urls['social_share_image'])->toBeNull();

    Storage::disk('public')->put('branding/logo-b.png', 'y');
    $svc->setMany('branding', ['logo_main' => 'branding/logo-b.png']);

    expect($svc->brandingUrls()['logo_main'])->toBe(Storage::url('branding/logo-b.png'));
});

test('E24: the layout renders the cached favicon/logo URLs exactly as the old inline checks did', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/fav.png', 'x');
    app(PlatformSettingsService::class)->setMany('branding', ['favicon' => 'branding/fav.png']);

    $this->get('/')->assertOk()->assertSee('<link rel="icon" href="'.Storage::url('branding/fav.png').'">', false);
});

test('E24: with a zero TTL the file check is live (no caching)', function () {
    config(['performance.cache.branding_urls_ttl' => 0]);
    Storage::fake('public');
    $svc = app(PlatformSettingsService::class);
    $svc->setMany('branding', ['logo_main' => 'branding/late.png']);

    expect($svc->brandingUrls()['logo_main'])->toBeNull();

    Storage::disk('public')->put('branding/late.png', 'x');

    expect($svc->brandingUrls()['logo_main'])->toBe(Storage::url('branding/late.png'));
});
