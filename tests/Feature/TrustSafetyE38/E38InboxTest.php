<?php

use App\Models\ModerationCase;
use App\Services\Creators\CommunityReportService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E38 (BF1) - الصندوق الموحَّد: قراءة فقط فوق البلاغات القائمة + إشارات النظام، مرشِّحات مغلقة، ترقيم صفحات، تجميع الدُفعات، خصوصية المبلِّغ.
*/

beforeEach(fn () => e38_seed());

function e38_inbox_world(): array
{
    $puzzle = e38_puzzle_report(null, null, 'spam');
    $collection = e38_collection_report(null, null, 'harassment');
    [, $comment] = e38_comment_with_report(null, null, 'hate_or_abuse');
    [, $chat] = e38_chat_with_report(null, null, 'scam');
    $flag = e38_fraud_flag(null, 'multiple_accounts', 'high');

    return compact('puzzle', 'collection', 'comment', 'chat', 'flag');
}

test('E38-1: the unified inbox lists every source type (puzzle, collection, comment, chat, fraud signal) through one query', function () {
    $w = e38_inbox_world();
    $page = e38_inbox()->paginate(e38_mod(), []);
    $types = collect($page->items())->pluck('sourceType')->sort()->values()->all();

    expect($page->total())->toBe(5)
        ->and($types)->toBe(['chat_message_report', 'community_puzzle_report', 'discussion_comment_report', 'fraud_flag', 'user_collection_report']);
});

test('E38-2: a fraud flag is labelled as a system signal and is never presented as a user report or a confirmed violation', function () {
    e38_inbox_world();
    $items = collect(e38_inbox()->paginate(e38_mod(), [])->items());
    $signal = $items->firstWhere('sourceType', 'fraud_flag');
    $reports = $items->where('sourceType', '!=', 'fraud_flag');

    expect($signal->systemSignal)->toBeTrue()
        ->and($signal->sourceLabel)->toContain('إشارة نظام')
        ->and($signal->categoryLabel)->not->toBe('')
        ->and($reports->every(fn ($i) => $i->systemSignal === false))->toBeTrue();
});

test('E38-3: the inbox is a read model - building it creates no case, source, event or copied report row', function () {
    e38_inbox_world();
    e38_inbox()->paginate(e38_mod(), []);
    e38_inbox()->paginateGrouped(e38_mod(), ['group' => 'subject']);
    e38_inbox()->summary(e38_mod());

    foreach (['moderation_cases', 'moderation_case_sources', 'moderation_case_events', 'moderation_evidence', 'moderation_actions', 'moderation_appeals'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

test('E38-4: the status filter separates open from closed reports using the domain status (dismissed/actioned) without rewriting it', function () {
    $open = e38_puzzle_report(null, null, 'spam');
    $closed = e38_puzzle_report(null, null, 'copyright');
    app(CommunityReportService::class)->dismiss($closed, e38_admin(), 'لا مخالفة');

    $mod = e38_mod();

    expect(e38_inbox()->paginate($mod, ['status' => 'open'])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['status' => 'closed'])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['status' => 'all'])->total())->toBe(2)
        ->and($closed->fresh()->status)->toBe('dismissed')
        ->and(collect(e38_inbox()->paginate($mod, ['status' => 'closed'])->items())->first()->sourceId)->toBe($closed->id);
});

test('E38-5: the source filter is a closed allow-list - unknown types are ignored, never used as table names', function () {
    e38_inbox_world();
    $mod = e38_mod();

    expect(e38_inbox()->paginate($mod, ['source' => ['fraud_flag']])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['source' => ['user_collection_report', 'discussion_comment_report']])->total())->toBe(2)
        ->and(e38_inbox()->paginate($mod, ['source' => ['users; DROP TABLE users', 'App\\Models\\User']])->total())->toBe(5)
        ->and(DB::table('users')->count())->toBeGreaterThan(0);
});

test('E38-6: the category filter maps policy categories to the raw reasons without altering stored reasons', function () {
    $w = e38_inbox_world();
    $mod = e38_mod();
    $harassment = e38_inbox()->paginate($mod, ['category' => ['harassment']]);
    $spam = e38_inbox()->paginate($mod, ['category' => ['spam']]);

    expect($harassment->total())->toBe(1)
        ->and(collect($harassment->items())->first()->sourceType)->toBe('user_collection_report')
        ->and($spam->total())->toBe(1)
        ->and($w['collection']->fresh()->reason)->toBe('harassment')
        ->and($w['puzzle']->fresh()->reason)->toBe('spam');
});

test('E38-7: has-case, assigned and priority filters follow the linked case and the suggested policy priority of the raw reason', function () {
    $linked = e38_puzzle_report(null, null, 'copyright');
    $free = e38_collection_report(null, null, 'spam');
    [, $high] = e38_comment_with_report(null, null, 'hate_or_abuse');
    $mod = e38_mod();
    $case = e38_open([$linked], $mod, null, 'urgent');
    e38_cases()->claim($case, $mod);

    expect(e38_inbox()->paginate($mod, ['has_case' => 'yes'])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['has_case' => 'no'])->total())->toBe(2)
        ->and(e38_inbox()->paginate($mod, ['assigned' => 'mine'])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['assigned' => 'unassigned'])->total())->toBe(0)
        ->and(e38_inbox()->paginate($mod, ['priority' => 'high'])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['priority' => 'normal'])->total())->toBe(2)
        ->and(e38_inbox()->paginate($mod, ['priority' => 'urgent'])->total())->toBe(0);

    $item = collect(e38_inbox()->paginate($mod, ['has_case' => 'yes'])->items())->first();

    expect($item->caseNumber)->toBe($case->case_number)->and($item->casePublicId)->toBe($case->public_id)->and($item->caseAssigneeId)->toBe($mod->id)
        ->and($free->id)->toBeInt()->and($high->id)->toBeInt();
});

test('E38-8: date filters bound the reported-at range and invalid dates are ignored safely', function () {
    $old = e38_puzzle_report(null, null, 'spam');
    DB::table('community_puzzle_reports')->where('id', $old->id)->update(['created_at' => now()->subDays(40)]);
    e38_collection_report(null, null, 'spam');
    $mod = e38_mod();

    expect(e38_inbox()->paginate($mod, ['from' => now()->subDays(7)->toDateString()])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['to' => now()->subDays(30)->toDateString()])->total())->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['from' => 'not-a-date', 'to' => "2026-01-01' OR 1=1"])->total())->toBe(2);
});

test('E38-9: pagination is bounded - per-page is clamped and sorting is deterministic oldest/newest', function () {
    foreach (range(1, 7) as $i) {
        e38_puzzle_report(null, null, 'spam');
    }

    $mod = e38_mod();
    $page = e38_inbox()->paginate($mod, ['per_page' => 3]);
    $huge = e38_inbox()->paginate($mod, ['per_page' => 100000]);
    $oldest = collect(e38_inbox()->paginate($mod, ['sort' => 'oldest'])->items())->pluck('sourceId')->all();
    $newest = collect(e38_inbox()->paginate($mod, ['sort' => 'newest'])->items())->pluck('sourceId')->all();

    expect($page->count())->toBe(3)->and($page->total())->toBe(7)->and($page->lastPage())->toBe(3)
        ->and($huge->perPage())->toBe((int) config('trust_safety.inbox.max_per_page'))
        ->and($oldest)->toBe(array_reverse($newest));
});

test('E38-10: grouping by subject shows report and unique-reporter counts as information only and never raises priority', function () {
    $creator = e28_creator();
    $work = e36_work($creator);
    $reports = [];

    foreach (['spam', 'copyright', 'misleading'] as $reason) {
        $reports[] = e38_puzzle_report(null, $work, $reason);
    }

    $grouped = e38_inbox()->paginateGrouped(e38_mod(), ['group' => 'subject']);
    $row = $grouped->items()[0];
    $case = e38_open($reports);

    expect($grouped->total())->toBe(1)
        ->and($row['report_count'])->toBe(3)->and($row['unique_reporters_count'])->toBe(3)->and($row['open_count'])->toBe(3)
        ->and($case->priority)->toBe('normal');
});

test('E38-11: filters are rejected silently when malformed - arrays, SQL fragments and class names change nothing', function () {
    e38_inbox_world();
    $mod = e38_mod();
    $f = e38_inbox()->normalize([
        'status' => ['x'], 'source' => 'fraud_flag', 'category' => ['harassment', 'nope'], 'priority' => "high' OR '1'='1", 'assigned' => '1; DROP',
        'has_case' => 'maybe', 'subject_type' => 'App\\Models\\User', 'sort' => 'id; --', 'per_page' => -5, 'page' => -3,
    ]);

    expect($f['status'])->toBe('open')->and($f['source'])->toBe(['fraud_flag'])->and($f['category'])->toBe(['harassment'])->and($f['priority'])->toBeNull()
        ->and($f['assigned'])->toBeNull()->and($f['has_case'])->toBeNull()->and($f['subject_type'])->toBeNull()
        ->and($f['sort'])->toBe('oldest')->and($f['per_page'])->toBe(1)->and($f['page'])->toBe(1)
        ->and(e38_inbox()->paginate($mod, ['priority' => "high' OR '1'='1"])->total())->toBe(5);
});

test('E38-12: hydration is batched - the query count does not grow with the number of rows on the page', function () {
    $measure = function (int $rows): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = e38_inbox()->paginate(e38_mod(), ['per_page' => 50]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($page->count())->toBe($rows);

        return $count;
    };

    foreach (range(1, 2) as $i) {
        e38_puzzle_report();
        e38_collection_report();
    }

    $small = $measure(4);

    foreach (range(1, 8) as $i) {
        e38_puzzle_report();
        e38_collection_report();
    }

    $large = $measure(20);

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(14);
});

test('E38-13: a missing subject resolves to a deleted marker instead of failing (batched, one query per type)', function () {
    $resolver = app(\App\Services\TrustSafety\SubjectResolver::class);
    $real = e38_collection_report(null, null, 'spam');
    $resolved = $resolver->resolve([['user_collection', 987654], ['community_puzzle', 987655], ['discussion_comment', 987656], ['chat_message', 987657], ['user', 987658], ['user_collection', $real->user_collection_id]]);

    expect($resolved['user_collection:987654']['exists'])->toBeFalse()
        ->and($resolved['community_puzzle:987655']['exists'])->toBeFalse()->and($resolved['discussion_comment:987656']['exists'])->toBeFalse()
        ->and($resolved['chat_message:987657']['exists'])->toBeFalse()->and($resolved['user:987658']['exists'])->toBeFalse()
        ->and($resolved['user_collection:987654']['label'])->not->toBe('')
        ->and($resolved['user_collection:'.$real->user_collection_id]['exists'])->toBeTrue();
});

test('E38-14: the inbox item carries no reporter identity and the report text is excerpted plain text', function () {
    e38_puzzle_report(null, null, 'spam', '<script>alert(1)</script> تفاصيل');
    $item = e38_inbox()->paginate(e38_mod(), [])->items()[0];
    $props = array_keys(get_object_vars($item));

    expect($props)->not->toContain('reporterId')->and($props)->not->toContain('reporterName')
        ->and((string) $item->excerpt)->not->toContain('<script>');
});

test('E38-15: the inbox requires the view permission - ordinary users, guests and support staff without it are refused', function () {
    e38_inbox_world();

    expect(fn () => e38_inbox()->paginate(e16User(), []))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_inbox()->summary(e16User()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->get('/admin/trust-safety-inbox')->assertRedirect();
    $this->actingAs(e16User())->get('/admin/trust-safety-inbox')->assertForbidden();
    $this->actingAs(e38_mod())->get('/admin/trust-safety-inbox')->assertOk();
});

test('E38-16: the summary counts open reports, system signals and unlinked reports with one aggregate query', function () {
    $linked = e38_puzzle_report();
    e38_collection_report();
    e38_fraud_flag();
    e38_open([$linked]);
    $mod = e38_mod();
    $mod->can('trust-safety.view');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = e38_inbox()->summary($mod);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summary)->toBe(['open' => 3, 'system' => 1, 'unlinked' => 2])->and($queries)->toBeLessThanOrEqual(3);
});

test('E38-17: report counts never change the inbox or case ordering by popularity - priority stays a policy suggestion', function () {
    $popular = e36_work(e28_creator());
    foreach (range(1, 4) as $i) {
        e38_puzzle_report(null, $popular, 'spam');
    }
    $quiet = e38_puzzle_report(null, null, 'spam');

    $popularCase = e38_open(['community_puzzle_report:'.DB::table('community_puzzle_reports')->where('creator_puzzle_id', $popular->id)->min('id')], e38_mod());
    $quietCase = e38_open([$quiet], e38_mod());

    expect($popularCase->priority)->toBe($quietCase->priority)->and(ModerationCase::count())->toBe(2);
});
