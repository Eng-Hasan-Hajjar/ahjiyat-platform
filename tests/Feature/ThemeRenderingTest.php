<?php

use App\Services\PlatformSettingsService;
use Illuminate\Support\Facades\Storage;

test('the homepage renders with default branding when no logo has been uploaded (no broken image)', function () {
    $this->get(route('home'))->assertOk()->assertDontSee('<img src="" ', false);
});

test('a logo uploaded via settings renders in the navbar', function () {
    Storage::fake('public');
    Storage::disk('public')->put('settings/branding/logo-test.png', 'fake');

    app(PlatformSettingsService::class)->set('branding', 'logo_main', 'settings/branding/logo-test.png');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(Storage::url('settings/branding/logo-test.png'), false);
});

test('the no-flash theme bootstrap script is present in the page head before any stylesheet', function () {
    $html = $this->get(route('home'))->getContent();

    $scriptPos = strpos($html, "document.documentElement.setAttribute('data-theme'");
    $vitePos = strpos($html, '/build/assets');

    expect($scriptPos)->not->toBeFalse()
        ->and($scriptPos)->toBeLessThan($vitePos !== false ? $vitePos : PHP_INT_MAX);
});

test('the dynamic color tokens are injected as inline CSS variables reflecting the saved primary color', function () {
    app(PlatformSettingsService::class)->set('appearance', 'color_primary', '#ff00aa');

    $this->get(route('home'))->assertOk()->assertSee('--color-primary: #ff00aa', false);
});

test('the theme switcher button is shown when allow_theme_switch is enabled', function () {
    app(PlatformSettingsService::class)->set('appearance', 'allow_theme_switch', true);

    $this->get(route('home'))->assertOk()->assertSee('themeSwitcher()', false);
});

test('the theme switcher button is hidden when allow_theme_switch is disabled', function () {
    app(PlatformSettingsService::class)->set('appearance', 'allow_theme_switch', false);

    $this->get(route('home'))->assertOk()->assertDontSee('themeSwitcher()', false);
});

test('the favicon link is only rendered when a favicon has actually been uploaded', function () {
    $this->get(route('home'))->assertOk()->assertDontSee('<link rel="icon" href="">', false);
});

test('a favicon uploaded via settings renders as a real link tag', function () {
    Storage::fake('public');
    Storage::disk('public')->put('settings/branding/favicon-test.png', 'fake');

    app(PlatformSettingsService::class)->set('branding', 'favicon', 'settings/branding/favicon-test.png');

    $this->get(route('home'))->assertOk()->assertSee('rel="icon"', false);
});

test('the site name setting is reflected in the page title', function () {
    app(PlatformSettingsService::class)->set('general', 'site_name', 'اسم فريد للاختبار');

    $this->get(route('home'))->assertOk()->assertSee('<title>اسم فريد للاختبار', false);
});