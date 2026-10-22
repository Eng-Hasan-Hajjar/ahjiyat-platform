<?php

use App\Filament\Resources\DiscussionCommentReportResource\Pages\ListDiscussionCommentReports;
use App\Filament\Resources\DiscussionCommentResource\Pages\ListDiscussionComments;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\User;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionReader;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Social\BlockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../DiscoveryE27/_support.php';

/*
| E36 AM (93-99): الأداء. القاعدة العامة: عدد الاستعلامات **لا يتبع حجم البيانات** (مقارنة حجمين على الخدمة/الصفحة نفسها) وله سقف مطلق،
| والقوائم مرقَّمة بسقف صلب. يُعدّ كل شيء بـ e27_queries/e27_sql (يستثني device_sightings). تسخين طلب واحد قبل القياس.
*/

beforeEach(function () {
    e36_seed();
    Cache::flush();
});

/**
 * يملأ نقاشًا بـ $roots تعليقًا جذريًا ولكلٍّ $replies ردًّا. $authors: عدد الكتّاب المتمايزين (0 = كاتب لكل تعليق).
 *
 * @return array{0: \App\Services\Discussions\DiscussionSubject, 1: list<DiscussionComment>, 2: list<User>}
 */
function e36_fill(int $roots, int $replies = 0, int $authors = 0, $work = null): array
{
    $subject = e36_subject_or_fail($work ?? e36_work());
    $pool = array_map(fn () => e16User(), range(1, max(1, $authors ?: min($roots * (1 + $replies), 40))));
    $svc = app(\App\Services\Discussions\DiscussionCommentService::class);
    $i = 0;
    $made = [];

    for ($r = 0; $r < $roots; $r++) {
        $root = $svc->create($pool[$i++ % count($pool)], $subject, 'تعليق جذري رقم '.$r.' '.fake()->unique()->numerify('####'));
        $made[] = $root;

        for ($k = 0; $k < $replies; $k++) {
            $svc->create($pool[$i++ % count($pool)], $subject, 'ردّ '.$k.' على '.$r.' '.fake()->unique()->numerify('####'), $root->public_id);
        }
    }

    // تفادي حواجز المعدّل/التكرار: الخدمة تفرض الدوري على النص لا على العدد، ولا حدّ معدّل هنا (الحدود على المسارات).
    return [$subject, $made, $pool];
}

function e36_read_queries(DiscussionReader $reader, $subject, ?User $viewer): array
{
    $reader->forSubject($subject, $viewer); // تسخين
    $sql = e27_sql(fn () => $reader->forSubject($subject, $viewer));

    return $sql;
}

test('E36-093: a page of 20 roots (each with replies) uses a bounded, size-independent query count for guests and users', function () {
    [$smallSubject] = e36_fill(3, 2);
    [$bigSubject, $roots] = e36_fill(25, 4);
    $perPage = (int) config('discussions.page.roots_per_page');
    $reader = app(DiscussionReader::class);
    $viewer = e16User();

    foreach ([null, $viewer] as $v) {
        $a = count(e36_read_queries($reader, $smallSubject, $v));
        $b = count(e36_read_queries($reader, $bigSubject, $v));
        $envelope = $reader->forSubject($bigSubject, $v);

        expect($b)->toBeLessThanOrEqual($a + 1)->and($b)->toBeLessThanOrEqual(14)->and($envelope['roots']->total())->toBe(25)->and(count($envelope['roots']->items()))->toBe($perPage)->and($perPage)->toBe(20);
    }

    // الصفحة الكاملة (HTML) كذلك: حدّ مطلق وغير مرتبط بالحجم (تسخين لكل صفحة بالمشاهد نفسه قبل القياس).
    $work = e36_work();
    e36_fill(20, 3, 0, $work);
    $smallWork = e36_work();
    e36_fill(2, 1, 0, $smallWork);
    $measure = function ($target) use ($viewer) {
        $this->actingAs($viewer)->get(e36_url($target))->assertOk();

        return e27_queries(fn () => $this->actingAs($viewer)->get(e36_url($target))->assertOk());
    };
    $small = $measure($smallWork);
    $page = $measure($work);

    expect($page)->toBeLessThanOrEqual($small + 2)->and($page)->toBeLessThanOrEqual(45);
});

test('E36-094: authors are eager-loaded in one query (20 distinct authors cost the same as 2)', function () {
    [$few] = e36_fill(20, 0, 2);
    [$many] = e36_fill(20, 0, 20);
    $reader = app(DiscussionReader::class);

    $a = e36_read_queries($reader, $few, null);
    $b = e36_read_queries($reader, $many, null);
    $userQueries = fn (array $sql) => count(array_filter($sql, fn ($q) => preg_match('/from "users"/i', $q) === 1));

    expect(count($b))->toBe(count($a))->and($userQueries($b))->toBe($userQueries($a))->and($userQueries($b))->toBeLessThanOrEqual(1);

    $names = array_map(fn ($r) => $r['author']['name'] ?? null, $reader->forSubject($many, null)['roots']->getCollection()->all());
    expect(count(array_filter($names)))->toBe(20);
});

test('E36-095: the viewer like-state is one batched query (liking 10 of 20 comments costs the same as liking none)', function () {
    [$subject, $roots] = e36_fill(20, 0, 5);
    $viewer = e16User();
    $reader = app(DiscussionReader::class);
    $likeQueries = fn (array $sql) => count(array_filter($sql, fn ($q) => str_contains($q, 'discussion_comment_likes')));

    $none = e36_read_queries($reader, $subject, $viewer);

    foreach (array_slice($roots, 0, 10) as $root) {
        if ((int) $root->user_id !== (int) $viewer->id) {
            app(DiscussionLikeService::class)->like($viewer, $root->public_id);
        }
    }

    $some = e36_read_queries($reader, $subject, $viewer);
    $items = $reader->forSubject($subject, $viewer)['roots']->getCollection()->all();

    expect(count($some))->toBe(count($none))->and($likeQueries($some))->toBe($likeQueries($none))->and($likeQueries($some))->toBeLessThanOrEqual(1)
        ->and(count(array_filter($items, fn ($i) => $i['liked'])))->toBe(10)
        ->and(count(array_filter($reader->forSubject($subject, null)['roots']->getCollection()->all(), fn ($i) => $i['liked'])))->toBe(0);
});

test('E36-096: reply counts are bounded (a root with 60 replies shows the preview only; cost equals a root with 1 reply)', function () {
    $preview = (int) config('discussions.page.replies_preview');
    [$one, $r1] = e36_fill(1, 1, 3);
    [$many, $rm, $pool] = e36_fill(1, 60, 6);
    $reader = app(DiscussionReader::class);
    $viewer = e16User();

    $a = count(e36_read_queries($reader, $one, $viewer));
    $b = count(e36_read_queries($reader, $many, $viewer));
    $root = $reader->forSubject($many, $viewer)['roots']->getCollection()->first();

    expect($b)->toBeLessThanOrEqual($a + 1)->and($preview)->toBe(3)->and(count($root['replies']))->toBe($preview)->and($root['reply_count'])->toBe(60)->and($root['more_replies'])->toBe(60 - $preview);

    $html = $this->actingAs($viewer)->get(e36_url(\App\Models\CreatorPuzzle::query()->get()->last()))->getContent();
    expect(substr_count($html, 'data-more-replies'))->toBeLessThanOrEqual(1);
});

test('E36-097: block filtering costs a constant number of queries (15 blocked and 5 blocking cost the same as none) and still hides them', function () {
    [$subject, $roots, $pool] = e36_fill(20, 0, 20);
    $viewer = e16User();
    $reader = app(DiscussionReader::class);
    $none = e36_read_queries($reader, $subject, $viewer);

    foreach (array_slice($pool, 0, 15) as $u) {
        app(BlockService::class)->block($viewer, $u);
    }

    foreach (array_slice($pool, 15, 5) as $u) {
        app(BlockService::class)->block($u, $viewer);
    }

    $blocked = e36_read_queries($reader, $subject, $viewer);
    $visible = $reader->forSubject($subject, $viewer)['roots'];
    $hiddenAuthors = array_slice($pool, 0, 20);

    expect(count($blocked))->toBeLessThanOrEqual(count($none) + 1)->and($visible->total())->toBe(0);

    // كاتب واحد غير محظور يبقى ظاهرًا: الترقيم يُحسب بعد الترشيح لا قبله.
    $free = e16User();
    e36_say($free, $subject, 'تعليق غير محظور');
    expect($reader->forSubject($subject, $viewer)['roots']->total())->toBe(1)->and(count($hiddenAuthors))->toBe(20);
});

test('E36-098: replies are paginated with a hard per-page cap (45 replies = 20/20/5), constant queries, no client-controlled page size', function () {
    [$subject, $roots] = e36_fill(1, 45, 8);
    $root = $roots[0];
    $thread = e36_thread($subject);
    $reader = app(DiscussionReader::class);
    $per = (int) config('discussions.page.replies_per_page');
    $viewer = e16User();

    $sizes = [];
    $counts = [];

    foreach ([1, 2, 3] as $page) {
        $reader->repliesPage($root->fresh(), $thread, $subject, $viewer, $page);
        $counts[$page] = e27_queries(function () use ($reader, $root, $thread, $subject, $viewer, $page, &$sizes) {
            $data = $reader->repliesPage($root->fresh(), $thread, $subject, $viewer, $page);
            $sizes[$page] = count($data['replies']->items());
            expect($data['replies']->total())->toBe(45);
        });
    }

    expect($per)->toBe(20)->and($sizes)->toBe([1 => 20, 2 => 20, 3 => 5])->and(max($counts) - min($counts))->toBeLessThanOrEqual(1);

    // صفحة بعيدة جدًا أو حجم صفحة من العميل: لا يخرج عن السقف.
    $far = $reader->repliesPage($root->fresh(), $thread, $subject, $viewer, 9999);
    expect(count($far['replies']->items()))->toBeLessThanOrEqual($per);
    $url = route('discussions.replies', ['type' => 'community_puzzle', 'subject' => $subject->publicId, 'root' => $root->public_id]);
    $html = $this->actingAs($viewer)->get($url.'?per_page=1000&limit=1000&page=1')->assertOk()->getContent();
    expect(substr_count($html, 'data-comment-id') + substr_count($html, 'id="c-'))->toBeLessThanOrEqual($per + 2);
});

test('E36-099: the moderation queues are paginated (20 per page, no unbounded page size) with no per-row N+1', function () {
    $mod = e36_mod();
    [$subject, $roots, $pool] = e36_fill(30, 0, 30);
    $reporters = array_map(fn () => e16User(), range(1, 3));
    $reports = [];

    foreach ($roots as $i => $root) {
        $reports[] = app(DiscussionReportService::class)->report($reporters[$i % 3], $root->public_id, 'spam');
    }

    $page = (int) config('discussions.moderation.page_size');
    $newest = collect($reports)->sortByDesc(fn ($r) => $r->id)->values();
    $reportTable = Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class);
    $reportTable->assertCanSeeTableRecords($newest->take($page)->all())->assertCanNotSeeTableRecords($newest->slice($page)->all());

    // الصفحة الثانية تعرض الباقي (10).
    $reportTable->call('gotoPage', 2)->assertCanSeeTableRecords($newest->slice($page)->all());

    // حجم الصفحة من العميل لا يفتح القائمة بلا سقف.
    $big = Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class)->set('tableRecordsPerPage', 100000);
    $visible = count(array_filter($newest->all(), fn ($r) => str_contains($big->html(), 'wire:key') && true));
    expect($visible)->toBeGreaterThan(0);
    expect((int) $big->instance()->getTableRecords()->count())->toBeLessThanOrEqual(50);

    // لا N+1 لكل صف: 5 مقابل 25 بلاغًا بالتكلفة نفسها تقريبًا.
    DiscussionCommentReport::query()->delete();
    foreach (array_slice($roots, 0, 5) as $i => $root) {
        app(DiscussionReportService::class)->report($reporters[$i % 3], $root->public_id, 'spam');
    }

    Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class); // تسخين
    $few = e27_queries(fn () => Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class));

    foreach (array_slice($roots, 5, 20) as $i => $root) {
        app(DiscussionReportService::class)->report($reporters[$i % 3], $root->public_id, 'harassment');
    }

    $many = e27_queries(fn () => Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class));
    expect($many)->toBeLessThanOrEqual($few + 3);

    // قائمة التعليقات الإدارية كذلك.
    Livewire::actingAs($mod)->test(ListDiscussionComments::class);
    $comments = e27_queries(fn () => Livewire::actingAs($mod)->test(ListDiscussionComments::class));
    expect($comments)->toBeLessThanOrEqual(60);
});
