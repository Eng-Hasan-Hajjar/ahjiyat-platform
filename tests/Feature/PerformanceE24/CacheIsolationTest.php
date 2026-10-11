<?php

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * E24 - عزل الكاش بين المستخدمين. الكاش الجديد عام فقط للبيانات العامة غير المقرِّرة (كتالوج المستويات، روابط الهوية البصرية، Top-N العامة)؛
 * كل ما يخص مستخدمًا (تقدّمه، محفظته، اسمه في شريط التنقل) يُقرأ له وحده. هنا نتأكد بالتبديل A→B→A والكاش دافئ (لا flush بين الطلبات).
 */
beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى ثانٍ']);
    LevelDefinition::factory()->create(['level_number' => 3, 'xp_required_total' => 1000, 'name' => 'مستوى ثالث']);
});

test('E24: with the level catalog cached, two users alternating get their own level and progress', function () {
    $a = User::factory()->create(['name' => 'مستخدم ألف']);
    $b = User::factory()->create(['name' => 'مستخدم باء']);
    PlayerProgression::factory()->create(['user_id' => $a->id, 'total_xp' => 50, 'current_level' => 1]);
    PlayerProgression::factory()->create(['user_id' => $b->id, 'total_xp' => 1500, 'current_level' => 3]);

    Cache::flush();

    foreach ([$a, $b, $a, $b] as $i => $user) {
        $expectedLevel = $user->is($a) ? 1 : 3;
        $data = $this->actingAs($user)->get(route('progress.show'))->assertOk();

        expect($data->viewData('currentLevel')->level_number)->toBe($expectedLevel, "request #{$i}")
            ->and($data->viewData('progression')->user_id)->toBe($user->id);
    }
});

test('E24: the wallet page shows only the requesting user\'s balances, even when pages are rendered back to back with a warm cache', function () {
    $currency = app(CurrencyRegistry::class)->defaultEarnedCurrency();
    $a = User::factory()->create(['name' => 'مستخدم ألف']);
    $b = User::factory()->create(['name' => 'مستخدم باء']);
    Wallet::query()->updateOrCreate(['user_id' => $a->id, 'currency_id' => $currency->id], ['pending_balance' => 0, 'available_balance' => 48170, 'lifetime_earned' => 48170, 'lifetime_redeemed' => 0]);
    Wallet::query()->updateOrCreate(['user_id' => $b->id, 'currency_id' => $currency->id], ['pending_balance' => 0, 'available_balance' => 29630, 'lifetime_earned' => 29630, 'lifetime_redeemed' => 0]);

    foreach ([$a, $b, $a, $b] as $user) {
        $mine = $user->is($a) ? 48170 : 29630;
        $other = $user->is($a) ? 29630 : 48170;

        $response = $this->actingAs($user)->get(route('wallet.index'))->assertOk();
        $html = $response->getContent();

        expect($html)->toContain(number_format($mine))->and($html)->not->toContain(number_format($other));
    }
});

test('E24: the navigation bar shows the signed-in user\'s name and never another user\'s after a warm request', function () {
    $a = User::factory()->create(['name' => 'ألف المتصفِّح']);
    $b = User::factory()->create(['name' => 'باء المتصفِّح']);

    foreach ([$a, $b, $a] as $user) {
        $other = $user->is($a) ? $b : $a;

        $this->actingAs($user)->get(route('home'))->assertOk()->assertSee($user->name)->assertDontSee($other->name);
    }

    // الضيف بعد مستخدمَين: لا اسم ولا بيانات شخصية.
    auth()->logout();
    $this->app['auth']->forgetGuards();
    $this->get(route('home'))->assertOk()->assertDontSee($a->name)->assertDontSee($b->name);
});

test('E24: nothing personal ends up in the shared cache after a user browses the pages that gained caching', function () {
    $user = User::factory()->create(['name' => 'اسم سرّي فريد', 'email' => 'unique-secret-person@example.test']);
    PlayerProgression::factory()->create(['user_id' => $user->id, 'total_xp' => 10]);
    Cache::flush();

    foreach (['home', 'progress.show', 'leaderboard.index', 'wallet.index'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }

    $storage = (fn () => $this->storage)->call(Cache::store()->getStore());
    $keys = array_keys($storage);

    // ليس اختبارًا فارغًا: الكاش الجديد كُتب فعلًا.
    expect($keys)->toContain(\App\Services\Progression\LevelService::CATALOG_CACHE_KEY)
        ->and(collect($keys)->contains(fn ($k) => str_starts_with($k, \App\Services\LeaderboardService::CACHE_KEY)))->toBeTrue();

    $needles = [$user->email, $user->name, $user->public_id, (string) $user->password];

    foreach ($storage as $key => $entry) {
        $blob = $key.'|'.json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR).'|'.serialize($entry['value'] ?? null);

        foreach ($needles as $needle) {
            expect(str_contains($blob, $needle))->toBeFalse("cache entry {$key} leaks user identity");
        }
    }
});
