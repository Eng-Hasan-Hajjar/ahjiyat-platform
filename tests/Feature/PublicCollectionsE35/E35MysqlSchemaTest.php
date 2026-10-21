<?php

use App\Models\EditorialShelf;
use App\Models\User;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Models\UserCollectionSave;
use App\Services\Collections\CollectionAccess;
use App\Services\Collections\CollectionOrderService;
use App\Services\Collections\CollectionReportService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Editorial\EditorialShelfService;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementReconciler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E35 AM13 (106-111) - حدود السلامة في القاعدة نفسها. كلها سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن الصفوف القائمة تبقى خاصة بمواضع حتمية، وأن الفرادة/القيود/المفاتيح الأجنبية لا تعتمد على كود التطبيق وحده.
*/

beforeEach(fn () => e35_seed());

function e35_old_collection(User $owner, string $title = 'مجموعة قديمة'): int
{
    return DB::table('user_collections')->insertGetId(['public_id' => (string) Str::ulid(), 'user_id' => $owner->id, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
}

test('E35-106: existing rows stay private with safe defaults and item positions are rebuilt deterministically from (created_at, id), idempotently', function () {
    $owner = e16User();
    [$w0, $w1, $w2, $w3, $w4] = e35_works(5);

    // صف بصيغة ما قبل E35 (لا يذكر أعمدة E35): القيم الافتراضية للقاعدة هي الخاصة.
    $cid = e35_old_collection($owner);
    $row = DB::table('user_collections')->find($cid);
    expect($row->visibility)->toBe('private')->and((int) $row->saves_count)->toBe(0)->and((int) $row->is_moderation_hidden)->toBe(0)
        ->and($row->first_published_at)->toBeNull()->and($row->moderation_reason)->toBeNull()->and($row->moderation_hidden_by)->toBeNull()
        ->and(app(CollectionAccess::class)->sharedQuery()->where('user_collections.id', $cid)->exists())->toBeFalse();

    // معرّفات تصاعدية w0..w4 لكن created_at مختلف؛ w0 وw4 متعادلان بالوقت ← يحسم id.
    $times = [$w0->id => '2026-10-01 09:00:01', $w1->id => '2026-10-01 09:00:04', $w2->id => '2026-10-01 09:00:00', $w3->id => '2026-10-01 09:00:02', $w4->id => '2026-10-01 09:00:01'];

    foreach ([$w0, $w1, $w2, $w3, $w4] as $w) {
        DB::table('user_collection_items')->insert(['user_collection_id' => $cid, 'creator_puzzle_id' => $w->id, 'created_at' => $times[$w->id]]);
    }

    expect(DB::table('user_collection_items')->where('user_collection_id', $cid)->pluck('position')->map(fn ($p) => (int) $p)->unique()->all())->toBe([0]);

    $migration = require base_path('database/migrations/2026_10_30_000001_evolve_user_collections_for_public_sharing.php');
    $backfill = new ReflectionMethod($migration, 'backfillPositions');
    $backfill->invoke($migration);

    $expected = [$w2->public_id, $w0->public_id, $w4->public_id, $w3->public_id, $w1->public_id];
    $order = fn () => DB::table('user_collection_items as i')->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')->where('i.user_collection_id', $cid)
        ->orderBy('i.position')->orderBy('i.id')->pluck('w.public_id')->all();
    $positions = fn () => DB::table('user_collection_items')->where('user_collection_id', $cid)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();

    expect($order())->toBe($expected)->and($positions())->toBe([1, 2, 3, 4, 5]);

    $backfill->invoke($migration);   // آمن للتكرار
    expect($order())->toBe($expected)->and($positions())->toBe([1, 2, 3, 4, 5]);

    // مجموعة أنشأتها E29 كما هي الآن تبقى خاصة بالافتراضي.
    expect(app(CollectionService::class)->create($owner, 'جديدة بعد E35')->fresh()->visibility)->toBe('private');
});

test('E35-107: a user can save a collection once - enforced by the database, idempotent by the service, and cascades with the collection or the user', function () {
    $owner = e16User();
    $c = e35_public($owner, 3);
    $saver = e16User();
    $svc = app(CollectionSaveService::class);

    expect($svc->save($saver, $c->public_id)['changed'])->toBeTrue()->and($svc->save($saver, $c->public_id)['changed'])->toBeFalse()
        ->and(UserCollectionSave::query()->where('user_collection_id', $c->id)->count())->toBe(1)->and((int) $c->fresh()->saves_count)->toBe(1);

    expect(fn () => DB::table('user_collection_saves')->insert(['user_id' => $saver->id, 'user_collection_id' => $c->id, 'created_at' => now()]))->toThrow(QueryException::class)
        ->and(UserCollectionSave::query()->where('user_collection_id', $c->id)->count())->toBe(1);

    // مستخدم آخر يحفظ المجموعة نفسها، والمستخدم نفسه يحفظ مجموعة أخرى: مسموح.
    $other = e16User();
    $second = e35_public(null, 3, 'ثانية');
    $svc->save($other, $c->public_id);
    $svc->save($saver, $second->public_id);
    expect(UserCollectionSave::query()->count())->toBe(3)->and((int) $c->fresh()->saves_count)->toBe(2);

    // حذف المستخدم الحافظ عبر النموذج: يُنقص العدّاد ولا يبقى صف يتيم (EngagementCleanup + cascade).
    $other->delete();
    expect((int) $c->fresh()->saves_count)->toBe(1)->and(UserCollectionSave::query()->where('user_id', $other->id)->count())->toBe(0);

    // حذف المجموعة ← تُحذف حفظاتها (cascade).
    DB::table('user_collections')->where('id', $c->id)->delete();
    expect(UserCollectionSave::query()->where('user_collection_id', $c->id)->count())->toBe(0)->and(UserCollectionSave::query()->count())->toBe(1);
});

test('E35-108: one open report per (collection, reporter) is a database guarantee; closing frees the slot; closed history may repeat; a lost race gives a clean error', function () {
    $c = e35_public(null, 3);
    $reporter = e16User();
    $other = e16User();
    $row = fn (int $reporterId, ?int $marker, string $status = 'open') => ['user_collection_id' => $c->id, 'reporter_id' => $reporterId, 'reason' => 'spam', 'status' => $status, 'open_marker' => $marker, 'created_at' => now(), 'updated_at' => now()];

    DB::table('user_collection_reports')->insert($row($reporter->id, 1));
    expect(fn () => DB::table('user_collection_reports')->insert($row($reporter->id, 1)))->toThrow(QueryException::class)
        ->and(UserCollectionReport::query()->where('reporter_id', $reporter->id)->count())->toBe(1);

    DB::table('user_collection_reports')->insert($row($other->id, 1));   // مبلّغ آخر: مسموح

    // الإغلاق يحرر الخانة (open_marker=NULL)، وعدة صفوف مغلقة متعايشة (NULL لا يتعارض في UNIQUE على MySQL وSQLite).
    DB::table('user_collection_reports')->where('reporter_id', $reporter->id)->update(['status' => 'dismissed', 'open_marker' => null]);
    DB::table('user_collection_reports')->insert($row($reporter->id, 1));
    DB::table('user_collection_reports')->where('reporter_id', $reporter->id)->where('status', 'open')->update(['status' => 'dismissed', 'open_marker' => null]);
    DB::table('user_collection_reports')->insert($row($reporter->id, 1));
    expect(UserCollectionReport::query()->where('reporter_id', $reporter->id)->count())->toBe(3)->and(UserCollectionReport::query()->where('reporter_id', $reporter->id)->where('status', 'open')->count())->toBe(1);

    // سباق حقيقي: بعد فحص «لا بلاغ مفتوح» يتسلل بلاغ آخر قبل الكتابة ← خطأ نظيف ولا صف مكرر.
    $racer = e16User();
    $injected = false;
    DB::listen(function ($query) use (&$injected, $racer, $row) {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'user_collection_reports') && str_contains($query->sql, 'exists')) {
            $injected = true;
            DB::table('user_collection_reports')->insert($row($racer->id, 1));
        }
    });

    $message = null;

    try {
        app(CollectionReportService::class)->report($racer, $c->public_id, 'spam');
    } catch (EngagementException $e) {
        $message = $e->getMessage();
    }

    // الخاسر يتلقى خطأ نظيفًا (لا استثناء قاعدة بيانات خام) ولا يبقى أكثر من بلاغ مفتوح واحد له. (بقاء صف «الفائز» المحقون نفسه غير مضمون هنا: يشاركه الاتصال والمعاملة نفسيهما فيُتراجع معها).
    expect($injected)->toBeTrue()->and($message)->toContain('بلاغ مفتوح')->and(UserCollectionReport::query()->where('reporter_id', $racer->id)->where('status', 'open')->count())->toBeLessThanOrEqual(1);
});

test('E35-109: item positions stay correct and deterministic (append, remove gaps, reorder normalises, equal positions break ties by id)', function () {
    $owner = e16User();
    $works = e35_works(5);
    $c = e35_collection($owner, $works, 'public');
    $ids = array_map(fn ($w) => $w->public_id, $works);
    $positions = fn () => DB::table('user_collection_items')->where('user_collection_id', $c->id)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();

    expect(e35_order($c))->toBe($ids)->and($positions())->toBe([1, 2, 3, 4, 5]);

    // الإزالة تترك فجوة لكن الترتيب ثابت؛ الإضافة بعدها تُلحق بعد الأكبر (لا تتصادم).
    app(CollectionService::class)->remove($owner, $c->public_id, $ids[1]);
    expect(e35_order($c))->toBe([$ids[0], $ids[2], $ids[3], $ids[4]]);
    $extra = e35_works(1)[0];
    app(CollectionService::class)->add($owner, $c->public_id, $extra->fresh());
    expect(e35_order($c))->toBe([$ids[0], $ids[2], $ids[3], $ids[4], $extra->public_id])->and(count(array_unique($positions())))->toBe(5);

    // إعادة الترتيب تطبّع إلى 1..n.
    $reversed = array_reverse(e35_order($c));
    app(CollectionOrderService::class)->reorder($owner, $c->public_id, $reversed);
    expect(e35_order($c))->toBe($reversed)->and($positions())->toBe([1, 2, 3, 4, 5]);

    // مواضع متساوية (صفوف قديمة/إدخال خام) ← الحسم بـid، فالصفحة العامة والمالك يريان الترتيب نفسه دائمًا.
    DB::table('user_collection_items')->where('user_collection_id', $c->id)->update(['position' => 7]);
    $byId = DB::table('user_collection_items as i')->join('creator_puzzles as w', 'w.id', '=', 'i.creator_puzzle_id')->where('i.user_collection_id', $c->id)->orderBy('i.id')->pluck('w.public_id')->all();
    expect(e35_order($c))->toBe($byId);
    $html = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    $seen = [];

    foreach ($byId as $publicId) {
        $seen[] = strpos($html, $publicId);
    }

    expect(array_filter($seen, fn ($p) => $p === false))->toBe([])->and($seen)->toBe(array_values(collect($seen)->sort()->all()));
});

test('E35-110: a shelf cannot hold the same content twice (database unique), the same content may sit on other shelves, and keys are unique', function () {
    $admin = e35_admin();
    $a = e35_public(null, 3, 'مجموعة أ');
    $b = e35_public(null, 3, 'مجموعة ب');
    $svc = app(EditorialShelfService::class);
    $one = $svc->create($admin, ['internal_key' => 'shelf-one', 'title' => 'رف 1', 'placement' => 'discover']);
    $two = $svc->create($admin, ['internal_key' => 'shelf-two', 'title' => 'رف 2', 'placement' => 'home']);
    $row = fn (int $shelf, string $type, int $id, int $pos) => ['editorial_shelf_id' => $shelf, 'content_type' => $type, 'content_id' => $id, 'position' => $pos, 'created_at' => now(), 'updated_at' => now()];

    DB::table('editorial_shelf_items')->insert($row($one->id, 'public_collection', $a->id, 1));
    expect(fn () => DB::table('editorial_shelf_items')->insert($row($one->id, 'public_collection', $a->id, 2)))->toThrow(QueryException::class);

    // مسموح: النوع مختلف بنفس المعرّف، أو رف آخر بنفس المحتوى.
    DB::table('editorial_shelf_items')->insert($row($one->id, 'community_puzzle', $a->id, 2));
    DB::table('editorial_shelf_items')->insert($row($two->id, 'public_collection', $a->id, 1));
    expect(DB::table('editorial_shelf_items')->count())->toBe(3);

    expect(fn () => DB::table('editorial_shelves')->where('id', $two->id)->update(['internal_key' => 'shelf-one']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('editorial_shelves')->where('id', $two->id)->update(['public_id' => $one->public_id]))->toThrow(QueryException::class);

    // الخدمة ترفض التكرار قبل الكتابة (لا يُكتب شيء)، والقائمة الصحيحة تُكتب بترتيبها.
    $before = DB::table('editorial_shelf_items')->where('editorial_shelf_id', $one->id)->count();
    expect(fn () => $svc->setItems($admin, $one->fresh(), [['public_collection', $a->id], ['public_collection', $a->id], ['public_collection', $b->id]]))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('editorial_shelf_items')->where('editorial_shelf_id', $one->id)->count())->toBe($before);

    $svc->setItems($admin, $one->fresh(), [['public_collection', $a->id], ['public_collection', $b->id]]);
    expect(DB::table('editorial_shelf_items')->where('editorial_shelf_id', $one->id)->count())->toBe(2);
});

test('E35-111: foreign keys have the promised deletion policy and no deletion leaves orphans or counter drift', function () {
    $expected = [
        'user_collection_saves' => ['user_id' => 'cascade', 'user_collection_id' => 'cascade'],
        'user_collection_reports' => ['user_collection_id' => 'cascade', 'reporter_id' => 'cascade', 'handled_by' => 'set null'],
        'editorial_shelves' => ['created_by' => 'set null', 'updated_by' => 'set null'],
        'editorial_shelf_items' => ['editorial_shelf_id' => 'cascade'],
    ];

    foreach ($expected as $table => $columns) {
        $actual = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            $actual[$fk['columns'][0]] = strtolower((string) $fk['on_delete']);
        }

        ksort($actual);
        ksort($columns);
        expect($actual)->toBe($columns);
    }

    $owner = e16User();
    $c = e35_public($owner, 3);
    $saver = e16User();
    $reporter = e16User();
    $moderator = e35_moderator();
    app(CollectionSaveService::class)->save($saver, $c->public_id);
    $report = app(CollectionReportService::class)->report($reporter, $c->public_id, 'spam');
    app(\App\Services\Collections\CollectionModerationService::class)->dismiss($report->fresh(), $moderator, 'لا مخالفة');
    expect($report->fresh()->handled_by)->toBe($moderator->id);

    // حذف المشرف ← handled_by يصير NULL والبلاغ يبقى.
    DB::table('users')->where('id', $moderator->id)->delete();
    expect(UserCollectionReport::query()->find($report->id))->not->toBeNull()->and(UserCollectionReport::query()->find($report->id)->handled_by)->toBeNull();

    // حذف مدير الرف ← created_by NULL والرف يبقى؛ حذف الرف ← عناصره تُحذف.
    $admin = e35_admin();
    $shelf = e35_shelf([['public_collection', $c->id]], 'fk-shelf', 'discover', [], true, $admin);
    DB::table('users')->where('id', $admin->id)->delete();
    expect(EditorialShelf::query()->find($shelf->id)->created_by)->toBeNull()->and(DB::table('editorial_shelf_items')->where('editorial_shelf_id', $shelf->id)->count())->toBe(1);
    DB::table('editorial_shelves')->where('id', $shelf->id)->delete();
    expect(DB::table('editorial_shelf_items')->where('editorial_shelf_id', $shelf->id)->count())->toBe(0);

    // حذف مالك المجموعة ← مجموعته وحفظاتها وبلاغاتها وعناصرها تذهب؛ لا يتامى ولا انحراف عدّادات.
    $reporter2 = e16User();
    app(CollectionReportService::class)->report($reporter2, $c->public_id, 'spam');
    $owner->delete();

    foreach (['user_collections' => 'id', 'user_collection_saves' => 'user_collection_id', 'user_collection_reports' => 'user_collection_id', 'user_collection_items' => 'user_collection_id'] as $table => $column) {
        expect(DB::table($table)->where($column, $c->id)->count())->toBe(0);
    }

    $drift = app(EngagementReconciler::class)->run(true)['drift'];
    expect(array_values(array_filter($drift, fn ($d) => $d['table'] === 'user_collections')))->toBe([]);
});
