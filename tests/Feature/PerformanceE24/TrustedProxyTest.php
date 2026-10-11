<?php

use Illuminate\Support\Facades\Route;

/**
 * E24 - خلف موازن حِمل: بدون TRUSTED_PROXIES لا يُصدَّق X-Forwarded-For (السلوك الحالي)، ومعه يُقرأ عنوان اللاعب الحقيقي.
 */
beforeEach(function () {
    Route::middleware('web')->get('/_e24/ip', fn (\Illuminate\Http\Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure()]));
});

function e24_trusted_config(?string $env): mixed
{
    $previous = getenv('TRUSTED_PROXIES');
    $env === null ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES='.$env);
    unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

    try {
        return (require base_path('config/trustedproxy.php'))['proxies'];
    } finally {
        $previous === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES='.$previous);
    }
}

test('E24: the config defaults to null (no behavior change), accepts a wildcard, or a trimmed list', function () {
    expect(e24_trusted_config(null))->toBeNull()
        ->and(e24_trusted_config(''))->toBeNull()
        ->and(e24_trusted_config('*'))->toBe('*')
        ->and(e24_trusted_config('10.0.0.0/8, 172.16.0.0/12 ,'))->toBe(['10.0.0.0/8', '172.16.0.0/12']);
});

test('E24: untrusted by default - a spoofed X-Forwarded-For is ignored', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_e24/ip')->assertJsonPath('ip', '10.0.0.5');
});

test('E24: with the proxy trusted, the real client address and scheme are used', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/_e24/ip')->assertJsonPath('ip', '203.0.113.9')->assertJsonPath('secure', true);
});

test('E24: a proxy that is not in the trusted list is still ignored', function () {
    config(['trustedproxy.proxies' => ['192.168.50.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_e24/ip')->assertJsonPath('ip', '10.0.0.5');
});
