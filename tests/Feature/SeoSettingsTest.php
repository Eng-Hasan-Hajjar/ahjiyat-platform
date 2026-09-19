<?php

use App\Services\PlatformSettingsService;

test('the default meta description setting appears in the page head', function () {
    app(PlatformSettingsService::class)->set('seo', 'meta_description', 'وصف اختباري فريد للمحرّكات');

    $this->get(route('home'))->assertOk()->assertSee('وصف اختباري فريد للمحرّكات', false);
});

test('og:title and og:description tags render from SEO settings', function () {
    app(PlatformSettingsService::class)->set('seo', 'meta_title', 'عنوان OG فريد');

    $html = $this->get(route('home'))->getContent();

    expect($html)->toContain('og:title')
        ->and($html)->toContain('عنوان OG فريد');
});

test('a noindex meta tag is added when indexing is disabled', function () {
    app(PlatformSettingsService::class)->set('seo', 'indexing_enabled', false);

    $this->get(route('home'))->assertOk()->assertSee('noindex', false);
});

test('no noindex tag appears when indexing is enabled (the default)', function () {
    $this->get(route('home'))->assertOk()->assertDontSee('noindex', false);
});

test('a puzzle page keeps its own specific title instead of the generic SEO default', function () {
    $puzzle = \App\Models\Puzzle::factory()->create(['title' => 'أحجية فريدة لعنوان الصفحة', 'is_active' => true]);
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)
        ->get(route('puzzles.show', $puzzle))
        ->assertOk()
        ->assertSee('أحجية فريدة لعنوان الصفحة');
});

test('the announcement bar renders when enabled with text', function () {
    app(PlatformSettingsService::class)->setMany('announcement', [
        'enabled' => true,
        'text' => 'نص تنبيه فريد للاختبار',
        'type' => 'info',
    ]);

    $this->get(route('home'))->assertOk()->assertSee('نص تنبيه فريد للاختبار');
});

test('the announcement bar does not render when disabled, even if text is set', function () {
    app(PlatformSettingsService::class)->setMany('announcement', [
        'enabled' => false,
        'text' => 'نص لن يظهر',
    ]);

    $this->get(route('home'))->assertOk()->assertDontSee('نص لن يظهر');
});

test('the announcement bar never renders raw HTML from its text - plain text only', function () {
    app(PlatformSettingsService::class)->setMany('announcement', [
        'enabled' => true,
        'text' => '<script>alert(1)</script>',
    ]);

    $html = $this->get(route('home'))->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
});

test('public HTML never exposes settings from other groups as raw data attributes or JSON dumps', function () {
    $html = $this->get(route('home'))->getContent();

    expect($html)->not->toContain('allow_registration')
        ->and($html)->not->toContain('meta_keywords');
});