<?php

use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\Resources\SeasonResource\RelationManagers\AnnouncementsRelationManager;
use App\Filament\Resources\SeasonResource\RelationManagers\ContentItemsRelationManager;
use App\Filament\Resources\SeasonResource\RelationManagers\PhasesRelationManager;
use App\Models\Campaign;
use App\Models\OperationalAuditLog;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use App\Models\SeasonPhase;
use App\Services\Seasons\SeasonAnalyticsService;
use App\Services\Seasons\SeasonException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/*
| E42 / الإدارة: صفحات Filament (قائمة، إنشاء، تعديل + إجراءات الرأس) ومدراء العلاقات الثلاثة والتحليلات. كل كتابة تمرّ بـSeasonBuilderService.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

function e42_rm(string $class, Season $season)
{
    return Livewire::test($class, ['ownerRecord' => $season, 'pageClass' => EditSeason::class]);
}

// ───────────────────────────── القائمة والتعديل ─────────────────────────────

test('E42-AD-01: the list shows the derived lifecycle label and readiness; a player cannot reach it', function () {
    $live = e42_live();
    $draft = e42_season(['published' => false]);

    $this->actingAs(e42_admin());
    Livewire::test(ListSeasons::class)->assertSuccessful()->assertCanSeeTableRecords([$live, $draft])->assertSee('مباشر الآن')->assertSee('مسودة');

    $this->actingAs(e16User())->get('/admin/seasons')->assertForbidden();
});

test('E42-AD-02: the edit page renders with the three relation managers and the readiness / analytics modals open without error', function () {
    $season = e42_live();
    $this->actingAs(e42_admin());

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->assertSuccessful()
        ->callAction('readiness')->assertHasNoActionErrors()
        ->callAction('analytics')->assertHasNoActionErrors();

    expect(\App\Filament\Resources\SeasonResource::getRelations())->toBe([PhasesRelationManager::class, ContentItemsRelationManager::class, AnnouncementsRelationManager::class]);
});

test('E42-AD-03: publish action is gated by readiness (blocked while the campaign is empty) and by the seasons.publish permission', function () {
    $bare = e42_season(['published' => false, 'structure' => false]);
    $ready = e42_season(['published' => false]);

    $this->actingAs(e42_admin());
    Livewire::test(EditSeason::class, ['record' => $bare->getRouteKey()])->callAction('publish');
    expect($bare->fresh()->is_published)->toBeFalse();

    Livewire::test(EditSeason::class, ['record' => $ready->getRouteKey()])->callAction('publish');
    expect($ready->fresh()->is_published)->toBeTrue();

    Livewire::test(EditSeason::class, ['record' => $ready->getRouteKey()])->callAction('unpublish');
    expect($ready->fresh()->is_published)->toBeFalse();

    $editor = e42_staff(['seasons.view', 'seasons.update']);
    $this->actingAs($editor);
    Livewire::test(EditSeason::class, ['record' => $ready->getRouteKey()])->assertActionHidden('publish')->assertActionHidden('archive');
});

test('E42-AD-04: archive action ends a live season and freezes it; it is hidden for drafts', function () {
    $live = e42_live();
    $draft = e42_season(['published' => false]);
    $this->actingAs(e42_admin());

    Livewire::test(EditSeason::class, ['record' => $draft->getRouteKey()])->assertActionHidden('archive');
    Livewire::test(EditSeason::class, ['record' => $live->getRouteKey()])->assertActionVisible('archive')->callAction('archive');

    expect(e42_life()->state($live->fresh('campaign')))->toBe('archived')
        ->and(OperationalAuditLog::query()->where('action', 'season_archived')->count())->toBe(1);
});

test('E42-AD-05: saving the edit form with an inverted window is refused (nothing saved); a valid one is saved through the checks', function () {
    $season = e42_live();
    $this->actingAs(e42_admin());

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->fillForm(['ends_at' => '2026-11-01 00:00:00'])
        ->call('save');
    expect($season->fresh()->ends_at->toDateString())->toBe('2026-12-30');

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->fillForm(['ends_at' => '2027-01-15 00:00:00'])
        ->call('save')->assertHasNoFormErrors();
    expect($season->fresh()->ends_at->toDateString())->toBe('2027-01-15');
});

test('E42-AD-06: a plain save of a started season does not trip the start lock (form minute precision)', function () {
    $season = e42_live();
    Season::query()->whereKey($season->id)->update(['starts_at' => '2026-11-30 00:00:41']);
    $this->actingAs(e42_admin());

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])->call('save')->assertHasNoFormErrors();

    expect($season->fresh()->starts_at->toDateTimeString())->toBe('2026-11-30 00:00:41');
});

test('E42-AD-07: an editor without seasons.publish saving is_published=true leaves the season unpublished', function () {
    $season = e42_season(['published' => false]);
    $this->actingAs(e42_staff(['seasons.view', 'seasons.update']));

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])->fillForm(['is_published' => true])->call('save');

    expect($season->fresh()->is_published)->toBeFalse();
});

test('E42-AD-08: creating a season validates the reserved slug, the theme whitelist and the window', function () {
    $campaign = e42_campaign();
    $this->actingAs(e42_admin());

    Livewire::test(CreateSeason::class)
        ->fillForm(['campaign_id' => $campaign->id, 'code' => 'S9', 'slug' => 'archive', 'theme_config' => ['preset' => 'generic']])
        ->call('create')->assertHasFormErrors(['slug']);
    expect(Season::query()->count())->toBe(0);

    Livewire::test(CreateSeason::class)
        ->fillForm(['campaign_id' => $campaign->id, 'code' => 'S9', 'slug' => 'winter', 'theme_config' => ['preset' => 'generic'], 'starts_at' => '2026-12-20 00:00:00', 'ends_at' => '2026-12-19 00:00:00'])
        ->call('create');
    expect(Season::query()->count())->toBe(0);

    Livewire::test(CreateSeason::class)
        ->fillForm(['campaign_id' => $campaign->id, 'code' => 'S9', 'slug' => 'winter', 'theme_config' => ['preset' => 'aseel', 'hero_tagline' => 'رحلة الشتاء'], 'starts_at' => '2026-12-20 00:00:00', 'ends_at' => '2026-12-30 00:00:00'])
        ->call('create')->assertHasNoFormErrors();

    $season = Season::query()->where('slug', 'winter')->first();
    expect($season)->not->toBeNull()->and($season->themePreset())->toBe('aseel')->and($season->heroTagline())->toBe('رحلة الشتاء')
        ->and($season->is_published)->toBeFalse();
});

test('E42-AD-09: the delete action is hidden for a season that has started (archive instead)', function () {
    $live = e42_live();
    $upcoming = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);
    $this->actingAs(e42_admin());

    Livewire::test(ListSeasons::class)->assertTableActionHidden('delete', $live->fresh())->assertTableActionVisible('delete', $upcoming->fresh());
});

// ───────────────────────────── مدير المراحل ─────────────────────────────

test('E42-AD-10: phases manager - add, edit and delete go through the builder; an overlap is refused with a notification, not a crash', function () {
    $season = e42_live();
    $this->actingAs(e42_admin());

    e42_rm(PhasesRelationManager::class, $season)->assertSuccessful()
        ->callTableAction('addPhase', data: ['name' => 'الفصل الأول', 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-10 00:00:00', 'sort_order' => 0])
        ->assertHasNoTableActionErrors();
    expect(SeasonPhase::query()->count())->toBe(1);

    e42_rm(PhasesRelationManager::class, $season)
        ->callTableAction('addPhase', data: ['name' => 'متداخلة', 'starts_at' => '2026-12-05 00:00:00', 'ends_at' => '2026-12-12 00:00:00', 'sort_order' => 0]);
    expect(SeasonPhase::query()->count())->toBe(1);

    $phase = SeasonPhase::query()->first();
    e42_rm(PhasesRelationManager::class, $season)->callTableAction('editPhase', $phase, data: ['name' => 'الأول (معدّل)', 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-09 00:00:00', 'sort_order' => 1, 'accent' => 'gold']);
    expect($phase->fresh()->name)->toBe('الأول (معدّل)')->and($phase->fresh()->themeAccent())->toBe('gold');

    e42_rm(PhasesRelationManager::class, $season)->callTableAction('deletePhase', $phase);
    expect(SeasonPhase::query()->count())->toBe(0);
});

test('E42-AD-11: a started phase has no delete action; a view-only staff member sees the manager read-only', function () {
    $season = e42_live();
    $phase = e42_phase($season, 'بدأت', '2026-12-01 00:00:00', '2026-12-10 00:00:00');
    SeasonPhase::query()->whereKey($phase->id)->update(['went_live_at' => now()]);

    $this->actingAs(e42_admin());
    e42_rm(PhasesRelationManager::class, $season)->assertTableActionHidden('deletePhase', $phase->fresh())->assertTableActionVisible('editPhase', $phase->fresh());

    $this->actingAs(e42_staff(['seasons.view']));
    e42_rm(PhasesRelationManager::class, $season)->assertSuccessful()->assertTableActionHidden('editPhase', $phase->fresh());

    $this->actingAs(e42_plain_staff());
    expect(PhasesRelationManager::canViewForRecord($season, EditSeason::class))->toBeFalse();
});

// ───────────────────────────── مدير المحتوى ─────────────────────────────

test('E42-AD-12: content manager - link a campaign / competition / pool, refuse the primary campaign, unlink without deleting the entity', function () {
    $season = e42_live();
    $extra = e42_campaign();
    $event = e17Event();
    $pool = e34_pool(e34_quests(2));
    $this->actingAs(e42_admin());

    foreach ([[SeasonContentItem::TYPE_CAMPAIGN, $extra->id], [SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id], [SeasonContentItem::TYPE_MISSION_POOL, $pool->id]] as [$type, $id]) {
        e42_rm(ContentItemsRelationManager::class, $season)->callTableAction('addContent', data: ['type' => $type, 'target_id' => $id, 'sort_order' => 0]);
    }
    expect($season->contentItems()->count())->toBe(3);

    e42_rm(ContentItemsRelationManager::class, $season)->callTableAction('addContent', data: ['type' => SeasonContentItem::TYPE_CAMPAIGN, 'target_id' => $season->campaign_id, 'sort_order' => 0]);
    expect($season->contentItems()->count())->toBe(3);

    $link = $season->contentItems()->where('content_type', SeasonContentItem::TYPE_CAMPAIGN)->first();
    e42_rm(ContentItemsRelationManager::class, $season)->callTableAction('unlink', $link);
    expect($season->contentItems()->count())->toBe(2)->and(Campaign::query()->whereKey($extra->id)->exists())->toBeTrue();
});

// ───────────────────────────── مدير الإعلانات ─────────────────────────────

test('E42-AD-13: announcements manager - an editor can write but cannot request a notification; a publisher can', function () {
    $season = e42_live();
    $data = ['title' => 'خبر', 'body' => 'نص الإعلان', 'published_at' => '2026-12-11 10:00:00', 'sort_order' => 0];

    $this->actingAs(e42_staff(['seasons.view', 'seasons.update']));
    e42_rm(AnnouncementsRelationManager::class, $season)->callTableAction('addAnnouncement', data: $data + ['notify_audience' => 'all']);
    expect(SeasonAnnouncement::query()->count())->toBe(1)->and(SeasonAnnouncement::query()->first()->notify_audience)->toBeNull(); // الحقل مخفي للمحرّر فلا يصل

    $this->actingAs(e42_staff(['seasons.view', 'seasons.update', 'seasons.publish']));
    e42_rm(AnnouncementsRelationManager::class, $season)->callTableAction('addAnnouncement', data: ['title' => 'خبر مُشعَر', 'notify_audience' => 'participants'] + $data);
    expect(SeasonAnnouncement::query()->where('title', 'خبر مُشعَر')->first()->notify_audience)->toBe('participants');
});

test('E42-AD-14: announcement HTML is refused by the builder through the form (plain text only)', function () {
    $season = e42_live();
    $this->actingAs(e42_admin());

    e42_rm(AnnouncementsRelationManager::class, $season)->callTableAction('addAnnouncement', data: ['title' => 'خبر', 'body' => '<script>alert(1)</script>', 'sort_order' => 0]);

    expect(SeasonAnnouncement::query()->count())->toBe(0);
});

// ───────────────────────────── التحليلات ─────────────────────────────

test('E42-AD-15: analytics are aggregate-only, permission-checked inside the service and honest about what is not tracked', function () {
    $season = e42_live();
    $extra = e42_campaign();
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);
    e42_phase($season, 'مرحلة', '2026-12-01 00:00:00', '2026-12-20 00:00:00');
    e42_join($season);

    $stats = app(SeasonAnalyticsService::class)->overview(e42_admin(), $season->fresh());

    expect($stats['primary']['participants'])->toBe(1)
        ->and($stats['extra_campaigns'])->toHaveCount(1)
        ->and($stats['not_tracked'])->toBe(SeasonAnalyticsService::NOT_TRACKED)
        ->and(json_encode($stats, JSON_UNESCAPED_UNICODE))->not->toContain('@')   // لا إيميلات
        ->and(fn () => app(SeasonAnalyticsService::class)->overview(e42_plain_staff(), $season))->toThrow(SeasonException::class);

    $this->actingAs(e42_admin());
    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])->mountAction('analytics')->assertSee('غير متاح بعد');
});

test('E42-AD-16: analytics are read-only - opening them writes nothing', function () {
    $season = e42_live();
    e42_join($season);
    $tables = ['season_phases', 'season_announcements', 'season_content_items', 'operational_audit_logs', 'notifications'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

    app(SeasonAnalyticsService::class)->overview(e42_admin(), $season->fresh());

    foreach ($tables as $t) {
        expect(DB::table($t)->count())->toBe($before[$t], $t);
    }
});
