<?php

use App\Models\SeasonContentItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/*
| E42 - حدود الموسم في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت: المفاتيح الفريدة (مرحلة/كيان لكل موسم مع تعايش NULL)، سياسة الحذف (الموسم يملك مراحله وإعلاناته وروابطه؛ حذف الكيان المرتبط يزيل الرابط فقط؛ حذف المسار يفكّ الربط)،
| النص العربي حتى حدود الأعمدة، الفهارس المسماة، وعدم وجود float/decimal. السباق الحقيقي بين عمليات PHP في tools/e42/concurrency-probe.php.
*/

beforeEach(fn () => e42_seed());

function e42_db_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function e42_insert_phase(int $seasonId, string $key, array $over = []): int
{
    return (int) DB::table('season_phases')->insertGetId($over + [
        'season_id' => $seasonId, 'public_id' => (string) Str::ulid(), 'internal_key' => $key, 'name' => 'مرحلة '.$key,
        'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-05 00:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('E42-DB-01: the season window columns are nullable (NULL = follow the campaign) and there is no stored state column', function () {
    foreach (['starts_at', 'ends_at', 'announcement_at', 'archive_at', 'ahjiyat_pass_id'] as $column) {
        expect(Schema::hasColumn('seasons', $column))->toBeTrue($column);
    }

    $nullable = collect(Schema::getColumns('seasons'))->keyBy('name');

    foreach (['starts_at', 'ends_at', 'announcement_at', 'archive_at', 'ahjiyat_pass_id'] as $column) {
        expect($nullable[$column]['nullable'])->toBeTrue($column);
    }

    foreach (['status', 'state', 'is_active', 'is_live'] as $stored) {
        expect(Schema::hasColumn('seasons', $stored))->toBeFalse($stored);
    }
});

test('E42-DB-02: a phase internal key is unique per season only, and a public id is globally unique', function () {
    $a = e42_live();
    $b = e42_live();
    e42_insert_phase($a->id, 'opening');

    expect(e42_db_violates(fn () => e42_insert_phase($a->id, 'opening')))->toBeTrue()
        ->and(e42_db_violates(fn () => e42_insert_phase($b->id, 'opening')))->toBeFalse()
        ->and(e42_db_violates(fn () => DB::table('season_phases')->insert(['season_id' => $a->id, 'public_id' => str_repeat('A', 26), 'internal_key' => 'x1', 'name' => 'x', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()])))->toBeFalse()
        ->and(e42_db_violates(fn () => DB::table('season_phases')->insert(['season_id' => $a->id, 'public_id' => str_repeat('A', 26), 'internal_key' => 'x2', 'name' => 'x', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()])))->toBeTrue();
});

test('E42-DB-03: one link per entity per season for each type - the database refuses the duplicate and lets NULLs coexist', function () {
    $season = e42_live();
    $other = e42_live();
    $extra = e42_campaign();
    $event = e17Event();
    $pool = e34_pool(e34_quests(1));
    $now = now();
    $row = fn (string $type, string $col, int $id, int $seasonId) => [
        'season_id' => $seasonId, 'content_type' => $type, $col => $id, 'sort_order' => 0, 'is_featured' => false, 'created_at' => $now, 'updated_at' => $now,
    ];

    DB::table('season_content_items')->insert($row('campaign', 'campaign_id', $extra->id, $season->id));
    DB::table('season_content_items')->insert($row('competitive_event', 'competitive_event_id', $event->id, $season->id));
    DB::table('season_content_items')->insert($row('mission_pool', 'mission_pool_id', $pool->id, $season->id)); // الأعمدة الأخرى NULL وتتعايش

    expect(e42_db_violates(fn () => DB::table('season_content_items')->insert($row('campaign', 'campaign_id', $extra->id, $season->id))))->toBeTrue()
        ->and(e42_db_violates(fn () => DB::table('season_content_items')->insert($row('competitive_event', 'competitive_event_id', $event->id, $season->id))))->toBeTrue()
        ->and(e42_db_violates(fn () => DB::table('season_content_items')->insert($row('mission_pool', 'mission_pool_id', $pool->id, $season->id))))->toBeTrue()
        ->and(e42_db_violates(fn () => DB::table('season_content_items')->insert($row('competitive_event', 'competitive_event_id', $event->id, $other->id))))->toBeFalse(); // موسم آخر يستطيع ربط المنافسة نفسها
});

test('E42-DB-04: a link must point at an entity that exists (real foreign keys, no polymorphic orphans)', function () {
    $season = e42_live();
    $bad = fn (string $col) => [
        'season_id' => $season->id, 'content_type' => 'x', $col => 987654, 'sort_order' => 0, 'is_featured' => false, 'created_at' => now(), 'updated_at' => now(),
    ];

    foreach (['campaign_id', 'competitive_event_id', 'mission_pool_id', 'season_phase_id'] as $col) {
        expect(e42_db_violates(fn () => DB::table('season_content_items')->insert($bad($col))))->toBeTrue($col);
    }

    expect(e42_db_violates(fn () => e42_insert_phase(987654, 'orphan')))->toBeTrue();
});

test('E42-DB-05: deletion policy - the season owns phases / announcements / links; the linked entity is retained; deleting an entity removes only the link', function () {
    $season = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);
    $extra = e42_campaign();
    $event = e17Event();
    $phase = e42_phase($season, 'م', '2026-12-21 00:00:00', '2026-12-22 00:00:00');
    $note = e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'خبر', 'body' => 'ن', 'season_phase_id' => $phase->id]);
    $campaignLink = e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id, ['season_phase_id' => $phase->id]);
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id);

    // حذف المنافسة المرتبطة يزيل الرابط فقط.
    DB::table('competitive_events')->where('id', $event->id)->delete();
    expect(DB::table('season_content_items')->where('season_id', $season->id)->count())->toBe(1);

    // حذف المرحلة يفكّ الإعلان والرابط ولا يحذفهما.
    DB::table('season_phases')->where('id', $phase->id)->delete();
    expect(DB::table('season_announcements')->where('id', $note->id)->value('season_phase_id'))->toBeNull()
        ->and(DB::table('season_content_items')->where('id', $campaignLink->id)->value('season_phase_id'))->toBeNull();

    // حذف الموسم يزيل ما يملكه فقط.
    e42_phase($season, 'م2', '2026-12-23 00:00:00', '2026-12-24 00:00:00');
    $season->fresh()->delete();

    expect(DB::table('season_phases')->count())->toBe(0)->and(DB::table('season_announcements')->count())->toBe(0)->and(DB::table('season_content_items')->count())->toBe(0)
        ->and(DB::table('campaigns')->where('id', $extra->id)->exists())->toBeTrue();
});

test('E42-DB-06: deleting a draft pass unlinks it from the season instead of deleting the season', function () {
    $pass = e40_pass(['publish' => false]);
    $season = e42_live();
    e42_builder()->linkPass(e42_admin(), $season, $pass->id);

    DB::table('ahjiyat_passes')->where('id', $pass->id)->delete();

    expect(DB::table('seasons')->where('id', $season->id)->exists())->toBeTrue()
        ->and(DB::table('seasons')->where('id', $season->id)->value('ahjiyat_pass_id'))->toBeNull();
});

test('E42-DB-07: Arabic text round-trips up to the column limits (phase name 120, announcement title 160, body 2000)', function () {
    $season = e42_live();
    $name = str_repeat('ا', 120);
    $title = str_repeat('خ', 160);
    $body = str_repeat('نص عربي ', 249).'ن'; // 1993 حرفًا
    config(['seasons.limits.announcement_body_max' => 2000]);

    $phase = e42_phase($season, $name, '2026-12-01 00:00:00', '2026-12-02 00:00:00');
    $note = e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => $title, 'body' => $body, 'publish_now' => true]);

    expect(DB::table('season_phases')->where('id', $phase->id)->value('name'))->toBe($name)
        ->and(DB::table('season_announcements')->where('id', $note->id)->value('title'))->toBe($title)
        ->and(DB::table('season_announcements')->where('id', $note->id)->value('body'))->toBe($body);
});

test('E42-DB-08: named indexes exist and no float / decimal columns were introduced', function () {
    $indexNames = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexNames('seasons'))->toContain('seasons_publication_window_idx')
        ->and($indexNames('season_phases'))->toContain('season_phases_key_unique')->toContain('season_phases_public_unique')->toContain('season_phases_season_start_idx')
        ->and($indexNames('season_announcements'))->toContain('season_announcements_public_unique')->toContain('season_announcements_season_pub_idx')
        ->and($indexNames('season_content_items'))->toContain('season_items_campaign_unique')->toContain('season_items_event_unique')->toContain('season_items_pool_unique');

    foreach (['season_phases', 'season_announcements', 'season_content_items'] as $table) {
        foreach (Schema::getColumns($table) as $column) {
            expect($column['type_name'])->not->toContain('float')->not->toContain('double')->not->toContain('decimal', $table.'.'.$column['name']);
        }
    }
});
