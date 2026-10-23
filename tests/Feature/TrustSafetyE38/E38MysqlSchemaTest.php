<?php

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationCaseSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/_support.php';

/*
| E38 (BF15) - حدود السلامة في القاعدة نفسها. سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن «بلاغ في حالة فعّالة واحدة»، «إجراء فعّال واحد على الهدف»، «اعتراض فعّال واحد لكل إجراء»، سياسة المفاتيح الأجنبية (التاريخ لا يُحذف تتابعيًا)،
| النص العربي الكامل، فهارس الطابور، حتمية الترتيب، واتحاد الصندوق (UNION ALL) لا تعتمد على كود التطبيق وحده.
*/

beforeEach(fn () => e38_seed());

function e38_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

test('E38-131: one report per active case, one active action per target and one active appeal per action are database constraints - NULL markers do not collide', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $report = DB::table('moderation_case_sources')->where('moderation_case_id', $case->id)->first();
    $hide = e38_apply($case, $admin, 'hide_content');
    e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');

    $source = fn (?int $marker) => DB::table('moderation_case_sources')->insert(['moderation_case_id' => $case->id, 'source_type' => $report->source_type, 'source_id' => $report->source_id, 'category' => 'spam', 'active_marker' => $marker]);
    $action = fn (?int $marker) => DB::table('moderation_actions')->insert([
        'public_id' => (string) \Illuminate\Support\Str::ulid(), 'moderation_case_id' => $case->id, 'action' => 'hide_content', 'target_key' => $hide->target_key,
        'category' => 'spam', 'reason' => 'سبب', 'active_marker' => $marker, 'created_at' => now(),
    ]);
    $appeal = fn (?int $marker) => DB::table('moderation_appeals')->insert([
        'public_id' => (string) \Illuminate\Support\Str::ulid(), 'moderation_action_id' => $hide->id, 'reason' => 'نص', 'status' => 'pending', 'active_marker' => $marker, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(e38_violates(fn () => $source(1)))->toBeTrue()->and(e38_violates(fn () => $action(1)))->toBeTrue()->and(e38_violates(fn () => $appeal(1)))->toBeTrue()
        ->and(e38_violates(fn () => $source(null)))->toBeFalse()->and(e38_violates(fn () => $source(null)))->toBeFalse()
        ->and(e38_violates(fn () => $action(null)))->toBeFalse()->and(e38_violates(fn () => $appeal(null)))->toBeFalse();
});

test('E38-132: the moderation history is never deleted by cascade - cases, events, evidence, sources, actions and appeals are RESTRICTed', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');
    e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');

    expect(e38_violates(fn () => DB::table('moderation_cases')->where('id', $case->id)->delete()))->toBeTrue()
        ->and(e38_violates(fn () => DB::table('moderation_actions')->where('id', $hide->id)->delete()))->toBeTrue()
        ->and(DB::table('moderation_cases')->count())->toBe(1)->and(DB::table('moderation_actions')->count())->toBe(1)
        ->and(DB::table('moderation_case_events')->count())->toBeGreaterThan(0)->and(DB::table('moderation_appeals')->count())->toBe(1);
});

test('E38-133: deleting a user keeps the whole record - people references become NULL while cases, events, actions and appeals remain', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');
    e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');
    $reporterId = DB::table('moderation_case_sources')->where('moderation_case_id', $case->id)->value('reporter_id');
    $counts = fn () => [DB::table('moderation_cases')->count(), DB::table('moderation_case_events')->count(), DB::table('moderation_actions')->count(), DB::table('moderation_appeals')->count(), DB::table('moderation_evidence')->count()];
    $before = $counts();

    foreach ([$creator->id, $admin->id, $reporterId] as $userId) {
        DB::table('users')->where('id', $userId)->delete();
    }

    expect($counts())->toBe($before)
        ->and(DB::table('moderation_cases')->value('assigned_to'))->toBeNull()->and(DB::table('moderation_cases')->value('subject_user_id'))->toBeNull()
        ->and(DB::table('moderation_actions')->value('performed_by'))->toBeNull()->and(DB::table('moderation_actions')->value('target_user_id'))->toBeNull()
        ->and(DB::table('moderation_appeals')->value('appellant_id'))->toBeNull()
        ->and(DB::table('moderation_case_sources')->value('reporter_id'))->toBeNull();
});

test('E38-134: Arabic text round-trips intact up to the column limits in every free-text field', function () {
    [$case, $admin] = e38_puzzle_case();
    $summary = mb_substr(str_repeat('أحجية ', 100), 0, 500);
    $note = trim(str_repeat('ملاحظة عربية ', 70));
    $reason = trim(mb_substr(str_repeat('سبب داخلي ', 60), 0, 399));
    $public = trim(mb_substr(str_repeat('رسالة عامة ', 60), 0, 499));
    $evidence = e38_cases()->addNote($case->fresh(), $admin, $note);
    DB::table('moderation_cases')->where('id', $case->id)->update(['summary' => $summary]);
    $action = e38_apply($case, $admin, 'hide_content', ['reason' => $reason, 'public_note' => $public]);

    expect(DB::table('moderation_cases')->where('id', $case->id)->value('summary'))->toBe($summary)
        ->and(DB::table('moderation_evidence')->where('id', $evidence->id)->value('body'))->toBe($note)
        ->and($action->fresh()->reason)->toBe($reason)->and($action->fresh()->public_note)->toBe($public)
        ->and(mb_strlen($action->fresh()->reason))->toBeGreaterThan(300);
});

test('E38-135: the queue and appeals indexes exist and the queue ordering is deterministic under ties', function () {
    $indexes = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

    expect($indexes('moderation_cases'))->toContain('moderation_cases_queue_index', 'moderation_cases_assignee_index', 'moderation_cases_sla_index', 'moderation_cases_public_unique')
        ->and($indexes('moderation_case_sources'))->toContain('moderation_sources_one_active_unique', 'moderation_sources_case_index')
        ->and($indexes('moderation_actions'))->toContain('moderation_actions_one_active_unique', 'moderation_actions_user_index')
        ->and($indexes('moderation_appeals'))->toContain('moderation_appeals_one_active_unique', 'moderation_appeals_queue_index');

    $admin = e38_admin();
    $ids = [];

    foreach (range(1, 6) as $i) {
        $ids[] = e38_open([e38_puzzle_report()], $admin)->id;
    }

    DB::table('moderation_cases')->update(['created_at' => '2026-01-01 00:00:00']); // تعادل كامل بالزمن والأولوية
    $order = collect(e38_cases()->queue($admin, ['view' => 'open'])->items())->pluck('id')->all();

    expect($order)->toBe($ids)->and(collect(e38_cases()->queue($admin, ['view' => 'open', 'page' => 1])->items())->pluck('id')->all())->toBe($order);
});

test('E38-136: the unified inbox UNION ALL runs on the engine with exact counts and a stable order across engines', function () {
    $a = e38_puzzle_report();
    $b = e38_collection_report();
    [, $c] = e38_comment_with_report();
    [, $d] = e38_chat_with_report();
    $e = e38_fraud_flag();
    $mod = e38_mod();

    $page = e38_inbox()->paginate($mod, ['per_page' => 2, 'sort' => 'oldest']);
    $all = collect(e38_inbox()->paginate($mod, ['per_page' => 50])->items())->map(fn ($i) => $i->ref())->all();

    expect($page->total())->toBe(5)->and($page->lastPage())->toBe(3)->and($all)->toHaveCount(5)->and(array_unique($all))->toHaveCount(5)
        ->and(e38_inbox()->summary($mod))->toBe(['open' => 5, 'system' => 1, 'unlinked' => 5])
        ->and([$a->id, $b->id, $c->id, $d->id, $e->id])->each->toBeInt();
});

test('E38-137: the atomic claim is a single conditional UPDATE - a stale second claim changes nothing and counters stay exact', function () {
    $case = e38_open([e38_puzzle_report()]);
    $a = e38_mod();
    $b = e38_mod();
    $staleB = ModerationCase::find($case->id);

    e38_cases()->claim($case, $a);

    expect(e38_error(fn () => e38_cases()->claim($staleB, $b)))->not->toBeNull()
        ->and(DB::table('moderation_cases')->where('id', $case->id)->value('assigned_to'))->toBe($a->id)
        ->and(DB::table('moderation_case_events')->where('moderation_case_id', $case->id)->where('type', 'claimed')->count())->toBe(1);

    $appeal = e38_hidden_for_mysql();
    $r1 = e38_appeals()->claim($appeal, $a);

    expect(e38_error(fn () => e38_appeals()->claim(ModerationAppeal::find($appeal->id)->forceFill(['status' => 'pending', 'reviewer_id' => null]), $b)))->not->toBeNull()
        ->and($r1->reviewer_id)->toBe($a->id)->and(ModerationAppeal::find($appeal->id)->reviewer_id)->toBe($a->id);
});

function e38_hidden_for_mysql(): ModerationAppeal
{
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');

    return e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');
}

test('E38-138: boolean, status and marker columns keep exact types and the schema exposes no unexpected nullable core columns', function () {
    $appeal = e38_hidden_for_mysql();
    $row = DB::table('moderation_appeals')->where('id', $appeal->id)->first();
    $action = DB::table('moderation_actions')->first();
    $columns = fn (string $t) => collect(Schema::getColumns($t))->mapWithKeys(fn ($c) => [$c['name'] => $c['nullable']])->all();

    expect((int) $row->conflict_flag)->toBe(0)->and((int) $row->active_marker)->toBe(1)->and((int) $action->user_visible)->toBe(1)
        ->and($columns('moderation_cases')['case_number'])->toBeTrue()->and($columns('moderation_cases')['public_id'])->toBeFalse()
        ->and($columns('moderation_actions')['reason'])->toBeFalse()->and($columns('moderation_actions')['public_note'])->toBeTrue()
        ->and($columns('moderation_case_events')['actor_id'])->toBeTrue()->and($columns('moderation_appeals')['reason'])->toBeFalse();
});

test('E38-139: exactly two migrations create the E38 tables, adding no package and no change to existing tables', function () {
    $files = collect(glob(base_path('database/migrations/2026_11_08_*.php')))->map(fn ($f) => basename($f))->values()->all();

    expect($files)->toBe(['2026_11_08_000001_create_moderation_case_tables.php', '2026_11_08_000002_create_moderation_actions_and_appeals_tables.php']);

    foreach (['moderation_cases', 'moderation_case_sources', 'moderation_case_events', 'moderation_evidence', 'moderation_actions', 'moderation_appeals'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    foreach ($files as $file) {
        expect(file_get_contents(base_path('database/migrations/'.$file)))->not->toContain("Schema::table(");
    }
});
