<?php

use App\Models\SupporterTier;
use App\Services\Sponsorship\SupporterTierService;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_tier(\App\Models\SupporterProfile $s): ?string
{
    return app(SupporterTierService::class)->forSupporter($s)?->slug;
}

test('AM3-23: the tier derives from the confirmed net total (default Arabic tiers)', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    expect(e30_tier($supporter))->toBeNull();

    e30_confirmed($supporter, $program, '5');
    expect(e30_tier($supporter))->toBe('supporter');
    e30_confirmed($supporter, $program, '45');
    expect(e30_tier($supporter))->toBe('silver');
    e30_confirmed($supporter, $program, '200');
    expect(e30_tier($supporter))->toBe('gold');
    expect(SupporterTier::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['داعم', 'فضي', 'ذهبي', 'بلاتيني', 'ماسي']);
});

test('AM3-24: a pending contribution does not affect the tier', function () {
    $supporter = e30_supporter();
    e30_pending($supporter, e30_program(), '1000');

    expect(e30_tier($supporter))->toBeNull();
});

test('AM3-25: refunded support downgrades the tier automatically', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    $big = e30_confirmed($supporter, $program, '300');
    e30_confirmed($supporter, $program, '10');
    expect(e30_tier($supporter))->toBe('gold');

    e30_refund($big);

    expect(e30_tier($supporter))->toBe('supporter');
});

test('AM3-26: the highest matching tier is chosen', function () {
    $supporter = e30_supporter();
    e30_confirmed($supporter, e30_program(), '5000');

    expect(e30_tier($supporter))->toBe('diamond');
});

test('AM3-27: an inactive tier is ignored (tiers are DB-managed and the cache is invalidated)', function () {
    $program = e30_program();
    $supporter = e30_supporter();
    e30_confirmed($supporter, $program, '300');
    expect(e30_tier($supporter))->toBe('gold');

    SupporterTier::query()->where('slug', 'gold')->first()->update(['is_active' => false]);

    expect(e30_tier($supporter))->toBe('silver');

    SupporterTier::query()->where('slug', 'gold')->first()->update(['minimum_accounting_amount_minor' => 99999999, 'is_active' => true]);
    expect(e30_tier($supporter))->toBe('silver');
});

test('AM3-28: the public player profile shows supporter recognition only for public, named support', function () {
    $user = e16User(['name' => 'لاعب داعم']);
    $program = e30_program();
    $supporter = e30_supporter(['user_id' => $user->id]);
    e30_confirmed($supporter, $program, '60');

    $this->actingAs(e16User())->get(route('players.show', $user))->assertOk()->assertSee('data-supporter-recognition', false)->assertSee('داعم · فضي');
});

test('AM3-29: private, suspended or fully anonymous supporters show no recognition (safe behaviour)', function () {
    $program = e30_program();
    $viewer = e16User();

    $private = e16User();
    e30_confirmed(e30_supporter(['user_id' => $private->id, 'is_public' => false]), $program, '60');
    $anonymous = e16User();
    e30_confirmed(e30_supporter(['user_id' => $anonymous->id]), $program, '60', ['is_anonymous' => true]);

    foreach ([$private, $anonymous] as $user) {
        $this->actingAs($viewer)->get(route('players.show', $user))->assertOk()->assertDontSee('data-supporter-recognition', false);
    }
});

test('AM3-30: the client cannot submit a tier; tiers are never stored on the supporter', function () {
    $supporter = e30_supporter(['tier' => 'diamond', 'tier_id' => 5, 'tier_slug' => 'diamond']);

    expect($supporter->getAttributes())->not->toHaveKeys(['tier', 'tier_id', 'tier_slug']);
    expect(e30_tier($supporter))->toBeNull();
    $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('supporter_profiles', 'tier'));
});
