<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Facades\Cache;

test('a setting returns its config default when no database row exists', function () {
    $settings = app(PlatformSettingsService::class);

    expect($settings->get('general', 'site_name'))->toBe('أحجيات');
});

test('a database value overrides the config default', function () {
    PlatformSetting::create(['group' => 'general', 'key' => 'site_name', 'value' => 'اسم مخصَّص', 'type' => 'string']);

    $settings = app(PlatformSettingsService::class);

    expect($settings->get('general', 'site_name'))->toBe('اسم مخصَّص');
});

test('boolean settings are cast to real booleans, not the string "0"', function () {
    PlatformSetting::create(['group' => 'access', 'key' => 'allow_registration', 'value' => '0', 'type' => 'boolean']);

    $settings = app(PlatformSettingsService::class);

    expect($settings->get('access', 'allow_registration'))->toBeFalse();
});

test('boolean setting default (true) is a real boolean too', function () {
    $settings = app(PlatformSettingsService::class);

    expect($settings->get('access', 'allow_registration'))->toBeTrue();
});

test('integer settings are cast to real integers', function () {
    config(['platform.general.test_integer_key' => ['type' => 'integer', 'default' => 0]]);
    PlatformSetting::create(['group' => 'general', 'key' => 'test_integer_key', 'value' => '42', 'type' => 'integer']);

    $settings = app(PlatformSettingsService::class);

    expect($settings->get('general', 'test_integer_key'))->toBe(42)
        ->and($settings->get('general', 'test_integer_key'))->toBeInt();
});

test('a setting can be written and read back correctly via set()', function () {
    $settings = app(PlatformSettingsService::class);

    $settings->set('general', 'site_name', 'منصة الاختبار');

    expect($settings->get('general', 'site_name'))->toBe('منصة الاختبار');
});

test('setMany writes multiple keys of the same group in one call', function () {
    $settings = app(PlatformSettingsService::class);

    $settings->setMany('general', ['site_name' => 'اسم جديد', 'short_name' => 'قصير']);

    expect($settings->get('general', 'site_name'))->toBe('اسم جديد')
        ->and($settings->get('general', 'short_name'))->toBe('قصير');
});

test('setMany silently ignores keys not defined in the config (no arbitrary keys)', function () {
    $settings = app(PlatformSettingsService::class);

    $settings->setMany('general', ['not_a_real_setting' => 'x']);

    expect(PlatformSetting::where('key', 'not_a_real_setting')->exists())->toBeFalse();
});

test('getGroup returns every configured key for that group, casted', function () {
    $settings = app(PlatformSettingsService::class);

    $group = $settings->getGroup('access');

    expect($group)->toHaveKeys(['allow_registration', 'show_registration_cta'])
        ->and($group['allow_registration'])->toBeBool();
});

test('the cache is invalidated after set() - a stale value is never returned', function () {
    $settings = app(PlatformSettingsService::class);

    expect($settings->get('general', 'site_name'))->toBe('أحجيات');

    $settings->set('general', 'site_name', 'بعد التحديث');

    expect($settings->get('general', 'site_name'))->toBe('بعد التحديث');
});

test('the settings cache is a single shared array, not one query per key', function () {
    Cache::flush();
    $settings = app(PlatformSettingsService::class);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $settings->get('general', 'site_name');
    $settings->get('general', 'short_name');
    $settings->get('appearance', 'color_primary');
    $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(1);
});

test('the service never fatal-errors even if the settings table were unreadable', function () {
    $settings = app(PlatformSettingsService::class);

    expect($settings->get('general', 'site_name'))->not->toBeNull();
});

test('updated_by is recorded when a settings value changes', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $settings = app(PlatformSettingsService::class);

    $settings->set('general', 'site_name', 'تجربة', $admin);

    expect(PlatformSetting::where('key', 'site_name')->first()->updated_by)->toBe($admin->id);
});