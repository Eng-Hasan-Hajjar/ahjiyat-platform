<?php

use App\Models\Campaign;
use App\Models\Season;
use App\Models\User;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\Schema;

test('a season belongs to exactly one campaign, and the relation resolves both ways', function () {
    $campaign = Campaign::factory()->create();
    $season = Season::factory()->create(['campaign_id' => $campaign->id]);

    expect($season->campaign->is($campaign))->toBeTrue()
        ->and($campaign->fresh()->season->is($season))->toBeTrue();
});

test('a campaign cannot have two seasons (unique constraint)', function () {
    $campaign = Campaign::factory()->create();
    Season::factory()->create(['campaign_id' => $campaign->id]);

    expect(fn () => Season::factory()->create(['campaign_id' => $campaign->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('an unpublished season is hidden from the public season show route', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('seasons.show', $season))->assertNotFound();
});

test('an unpublished season is hidden from the guest as well', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);

    $this->get(route('seasons.show', $season))->assertNotFound();
});

test('an admin can preview an unpublished season', function () {
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('seasons.show', $season))
        ->assertOk()
        ->assertSee($campaign->title);
});

test('an unpublished season does not appear on the public index even for a logged-in non-admin user', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'حملة غير منشورة']);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('seasons.index'))->assertOk()->assertDontSee('حملة غير منشورة');
});

test('a published, available season appears on the public index', function () {
    $campaign = Campaign::factory()->create(['is_active' => true, 'title' => 'حملة منشورة']);
    Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    $this->get(route('seasons.index'))->assertOk()->assertSee('حملة منشورة');
});



// E42 (تعديل مقصود): كان هذا الاختبار يمنع وجود starts_at/ends_at على seasons كليًا. E42 يضيف نافذة موسم اختيارية صريحة،
// لكنه يُبقي المبدأ: لا حالة مخزَّنة (لا is_active) والـNULL يتبع الحملة الأساسية، فموسم بلا نافذة يسلك كما كان تمامًا.
test('season availability follows the campaign unless a window is set explicitly - no stored active flag on Season', function () {
    expect(Schema::hasColumn('seasons', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumn('seasons', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('seasons', 'starts_at'))->toBeTrue()
        ->and(Schema::hasColumn('seasons', 'ends_at'))->toBeTrue();

    $campaign = Campaign::factory()->create(['is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);

    // بلا نافذة صريحة: التواريخ فارغة والحالة كلها مشتقّة من الحملة (السلوك القديم حرفيًا).
    expect($season->starts_at)->toBeNull()->and($season->ends_at)->toBeNull();

    $campaign->update(['starts_at' => now()->addDay(), 'ends_at' => null]);
    $this->get(route('seasons.show', $season->fresh()))->assertOk()->assertSee('قريباً');
});

test('the season show page derives its availability label from the campaign schedule, not a stored field', function () {
    $progress = app(CampaignProgressService::class);

    $futureCampaign = Campaign::factory()->create(['is_active' => true, 'starts_at' => now()->addDay()]);
    $futureSeason = Season::factory()->create(['campaign_id' => $futureCampaign->id, 'is_published' => true]);

    $this->get(route('seasons.show', $futureSeason))->assertOk()->assertSee('قريباً');

    expect($progress->isCampaignAvailable($futureCampaign))->toBeFalse();
});