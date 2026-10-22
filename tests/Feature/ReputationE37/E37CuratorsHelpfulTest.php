<?php

use App\Models\DiscussionComment;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionService;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E37 AV5 (33-40) المنسّقون + AV6 (41-47) المساهمون المفيدون.
| المنسّق: حافظون **فريدون** على مجموعات **مُدرَجة علنًا** لا تكرار حفظ. المساهم: معجِبون **فريدون** على تعليقات حيّة؛ كثرة التعليقات وحدها لا تكفي، ولا لوحة لحجم التعليقات.
*/

beforeEach(fn () => e37_seed());

/* ===================== AV5. CURATORS ===================== */

test('E37-033: saves on public listed collections make a curator eligible and scored (distinct savers, not raw saves)', function () {
    [$owner, , $savers] = e37_curator(6, 2);
    e37_refresh($owner);
    $s = e37_snap($owner);

    expect((bool) $s->curator_eligible)->toBeTrue()->and((int) $s->curator_collections_count)->toBe(2)->and((int) $s->curator_savers_count)->toBe(6)
        ->and((int) $s->curator_saves_count)->toBe(12)->and((int) $s->curator_items_count)->toBe(6)->and((int) $s->curator_score)->toBeGreaterThan(0)
        ->and(e37_names(e37_rankings()->curators()))->toBe([$owner->name]);
});

test('E37-034: saves on a collection that stopped being public no longer count (private / unlisted)', function () {
    [$owner, $collections] = e37_curator(6, 3);
    e37_refresh($owner);
    expect((int) e37_snap($owner)->curator_collections_count)->toBe(3);

    DB::table('user_collections')->where('id', $collections[0]->id)->update(['visibility' => UserCollection::VISIBILITY_PRIVATE]);
    DB::table('user_collections')->where('id', $collections[1]->id)->update(['visibility' => UserCollection::VISIBILITY_UNLISTED]);
    e37_refresh($owner);
    $s = e37_snap($owner);

    expect((int) $s->curator_collections_count)->toBe(1)->and((int) $s->curator_saves_count)->toBe(6)->and((bool) $s->curator_eligible)->toBeFalse() // < مجموعتان مُدرَجتان
        ->and(e37_names(e37_rankings()->curators()))->toBe([]);
});

test('E37-035: a moderation-hidden collection contributes nothing, and returns when restored', function () {
    [$owner, $collections] = e37_curator(6, 2);
    e37_refresh($owner);
    expect((bool) e37_snap($owner)->curator_eligible)->toBeTrue();

    e35_hide_collection($collections[0]);
    e37_refresh($owner);

    expect((int) e37_snap($owner)->curator_collections_count)->toBe(1)->and((bool) e37_snap($owner)->curator_eligible)->toBeFalse()
        ->and(e37_names(e37_rankings()->curators()))->toBe([]);

    DB::table('user_collections')->where('id', $collections[0]->id)->update(['is_moderation_hidden' => false, 'moderation_hidden_at' => null]);
    e37_refresh($owner);

    expect((bool) e37_snap($owner)->curator_eligible)->toBeTrue();
});

test('E37-036: saves are unique per saver - one person saving every collection is one saver, not many', function () {
    [$owner, $collections, $savers] = e37_curator(1, 2);
    e37_refresh($owner);
    $s = e37_snap($owner);

    expect((int) $s->curator_saves_count)->toBe(2)->and((int) $s->curator_savers_count)->toBe(1)->and((bool) $s->curator_eligible)->toBeFalse(); // < 5 حافظين فريدين

    // المالك لا يرفع درجته بحفظ مجموعته، والحافظ المجمَّد لا يُحتسب.
    DB::table('user_collection_saves')->insert(['user_id' => $owner->id, 'user_collection_id' => $collections[0]->id, 'created_at' => now()]);
    $savers[0]->forceFill(['is_frozen' => true])->save();
    e37_refresh($owner);

    expect((int) e37_snap($owner)->curator_savers_count)->toBe(0)->and((int) e37_snap($owner)->curator_saves_count)->toBe(0);
});

test('E37-037: quantity is capped - 200 savers and 50000 savers score the same; breadth and depth are capped too', function () {
    $base = ['verified' => true, 'profile_public' => true, 'collections' => 5, 'items' => 60, 'fresh' => 5, 'saves' => 200];
    $at = e37_scores($base + ['savers' => 200]);
    $over = e37_scores(array_replace($base, ['collections' => 50, 'items' => 6000, 'fresh' => 50, 'saves' => 90000]) + ['savers' => 50000]);

    expect($over['curator_score'])->toBe($at['curator_score'])->and($at['curator_score'])->toBe(10000)
        ->and(e37_scores($base + ['savers' => 20])['curator_score'])->toBeLessThan($at['curator_score']);
});

test('E37-038: the curators ranking orders by score then supporting savers then lowest id, deterministically', function () {
    [$top] = e37_curator(30, 3);
    [$mid] = e37_curator(8, 2);
    [$low] = e37_curator(5, 2);
    e37_refresh($low, $top, $mid);

    $order = e37_names(e37_rankings()->curators());

    expect($order)->toBe([$top->name, $mid->name, $low->name])->and(e37_names(e37_rankings()->curators()))->toBe($order);

    // تعادل كامل ← أصغر id أولًا.
    [$a] = e37_curator(6, 2);
    [$b] = e37_curator(6, 2);
    e37_refresh($b, $a);
    $tie = array_values(array_filter(e37_names(e37_rankings()->curators()), fn ($n) => in_array($n, [$a->name, $b->name], true)));

    expect($tie)->toBe([$a->name, $b->name]);
});

test('E37-039: curator profile privacy - a non-public profile owner is never ranked and their collections are not counted', function () {
    [$owner] = e37_curator(6, 2);
    e37_refresh($owner);
    expect(e37_names(e37_rankings()->curators()))->toBe([$owner->name]);

    $owner->forceFill(['profile_visibility' => User::VISIBILITY_PRIVATE])->save();

    // الفحص الحيّ يُسقطه فورًا حتى قبل إعادة الحساب؛ وإعادة الحساب تصفّر المنسّق (المجموعات لا تُدرَج علنًا بلا ملف عام).
    expect(e37_names(e37_rankings()->curators()))->toBe([]);
    e37_refresh($owner);

    expect((int) e37_snap($owner)->curator_collections_count)->toBe(0)->and((bool) e37_snap($owner)->curator_eligible)->toBeFalse();
});

test('E37-040: a cached curators pool never resurrects a creator-turned-frozen or blocked curator (live recheck + viewer blocks)', function () {
    config(['reputation.rankings.cache_seconds' => 600]);
    Cache::flush();
    [$a] = e37_curator(6, 2);
    [$b] = e37_curator(6, 2);
    e37_refresh($a, $b);

    expect(e37_names(e37_rankings()->curators()))->toHaveCount(2);

    $a->forceFill(['is_frozen' => true])->save();
    expect(e37_names(e37_rankings()->curators()))->toBe([$b->name]);

    $viewer = e16User();
    app(\App\Services\Social\BlockService::class)->block($viewer, $b);

    expect(e37_names(e37_rankings()->curators($viewer)))->toBe([])->and(e37_names(e37_rankings()->curators()))->toBe([$b->name]);
});

/* ===================== AV6. HELPFULNESS ===================== */

test('E37-041: likes on a visible comment contribute - distinct likers make a helpful contributor eligible and ranked', function () {
    [$author, $comments] = e37_helper(5, 10);
    e37_refresh($author);
    $s = e37_snap($author);

    expect((bool) $s->helpful_eligible)->toBeTrue()->and((int) $s->helpful_comments_count)->toBe(5)->and((int) $s->helpful_likers_count)->toBe(10)->and((int) $s->helpful_liked_comments)->toBe(1)
        ->and((int) $s->helpful_likes_count)->toBe(10)->and((int) $s->helpfulness_score)->toBeGreaterThan(0)
        ->and(e37_names(e37_rankings()->helpful()))->toBe([$author->name]);

    // الإعجاب الرسمي بالخدمة يُحتسب كذلك.
    $extra = e16User();
    app(DiscussionLikeService::class)->like($extra, $comments[1]->public_id);
    e37_refresh($author);

    expect((int) e37_snap($author)->helpful_likers_count)->toBe(11)->and((int) e37_snap($author)->helpful_liked_comments)->toBe(2);
});

test('E37-042: deleted and moderation-hidden comments contribute nothing (their likes vanish from the signal)', function () {
    [$author, $comments] = e37_helper(5, 10);
    e37_refresh($author);
    expect((bool) e37_snap($author)->helpful_eligible)->toBeTrue();

    e36_hide($comments[0]); // التعليق المُعجَب به صار مخفيًا ← يسقط أثره ويقلّ عدد الحيّة
    e37_refresh($author);
    $s = e37_snap($author);

    expect((int) $s->helpful_likers_count)->toBe(0)->and((int) $s->helpful_comments_count)->toBe(4)->and((bool) $s->helpful_eligible)->toBeFalse()->and(e37_names(e37_rankings()->helpful()))->toBe([]);

    // حذف الكاتب لتعليقه (soft delete) كذلك.
    [$author2, $comments2] = e37_helper(5, 10);
    app(DiscussionCommentService::class)->delete($author2, $comments2[0]->public_id);
    e37_refresh($author2);

    expect((int) e37_snap($author2)->helpful_likers_count)->toBe(0)->and((int) e37_snap($author2)->helpful_comments_count)->toBe(4);
});

test('E37-043: a self-like has no effect on reputation', function () {
    [$author, $comments, $fans] = e37_helper(5, 9);
    DB::table('discussion_comment_likes')->insert(['user_id' => $author->id, 'discussion_comment_id' => $comments[0]->id, 'created_at' => now()]);
    e37_refresh($author);
    $s = e37_snap($author);

    expect((int) $s->helpful_likers_count)->toBe(9)->and((bool) $s->helpful_eligible)->toBeFalse() // 9 < 10 (الذاتي لا يكمّل العيّنة)
        ->and((int) $s->helpful_likes_count)->toBe(9);
});

test('E37-044: comment quantity alone is not enough - hundreds of comments with no likes score zero and are not eligible', function () {
    expect(e37_scores(['comments' => 800, 'likers' => 0, 'liked_comments' => 0, 'likes' => 0])['helpful_eligible'])->toBeFalse()
        ->and(e37_scores(['comments' => 800, 'likers' => 0, 'liked_comments' => 0, 'likes' => 0])['helpfulness_score'])->toBe(0);

    $author = e16User();

    for ($i = 0; $i < 6; $i++) {
        e36_say($author, e36_subject_or_fail(e36_work()));
    }

    e37_refresh($author);

    expect((int) e37_snap($author)->helpful_comments_count)->toBe(6)->and((bool) e37_snap($author)->helpful_eligible)->toBeFalse()->and((int) e37_snap($author)->helpfulness_score)->toBe(0)
        ->and(e37_names(e37_rankings()->helpful()))->toBe([]);

    // وزيادة الكمية لا ترفع الدرجة مع ثبات الإعجابات الفريدة.
    $few = e37_scores(['comments' => 5, 'likers' => 12, 'liked_comments' => 3, 'likes' => 12]);
    $many = e37_scores(['comments' => 5000, 'likers' => 12, 'liked_comments' => 3, 'likes' => 12]);

    expect($many['helpfulness_score'])->toBe($few['helpfulness_score']);
});

test('E37-045: the minimum sample is enforced - 4 comments or 9 likers are not eligible; 5 comments and 10 likers are', function () {
    $ok = ['comments' => 5, 'likers' => 10, 'liked_comments' => 2, 'likes' => 10];

    expect(e37_scores($ok)['helpful_eligible'])->toBeTrue()
        ->and(e37_scores(array_replace($ok, ['comments' => 4]))['helpful_eligible'])->toBeFalse()
        ->and(e37_scores(array_replace($ok, ['likers' => 9]))['helpful_eligible'])->toBeFalse()
        ->and(e37_scores($ok + ['profile_public' => true])['helpful_eligible'])->toBeTrue();

    // ملف غير عام أو حساب غير موثَّق أو مجمَّد ← غير مؤهَّل.
    expect(e37_scores(array_replace($ok, ['profile_public' => false]))['helpful_eligible'])->toBeFalse()
        ->and(e37_scores(array_replace($ok, ['verified' => false]))['helpful_eligible'])->toBeFalse()
        ->and(e37_scores(array_replace($ok, ['frozen' => true]))['helpful_eligible'])->toBeFalse();
});

test('E37-046: helpfulness pays nothing - no XP, currency, gems, wallet or competition change from comments, likes or recalculation', function () {
    [$author, $comments, $fans] = e37_helper(5, 10);
    $before = e37_world($author, ...$fans);

    app(DiscussionLikeService::class)->like(e16User(), $comments[2]->public_id);
    e37_refresh($author);
    e37_refresh($author);
    $after = e37_world($author, ...$fans);

    // الإعجاب نفسه قد يكتب عدّاد التعليق فقط؛ لا دفاتر اقتصاد.
    expect($after['counts'])->toEqual($before['counts'])->and($after['wallets'])->toBe($before['wallets'])->and($after['xp'])->toBe($before['xp'])->and($after['per_user'])->toEqual($before['per_user']);
});

test('E37-047: the helpful board is ordered by distinct likers/liked comments, never by commenter volume - and no commenter-volume board exists', function () {
    [$quiet] = e37_helper(5, 25);   // 5 تعليقات، 25 معجبًا
    [$noisy] = e37_helper(5, 10);
    $loud = e16User();

    for ($i = 0; $i < 12; $i++) {
        e36_say($loud, e36_subject_or_fail(e36_work()));
    }

    e37_refresh($quiet, $noisy, $loud);

    expect(e37_names(e37_rankings()->helpful()))->toBe([$quiet->name, $noisy->name]);

    $html = $this->get(route('community.rankings'))->assertOk()->getContent();

    expect($html)->not->toContain('الأكثر تعليقًا')->and($html)->not->toContain('أكثر المعلقين');
    expect(array_key_exists('comments', (e37_rankings()->helpful()[0] ?? [])))->toBeFalse();
});
