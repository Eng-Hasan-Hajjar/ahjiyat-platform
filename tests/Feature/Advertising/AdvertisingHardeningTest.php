<?php

use App\Exceptions\AdInvariantViolation;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Models\OperationalAuditLog;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Services\Advertising\AdPlacementRegistry;
use App\Services\Advertising\AdServingService;
use App\Services\Advertising\SponsorCampaignService;
use App\Services\PlatformSettingsService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * E14.1 - تصحيح نهائي: ثوابت المراجعة، حماية سجل التحليلات، ثوابت الاعتماد،
 * صلاحية إعدادات الإعلانات، نظافة الـSeeders وحاجز قاعدة الاختبارات.
 */
beforeEach(function () {
    $settings = app(PlatformSettingsService::class);
    $settings->set('advertising', 'ads_enabled', true);
    $settings->set('advertising', 'direct_sponsors_enabled', true);
    $settings->set('advertising', 'max_ads_desktop', 5);

    $this->campaigns = app(SponsorCampaignService::class);
    $this->serving = app(AdServingService::class);
    $this->admin = User::factory()->create();
});

function e141Home(): string
{
    return AdPlacementRegistry::HOME_INLINE_PRIMARY;
}

function e141Placement(): AdPlacement
{
    return AdPlacement::query()->where('internal_key', e141Home())->first()
        ?? AdPlacement::factory()->known(e141Home())->create();
}

/** حملة معتمَدة بمادة "مراجَعة" (تُبنى بلا أحداث لأن الحارس يُختبر بالاختبارات نفسها). @return array{0: SponsorCampaign, 1: SponsorCreative} */
function e141Approved(array $creative = [], string $state = 'approved'): array
{
    $campaign = SponsorCampaign::factory()->{$state}()->create();
    $campaign->placements()->attach(e141Placement()->id);
    $creative = SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly($creative + ['title' => 'A']);

    return [$campaign, $creative];
}

function e141NewCreativeData(array $override = []): array
{
    return $override + ['title' => 'B جديدة', 'destination_url' => 'https://b.example.com', 'sort_order' => 0, 'is_active' => true];
}

function e141History(SponsorCreative $creative): void
{
    $placementId = e141Placement()->id;
    AdImpression::create(['ad_placement_id' => $placementId, 'sponsor_campaign_id' => $creative->sponsor_campaign_id, 'sponsor_creative_id' => $creative->id, 'rendered_at' => now()]);
    AdClick::create(['ad_placement_id' => $placementId, 'sponsor_campaign_id' => $creative->sponsor_campaign_id, 'sponsor_creative_id' => $creative->id, 'clicked_at' => now()]);
}

function e141Panel(): void
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

// ============================ FIX #1: مواد جديدة لا تتجاوز المراجعة ============================

test('E14.1-A/48: a new creative on an approved campaign resets review, serves nothing, and becomes eligible only after re-approval', function () {
    [$campaign] = e141Approved(['sort_order' => 5]);
    expect($this->serving->serve(e141Home(), false)->hasAd)->toBeTrue();

    $this->campaigns->createCreative($campaign, e141NewCreativeData(['title' => 'B الجديدة']), $this->admin);

    $fresh = $campaign->fresh();
    expect($fresh->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and($fresh->approved_by)->toBeNull()
        ->and($fresh->approved_at)->toBeNull()
        ->and($this->serving->serve(e141Home(), false)->hasAd)->toBeFalse(); // لا الجديدة ولا القديمة

    $this->campaigns->approve($campaign, $this->admin);

    expect($this->serving->serve(e141Home(), false)->title)->toBe('B الجديدة'); // sort_order 0 قبل 5
});

test('E14.1-B/49: a new creative on a PAUSED campaign resets review and Resume is refused', function () {
    [$campaign] = e141Approved([], 'paused');

    $this->campaigns->createCreative($campaign, e141NewCreativeData(), $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and(fn () => $this->campaigns->resume($campaign, $this->admin))->toThrow(AdInvariantViolation::class);
});

test('E14.1-C/47: editing a creative while PAUSED resets review (not paused) and Resume is unavailable', function () {
    [$campaign, $creative] = e141Approved([], 'paused');

    $this->campaigns->updateCreative($creative, ['title' => 'عنوان معدَّل أثناء الإيقاف'], $this->admin);

    $fresh = $campaign->fresh();
    expect($fresh->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->approved_by)->toBeNull()
        ->and(fn () => $this->campaigns->resume($campaign, $this->admin))->toThrow(AdInvariantViolation::class);
});

test('E14.1-C: changing the sponsor identity of a PAUSED campaign directly via Eloquent also resets review', function () {
    [$campaign] = e141Approved([], 'paused');

    $campaign->update(['sponsor_name' => 'راعٍ مختلف كليًا']);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

test('E14.1-D/6: the invariant is domain-level - direct Eloquent create/update (no service, no Filament) cannot leave an unreviewed creative live', function () {
    [$campaign, $creative] = e141Approved();

    SponsorCreative::create(e141NewCreativeData(['sponsor_campaign_id' => $campaign->id]));
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW)
        ->and($this->serving->serve(e141Home(), false)->hasAd)->toBeFalse();

    // نعيد الاعتماد ثم نعدّل مادة موجودة مباشرة.
    $campaign->forceFill(['status' => SponsorCampaign::STATUS_APPROVED])->saveQuietly();
    $creative->update(['destination_url' => 'https://changed.example.com']);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

test('every sensitive creative field resets review; non-sensitive ones (is_active, sort_order) do not', function (string $field, mixed $value, bool $resets) {
    [$campaign, $creative] = e141Approved();

    $creative->update([$field => $value]);

    expect($campaign->fresh()->status)->toBe($resets ? SponsorCampaign::STATUS_PENDING_REVIEW : SponsorCampaign::STATUS_APPROVED);
})->with([
    'title' => ['title', 'x', true],
    'body' => ['body', 'نص جديد', true],
    'cta_label' => ['cta_label', 'زر', true],
    'destination_url' => ['destination_url', 'https://z.example.com', true],
    'image_path' => ['image_path', 'sponsor-creatives/a.png', true],
    'alt_text' => ['alt_text', 'وصف', true],
    'is_active' => ['is_active', false, false],
    'sort_order' => ['sort_order', 9, false],
]);

test('adding a creative to draft / pending / rejected campaigns does not change their state', function (string $state, string $expected) {
    $campaign = SponsorCampaign::factory()->{$state}()->create();

    $this->campaigns->createCreative($campaign, e141NewCreativeData(), $this->admin);

    expect($campaign->fresh()->status)->toBe($expected);
})->with([
    ['draft', SponsorCampaign::STATUS_DRAFT],
    ['pendingReview', SponsorCampaign::STATUS_PENDING_REVIEW],
    ['rejected', SponsorCampaign::STATUS_REJECTED],
]);

test('changing only priority on a paused/approved campaign keeps approval (priority is not content)', function () {
    [$campaign] = e141Approved();

    $this->campaigns->updateCampaignMeta($campaign, ['priority' => 77], $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED)->and($campaign->fresh()->priority)->toBe(77);
});

test('deleting a creative from an approved campaign (no history) is a content change and resets review', function () {
    [$campaign, $creative] = e141Approved();
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly(['title' => 'ثانية']);

    $this->campaigns->deleteCreative($creative, $this->admin);

    expect(SponsorCreative::find($creative->id))->toBeNull()
        ->and($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

// ============================ HTTPS: إنشاء وتعديل على مستوى النطاق ============================

test('E14.1-E/50: unsafe destination URLs are rejected on CREATE at the domain level - no row, campaign untouched', function (string $url) {
    [$campaign] = e141Approved();

    expect(fn () => $campaign->creatives()->create(e141NewCreativeData(['destination_url' => $url])))
        ->toThrow(AdInvariantViolation::class)
        ->and(fn () => $this->campaigns->createCreative($campaign, e141NewCreativeData(['destination_url' => $url]), $this->admin))
        ->toThrow(AdInvariantViolation::class);

    expect(SponsorCreative::where('sponsor_campaign_id', $campaign->id)->count())->toBe(1) // الأصلية فقط
        ->and($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED); // رُفض قبل إبطال أي شيء
})->with([
    'http' => 'http://example.com',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,x',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.com/a',
    'malformed' => 'not a url',
    'empty' => '',
    'userinfo' => 'https://user:pass@example.com',
]);

test('unsafe destination URL on UPDATE is rejected and the stored value is unchanged', function () {
    [$campaign, $creative] = e141Approved(['destination_url' => 'https://ok.example.com']);

    expect(fn () => $this->campaigns->updateCreative($creative, ['destination_url' => 'http://insecure.example.com'], $this->admin))
        ->toThrow(AdInvariantViolation::class);

    expect($creative->fresh()->destination_url)->toBe('https://ok.example.com')
        ->and($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED);
});

// ============================ FIX #2: لا حذف يمحو التحليلات ============================

test('E14.1-F/19: a creative with analytics cannot be hard-deleted (model) and its history survives', function () {
    [, $creative] = e141Approved();
    e141History($creative);

    expect(fn () => $creative->delete())->toThrow(AdInvariantViolation::class)
        ->and(fn () => $this->campaigns->deleteCreative($creative, $this->admin))->toThrow(AdInvariantViolation::class);

    expect(SponsorCreative::find($creative->id))->not->toBeNull()
        ->and(AdImpression::count())->toBe(1)
        ->and(AdClick::count())->toBe(1);
});

test('E14.1-G: a campaign with analytics cannot be hard-deleted, in any status, and its history survives', function (string $state) {
    [$campaign, $creative] = e141Approved([], $state);
    e141History($creative);

    expect(fn () => $campaign->delete())->toThrow(AdInvariantViolation::class);

    expect(SponsorCampaign::find($campaign->id))->not->toBeNull()
        ->and(AdImpression::count())->toBe(1)
        ->and(AdClick::count())->toBe(1);
})->with(['draft', 'approved', 'rejected']);

test('a campaign is deletable only when it is draft/rejected AND has no history', function () {
    [$approved] = e141Approved();
    expect(fn () => $approved->delete())->toThrow(AdInvariantViolation::class);

    $draft = SponsorCampaign::factory()->create();
    SponsorCreative::factory()->for($draft, 'campaign')->createQuietly();
    $draft->delete();

    expect(SponsorCampaign::find($draft->id))->toBeNull()
        ->and(SponsorCreative::where('sponsor_campaign_id', $draft->id)->count())->toBe(0);
});

test('E14.1-H: a placement with analytics or with assigned campaigns cannot be deleted; an unused one can', function () {
    [, $creative] = e141Approved();
    $used = e141Placement();
    expect(fn () => $used->delete())->toThrow(AdInvariantViolation::class); // مُسنَد لحملة

    e141History($creative);
    $campaign = $creative->campaign;
    $campaign->placements()->detach();
    expect(fn () => $used->fresh()->delete())->toThrow(AdInvariantViolation::class); // له تاريخ

    $unused = AdPlacement::factory()->create();
    $unused->delete();
    expect(AdPlacement::find($unused->id))->toBeNull()
        ->and(AdImpression::count())->toBe(1);
});

test('E14.1-I/55: even bypassing models entirely (raw query-builder deletes), the database FK blocks deleting analytics parents', function () {
    [$campaign, $creative] = e141Approved();
    e141History($creative);
    $placementId = e141Placement()->id;

    expect(fn () => DB::table('sponsor_creatives')->where('id', $creative->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('sponsor_campaigns')->where('id', $campaign->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('ad_placements')->where('id', $placementId)->delete())->toThrow(QueryException::class);

    expect(AdImpression::count())->toBe(1)->and(AdClick::count())->toBe(1)
        ->and(SponsorCreative::count())->toBe(1);
});

// ============================ FIX #3: ثوابت الاعتماد ============================

function e141Pending(array $campaign = []): SponsorCampaign
{
    $c = SponsorCampaign::factory()->pendingReview()->create($campaign);
    $c->placements()->attach(e141Placement()->id);
    SponsorCreative::factory()->for($c, 'campaign')->createQuietly();

    return $c;
}

test('E14.1-J/52: approve() refuses a campaign with zero placements', function () {
    $campaign = SponsorCampaign::factory()->pendingReview()->create();
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class, 'موضع');
    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_PENDING_REVIEW);
});

test('E14.1-K/53: approve() refuses a campaign whose creatives are all inactive', function () {
    $campaign = e141Pending();
    $campaign->creatives()->update(['is_active' => false]); // تحديث استعلام مباشر (محاكاة تعطيل بعد الإرسال)

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class, 'مادة');
});

test('approve() refuses an unknown (non-registry) placement', function () {
    $campaign = SponsorCampaign::factory()->pendingReview()->create();
    $campaign->placements()->attach(AdPlacement::factory()->create()->id); // مفتاحه ليس بسجل الكود
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class, 'غير معروف');
});

test('schedule with starts_at after ends_at blocks submit and approve', function () {
    $draft = SponsorCampaign::factory()->create(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDay()]);
    $draft->placements()->attach(e141Placement()->id);
    SponsorCreative::factory()->for($draft, 'campaign')->createQuietly();
    expect(fn () => $this->campaigns->submitForReview($draft))->toThrow(AdInvariantViolation::class, 'جدولة');

    $pending = e141Pending(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDay()]);
    expect(fn () => $this->campaigns->approve($pending, $this->admin))->toThrow(AdInvariantViolation::class, 'جدولة');
});

test('E14.1-L: approve() re-validates destination URLs from the database at approval time', function () {
    $campaign = e141Pending();
    $campaign->creatives()->first()->forceFill(['destination_url' => 'javascript:alert(1)'])->saveQuietly();

    expect(fn () => $this->campaigns->approve($campaign, $this->admin))->toThrow(AdInvariantViolation::class);
});

test('E14.1-23/24: approve() re-checks placement and active creative AT EXECUTION time, even after a valid submit', function () {
    $draft = SponsorCampaign::factory()->create();
    $draft->placements()->attach(e141Placement()->id);
    SponsorCreative::factory()->for($draft, 'campaign')->createQuietly();
    $this->campaigns->submitForReview($draft);

    $draft->placements()->detach(); // تغيّرت العلاقات بين الإرسال والاعتماد
    expect(fn () => $this->campaigns->approve($draft, $this->admin))->toThrow(AdInvariantViolation::class, 'موضع');

    $draft->placements()->attach(e141Placement()->id);
    $draft->creatives()->update(['is_active' => false]);
    expect(fn () => $this->campaigns->approve($draft, $this->admin))->toThrow(AdInvariantViolation::class, 'مادة');
});

test('submitForReview requires a placement and an active creative (fail early, clear message)', function () {
    $noPlacement = SponsorCampaign::factory()->create();
    SponsorCreative::factory()->for($noPlacement, 'campaign')->createQuietly();
    expect(fn () => $this->campaigns->submitForReview($noPlacement))->toThrow(AdInvariantViolation::class, 'موضع');

    $noCreative = SponsorCampaign::factory()->create();
    $noCreative->placements()->attach(e141Placement()->id);
    expect(fn () => $this->campaigns->submitForReview($noCreative))->toThrow(AdInvariantViolation::class, 'مادة');
});

test('E14.1-26: resume re-validates the campaign (placement/active creative) and does not require being inside its window', function () {
    [$campaign] = e141Approved([], 'paused');
    $campaign->placements()->detach();
    expect(fn () => $this->campaigns->resume($campaign, $this->admin))->toThrow(AdInvariantViolation::class, 'موضع');

    // جدولة مستقبلية مسموحة عند الاستئناف (الحالة تتغير، والعرض يبقى مشروطًا بـnow()).
    [$future] = e141Approved([], 'paused');
    $future->update(['starts_at' => now()->addDays(3)]);
    $this->campaigns->resume($future, $this->admin);
    expect($future->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED)
        ->and($future->fresh()->isCurrentlyWithinSchedule())->toBeFalse();
});

test('a fully valid campaign still goes through submit -> approve and writes audit entries', function () {
    $campaign = SponsorCampaign::factory()->create();
    $campaign->placements()->attach(e141Placement()->id);
    SponsorCreative::factory()->for($campaign, 'campaign')->createQuietly();

    $this->campaigns->submitForReview($campaign, $this->admin);
    $this->campaigns->approve($campaign, $this->admin);

    expect($campaign->fresh()->status)->toBe(SponsorCampaign::STATUS_APPROVED)
        ->and($campaign->fresh()->approved_by)->toBe($this->admin->id)
        ->and(OperationalAuditLog::where('action', 'ads.campaign.submitted')->exists())->toBeTrue()
        ->and(OperationalAuditLog::where('action', 'ads.campaign.approved')->exists())->toBeTrue();
});

test('a review reset caused by content change is audited', function () {
    [, $creative] = e141Approved();

    $this->campaigns->updateCreative($creative, ['title' => 'جديد'], $this->admin);

    expect(OperationalAuditLog::where('action', 'ads.campaign.sensitive_edit_reset_review')->exists())->toBeTrue();
});
