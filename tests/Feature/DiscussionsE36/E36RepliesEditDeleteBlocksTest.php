<?php

use App\Models\DiscussionComment;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionReader;
use App\Services\Discussions\DiscussionReconciler;
use App\Services\Social\BlockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E36 AK3 (22-28) الردود + AK4 (29-34) التعديل + AK5 (35-40) الحذف + AK6 (41-45) الحظر.
| مستوى ردود واحد (الرد على رد يُلحَق بجذره). التعديل 15 دقيقة لصاحب التعليق الحيّ. الحذف ناعم بلحد. الحظر E16 بكل اتجاه وبدفعة واحدة.
*/

beforeEach(fn () => e36_seed());

/** @return array{0: \App\Models\CreatorPuzzle, 1: \App\Services\Discussions\DiscussionSubject} */
function e36_pair(?\App\Models\User $creator = null): array
{
    $work = e36_work($creator);

    return [$work, e36_subject_or_fail($work)];
}

/* ===================== AK3. REPLIES ===================== */

test('E36-022: a reply works (route), is stored under its root in the same thread, counted, and its permalink points at it', function () {
    [$work, $subject] = e36_pair();
    [$author, $replier] = e36_users(2);
    $root = e36_say($author, $subject);

    $this->actingAs($replier)->post(route('discussions.replies.store', $root->public_id), ['body' => 'ردّ على التعليق'])->assertRedirect();
    $reply = DiscussionComment::query()->whereNotNull('parent_id')->firstOrFail();

    expect((int) $reply->parent_id)->toBe($root->id)->and((int) $reply->discussion_thread_id)->toBe((int) $root->discussion_thread_id)->and((int) $reply->user_id)->toBe($replier->id)
        ->and((int) e36_thread($subject)->comments_count)->toBe(2);
    $this->get(route('discussions.permalink', $reply->public_id))->assertRedirect(e36_url($work).'#c-'.$reply->public_id);
    $this->get(e36_url($work))->assertOk()->assertSee('ردّ على التعليق');
});

test('E36-023: the parent must belong to the same thread (service rejects unknown, malformed and foreign parents)', function () {
    [, $subjectA] = e36_pair();
    [, $subjectB] = e36_pair();
    [$u1, $u2] = e36_users(2);
    $rootB = e36_say($u1, $subjectB);
    $svc = app(DiscussionCommentService::class);

    expect(e36_error(fn () => $svc->create($u2, $subjectA, e36_text(), $rootB->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($u2, $subjectA, e36_text(), (string) Str::ulid())))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($u2, $subjectB, e36_text(), 'not-a-ulid')))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($u2, $subjectB, e36_text(), (string) $rootB->id)))->not->toBeNull()
        ->and(DiscussionComment::query()->count())->toBe(1);
});

test('E36-024: a reply can never land in a foreign thread (route follows the parent; the database composite key rejects forged rows)', function () {
    [, $subjectA] = e36_pair();
    [$workB, $subjectB] = e36_pair();
    [$u1, $u2] = e36_users(2);
    $rootA = e36_say($u1, $subjectA);
    $rootB = e36_say($u1, $subjectB);

    $this->actingAs($u2)->post(route('discussions.replies.store', $rootB->public_id), ['body' => 'ردّ يتبع أباه'])->assertRedirect();
    $reply = DiscussionComment::query()->whereNotNull('parent_id')->firstOrFail();
    expect((int) $reply->discussion_thread_id)->toBe((int) $rootB->discussion_thread_id)->and((int) $reply->discussion_thread_id)->not->toBe((int) $rootA->discussion_thread_id);

    $forged = ['public_id' => (string) Str::ulid(), 'discussion_thread_id' => $rootA->discussion_thread_id, 'parent_id' => $rootB->id, 'user_id' => $u2->id, 'body' => 'مزوّر', 'status' => 'visible', 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::table('discussion_comments')->insert($forged))->toThrow(QueryException::class);
});

test('E36-025: depth never exceeds one (a reply to a reply is attached to the root; forged depth-2 rows are detected by reconcile)', function () {
    [, $subject] = e36_pair();
    [$a, $b, $c] = e36_users(3);
    $root = e36_say($a, $subject);
    $reply = e36_say($b, $subject, null, $root);
    $deeper = e36_say($c, $subject, null, $reply);

    expect((int) $reply->parent_id)->toBe($root->id)->and((int) $deeper->parent_id)->toBe($root->id)
        ->and(DiscussionComment::query()->whereIn('parent_id', DiscussionComment::query()->whereNotNull('parent_id')->pluck('id'))->count())->toBe(0)
        ->and(app(DiscussionReconciler::class)->anomalies())->toBe([]);

    DB::table('discussion_comments')->insert(['public_id' => (string) Str::ulid(), 'discussion_thread_id' => $root->discussion_thread_id, 'parent_id' => $reply->id, 'user_id' => $c->id, 'body' => 'عمق ثانٍ مزوّر', 'status' => 'visible', 'created_at' => now(), 'updated_at' => now()]);
    $types = collect(app(DiscussionReconciler::class)->anomalies())->pluck('type')->all();
    expect($types)->toContain('deep_reply');
});

test('E36-026: replying to a moderation-hidden comment (or into a branch whose root is hidden) is rejected', function () {
    [, $subject] = e36_pair();
    [$a, $b, $c] = e36_users(3);
    $root = e36_say($a, $subject);
    $reply = e36_say($b, $subject, null, $root);
    $other = e36_say($a, $subject);
    $otherReply = e36_say($b, $subject, null, $other);

    e36_hide($reply);
    e36_hide($other);
    $svc = app(DiscussionCommentService::class);

    expect(e36_error(fn () => $svc->create($c, $subject, e36_text(), $reply->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($c, $subject, e36_text(), $other->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($c, $subject, e36_text(), $otherReply->public_id)))->not->toBeNull()
        ->and(DiscussionComment::query()->count())->toBe(4);
});

test('E36-027: replying to a deleted root is rejected (even when the tombstone keeps older replies)', function () {
    [, $subject] = e36_pair();
    [$a, $b, $c] = e36_users(3);
    $lonely = e36_say($a, $subject);
    $withReplies = e36_say($a, $subject);
    $old = e36_say($b, $subject, null, $withReplies);

    app(DiscussionCommentService::class)->delete($a, $lonely->public_id);
    app(DiscussionCommentService::class)->delete($a, $withReplies->public_id);
    $svc = app(DiscussionCommentService::class);

    expect(e36_error(fn () => $svc->create($c, $subject, e36_text(), $lonely->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($c, $subject, e36_text(), $withReplies->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($c, $subject, e36_text(), $old->public_id)))->not->toBeNull()
        ->and(DiscussionComment::query()->count())->toBe(3);
});

test('E36-028: reply counts are correct (live replies only; the preview is capped and the rest is reachable)', function () {
    [$work, $subject] = e36_pair();
    $author = e16User();
    $root = e36_say($author, $subject);
    $repliers = e36_users(7);

    foreach (array_slice($repliers, 0, 5) as $r) {
        e36_say($r, $subject, null, $root);
    }

    $deleted = e36_say($repliers[5], $subject, null, $root);
    $hidden = e36_say($repliers[6], $subject, null, $root);
    app(DiscussionCommentService::class)->delete($repliers[5], $deleted->public_id);
    e36_hide($hidden);

    $data = app(DiscussionReader::class)->forSubject($subject, e16User());
    $row = $data['roots']->getCollection()->first();

    expect($row['reply_count'])->toBe(5)->and(count($row['replies']))->toBe(3)->and($row['more_replies'])->toBe(2)->and((int) e36_thread($subject)->comments_count)->toBe(6);

    $html = $this->get(e36_url($work))->assertOk()->getContent();
    expect($html)->toContain('عرض كل الردود (5)');
    $this->get(route('discussions.replies', ['type' => 'community_puzzle', 'subject' => $work->public_id, 'root' => $root->public_id]))->assertOk()->assertSee('الردود');
});

/* ===================== AK4. EDIT ===================== */

test('E36-029: the owner edits inside the window (route), and the new text is stored normalized', function () {
    [$work, $subject] = e36_pair();
    $user = e16User();
    $c = e36_say($user, $subject, 'النص الأصلي');

    $this->travel(14)->minutes();
    $this->actingAs($user)->patch(route('discussions.comments.update', $c->public_id), ['body' => "  النص   بعد\n\n\n\nالتعديل "])->assertRedirect(route('discussions.permalink', $c->public_id))->assertSessionHas('discussion_status');

    $fresh = $c->fresh();
    expect($fresh->body)->toBe("النص بعد\n\nالتعديل")->and($fresh->edited_at)->not->toBeNull()->and((int) e36_thread($subject)->comments_count)->toBe(1);
    $this->travelBack();
});

test('E36-030: a non-owner cannot edit (another user, the content owner and a moderator alike)', function () {
    $owner = e28_creator();
    [$work, $subject] = e36_pair($owner);
    [$author, $stranger] = e36_users(2);
    $c = e36_say($author, $subject, 'نص صاحبه');

    foreach ([$stranger, $owner, e36_mod()] as $actor) {
        $this->actingAs($actor)->patch(route('discussions.comments.update', $c->public_id), ['body' => 'محاولة تعديل غيري'])->assertRedirect()->assertSessionHas('discussion_error');
    }

    expect($c->fresh()->body)->toBe('نص صاحبه')->and($c->fresh()->edited_at)->toBeNull();
    auth()->logout();
    $this->patch(route('discussions.comments.update', $c->public_id), ['body' => 'ضيف'])->assertRedirect(route('login'));
});

test('E36-031: editing after the window is rejected; a zero window disables edits', function () {
    [, $subject] = e36_pair();
    $user = e16User();
    $c = e36_say($user, $subject, 'نص قبل المهلة');
    $svc = app(DiscussionCommentService::class);

    $this->travel(16)->minutes();
    expect(e36_error(fn () => $svc->edit($user, $c->public_id, 'نص بعد المهلة')))->toContain('مهلة')->and($c->fresh()->body)->toBe('نص قبل المهلة');
    $this->actingAs($user)->patch(route('discussions.comments.update', $c->public_id), ['body' => 'نص بعد المهلة'])->assertRedirect()->assertSessionHas('discussion_error');
    $this->travelBack();

    config(['discussions.edit_window_minutes' => 0]);
    expect(e36_error(fn () => $svc->edit($user, $c->public_id, 'نص آخر')))->not->toBeNull()->and($c->fresh()->body)->toBe('نص قبل المهلة');
});

test('E36-032: a moderation-hidden (or deleted) comment cannot be edited by its author', function () {
    [, $subject] = e36_pair();
    $user = e16User();
    $hidden = e36_say($user, $subject, 'سيُخفى');
    $gone = e36_say($user, $subject, 'سيُحذف');
    e36_hide($hidden);
    $svc = app(DiscussionCommentService::class);
    $svc->delete($user, $gone->public_id);

    expect(e36_error(fn () => $svc->edit($user, $hidden->public_id, 'محاولة تعديل مخفي')))->not->toBeNull()
        ->and(e36_error(fn () => $svc->edit($user, $gone->public_id, 'محاولة تعديل محذوف')))->not->toBeNull()
        ->and($hidden->fresh()->body)->toBe('سيُخفى')->and($gone->fresh()->body)->toBe('');
});

test('E36-033: the edited indicator appears only after a real change (same text is a no-op)', function () {
    [$work, $subject] = e36_pair();
    $user = e16User();
    $c = e36_say($user, $subject, 'نص أول');

    expect($this->get(e36_url($work))->getContent())->not->toContain('تم التعديل');

    app(DiscussionCommentService::class)->edit($user, $c->public_id, 'نص أول');
    expect($c->fresh()->edited_at)->toBeNull();

    app(DiscussionCommentService::class)->edit($user, $c->public_id, 'نص معدّل');
    expect($c->fresh()->edited_at)->not->toBeNull()->and($this->get(e36_url($work))->getContent())->toContain('تم التعديل');
});

test('E36-034: an open report keeps the snapshot of the text as reported, even after the author edits (the old text never shows publicly)', function () {
    [$work, $subject] = e36_pair();
    [$author, $reporter] = e36_users(2);
    $c = e36_say($author, $subject, 'النص المبلَّغ عنه أصلًا');

    $this->actingAs($reporter)->post(route('discussions.reports.store', $c->public_id), ['reason' => 'misleading'])->assertRedirect();
    app(DiscussionCommentService::class)->edit($author, $c->public_id, 'نص جديد مختلف تمامًا');

    $report = \App\Models\DiscussionCommentReport::query()->firstOrFail();
    expect($report->body_snapshot)->toBe('النص المبلَّغ عنه أصلًا')->and($c->fresh()->body)->toBe('نص جديد مختلف تمامًا');

    $html = $this->get(e36_url($work))->getContent();
    expect($html)->not->toContain('النص المبلَّغ عنه أصلًا')->and($html)->toContain('نص جديد مختلف تمامًا');
});

/* ===================== AK5. DELETE ===================== */

test('E36-035: the owner deletes their own comment (route): soft delete, text blanked, counter decremented, second delete refused', function () {
    [, $subject] = e36_pair();
    $user = e16User();
    $c = e36_say($user, $subject, 'سيحذف صاحبه هذا النص');
    e36_say(e16User(), $subject);

    $this->actingAs($user)->delete(route('discussions.comments.destroy', $c->public_id))->assertRedirect()->assertSessionHas('discussion_status');
    $fresh = $c->fresh();

    expect($fresh->deleted_at)->not->toBeNull()->and($fresh->body)->toBe('')->and((int) $fresh->user_id)->toBe($user->id)->and((int) e36_thread($subject)->comments_count)->toBe(1);
    $this->actingAs($user)->delete(route('discussions.comments.destroy', $c->public_id))->assertRedirect()->assertSessionHas('discussion_error');
    expect((int) e36_thread($subject)->comments_count)->toBe(1);
});

test('E36-036: a non-owner cannot delete (another user, the content owner and a moderator alike)', function () {
    $owner = e28_creator();
    [, $subject] = e36_pair($owner);
    [$author, $stranger] = e36_users(2);
    $c = e36_say($author, $subject, 'تعليق لا يحذفه غير صاحبه');

    foreach ([$stranger, $owner, e36_mod()] as $actor) {
        $this->actingAs($actor)->delete(route('discussions.comments.destroy', $c->public_id))->assertRedirect()->assertSessionHas('discussion_error');
    }

    expect($c->fresh()->deleted_at)->toBeNull()->and($c->fresh()->body)->toBe('تعليق لا يحذفه غير صاحبه')->and((int) e36_thread($subject)->comments_count)->toBe(1);
    auth()->logout();
    $this->delete(route('discussions.comments.destroy', $c->public_id))->assertRedirect(route('login'));
});

test('E36-037: a deleted root without replies disappears completely (no tombstone, no text)', function () {
    [$work, $subject] = e36_pair();
    $user = e16User();
    $c = e36_say($user, $subject, 'تعليق وحيد سيختفي');
    e36_say(e16User(), $subject, 'تعليق باقٍ');
    app(DiscussionCommentService::class)->delete($user, $c->public_id);

    $html = $this->get(e36_url($work))->assertOk()->getContent();
    $roots = app(DiscussionReader::class)->forSubject($subject, null)['roots'];

    expect($html)->not->toContain('تعليق وحيد سيختفي')->and($html)->not->toContain('data-comment-tombstone')->and($roots->total())->toBe(1);
    $this->get(route('discussions.permalink', $c->public_id))->assertNotFound();
});

test('E36-038: a deleted root that has replies leaves a neutral tombstone and the replies stay readable', function () {
    [$work, $subject] = e36_pair();
    [$author, $replier] = e36_users(2);
    $root = e36_say($author, $subject, 'نص الجذر الذي سيحذف');
    e36_say($replier, $subject, 'ردّ يبقى ظاهرًا', $root);
    app(DiscussionCommentService::class)->delete($author, $root->public_id);

    $html = $this->get(e36_url($work))->assertOk()->getContent();
    $block = e36_comment_html($html, $root);

    expect($html)->toContain('data-comment-tombstone')->and($html)->toContain('تم حذف هذا التعليق')->and($html)->toContain('ردّ يبقى ظاهرًا')->and($html)->not->toContain('نص الجذر الذي سيحذف')
        ->and($block)->not->toContain($author->name);
    $this->get(route('discussions.permalink', $root->public_id))->assertRedirect();
});

test('E36-039: replies survive a deleted root; deleting a reply leaves its root; counters follow only live comments', function () {
    [, $subject] = e36_pair();
    [$a, $b, $c] = e36_users(3);
    $root = e36_say($a, $subject);
    $r1 = e36_say($b, $subject, null, $root);
    $r2 = e36_say($c, $subject, null, $root);
    $svc = app(DiscussionCommentService::class);

    $svc->delete($a, $root->public_id);
    expect(DiscussionComment::query()->count())->toBe(3)->and((int) $r1->fresh()->parent_id)->toBe($root->id)->and($r1->fresh()->isLive())->toBeTrue()->and((int) e36_thread($subject)->comments_count)->toBe(2);

    $svc->delete($b, $r1->public_id);
    expect($r2->fresh()->isLive())->toBeTrue()->and($root->fresh()->isDeleted())->toBeTrue()->and((int) e36_thread($subject)->comments_count)->toBe(1)->and(app(DiscussionReconciler::class)->run(true)['drift'])->toBe([]);
});

test('E36-040: a deleted comment cannot receive a like (and an existing like does not resurrect it)', function () {
    [, $subject] = e36_pair();
    [$author, $liker, $late] = e36_users(3);
    $c = e36_say($author, $subject);
    app(DiscussionLikeService::class)->like($liker, $c->public_id);
    app(DiscussionCommentService::class)->delete($author, $c->public_id);

    expect(e36_error(fn () => app(DiscussionLikeService::class)->like($late, $c->public_id)))->not->toBeNull()
        ->and(DB::table('discussion_comment_likes')->where('discussion_comment_id', $c->id)->count())->toBe(1);
    $this->actingAs($late)->post(route('discussions.likes.store', $c->public_id))->assertRedirect();
    expect(DB::table('discussion_comment_likes')->where('discussion_comment_id', $c->id)->count())->toBe(1);
});

/* ===================== AK6. BLOCKS ===================== */

test('E36-041: blocked users cannot interact in either direction (comment on the blocker’s content, reply to the blocker’s comment, reply from the blocker)', function () {
    $a = e28_creator();
    $b = e16User();
    [, $subject] = e36_pair($a);
    $rootA = e36_say($a, $subject);
    $other = e16User();
    $rootOther = e36_say($other, $subject);
    app(BlockService::class)->block($a, $b);
    $svc = app(DiscussionCommentService::class);

    expect(e36_error(fn () => $svc->create($b, $subject, e36_text())))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($b, $subject, e36_text(), $rootA->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($a, $subject, e36_text(), $rootOther->public_id)))->toBeNull();

    $rootB = e36_say($other, $subject);
    $bReply = e36_say($other, $subject, null, $rootB);
    expect($bReply->isLive())->toBeTrue();
    $this->actingAs($b)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $subject->publicId]), ['body' => 'محاولة تعليق محظور'])->assertRedirect()->assertSessionHas('discussion_error');
});

test('E36-042: the reverse block (the commenter blocked the content owner) is respected too, symmetrically for replies', function () {
    $owner = e28_creator();
    [, $subject] = e36_pair($owner);
    [$x, $y] = e36_users(2);
    $svc = app(DiscussionCommentService::class);

    app(BlockService::class)->block($x, $owner);
    expect(e36_error(fn () => $svc->create($x, $subject, e36_text())))->not->toBeNull();

    $rootX = e36_say($y, $subject);
    $rootY = e36_say(e16User(), $subject);
    app(BlockService::class)->block($y, $x);
    expect(e36_error(fn () => $svc->create($x, $subject, e36_text(), $rootX->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($y, $subject, e36_text(), $rootY->public_id)))->toBeNull();

    app(BlockService::class)->unblock($x, $owner);
    expect(e36_error(fn () => $svc->create($x, $subject, e36_text())))->toBeNull();
});

test('E36-043: a viewer never sees a blocked author’s root comment (page, reader, permalink and replies page); others still do', function () {
    [$work, $subject] = e36_pair();
    [$viewer, $blocked, $fine] = e36_users(3);
    $rootBlocked = e36_say($blocked, $subject, 'نص من المحظور');
    $rootFine = e36_say($fine, $subject, 'نص من لاعب عادي');
    $rootViewer = e36_say($viewer, $subject, 'نص من صاحب الحظر');
    app(BlockService::class)->block($viewer, $blocked);

    $html = $this->actingAs($viewer)->get(e36_url($work))->assertOk()->getContent();
    expect($html)->not->toContain('نص من المحظور')->and($html)->toContain('نص من لاعب عادي');
    $this->actingAs($viewer)->get(route('discussions.permalink', $rootBlocked->public_id))->assertNotFound();
    $this->actingAs($viewer)->get(route('discussions.replies', ['type' => 'community_puzzle', 'subject' => $work->public_id, 'root' => $rootBlocked->public_id]))->assertNotFound();
    $this->actingAs($viewer)->get(route('discussions.permalink', $rootFine->public_id))->assertRedirect();

    $this->actingAs($fine)->get(e36_url($work))->assertSee('نص من المحظور');
    // المحظور لا يرى تعليقات من حظره (التماثل)، ويرى تعليقات غيره.
    $theirs = $this->actingAs($blocked)->get(e36_url($work))->assertOk()->getContent();
    expect($theirs)->not->toContain('نص من صاحب الحظر')->and($theirs)->toContain('نص من لاعب عادي')->and($theirs)->toContain('نص من المحظور');
    $this->actingAs($blocked)->get(route('discussions.permalink', $rootViewer->public_id))->assertNotFound();
    expect(app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->total())->toBe(2);
});

test('E36-044: replies from blocked users are filtered out of previews, counts and the replies page', function () {
    [$work, $subject] = e36_pair();
    [$viewer, $blocked, $fine, $author] = e36_users(4);
    $root = e36_say($author, $subject, 'جذر عادي');
    e36_say($blocked, $subject, 'ردّ من المحظور', $root);
    e36_say($fine, $subject, 'ردّ من العادي', $root);
    app(BlockService::class)->block($viewer, $blocked);

    $row = app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->getCollection()->first();
    expect($row['reply_count'])->toBe(1)->and(count($row['replies']))->toBe(1);

    $html = $this->actingAs($viewer)->get(e36_url($work))->getContent();
    expect($html)->not->toContain('ردّ من المحظور')->and($html)->toContain('ردّ من العادي');
    $page = $this->actingAs($viewer)->get(route('discussions.replies', ['type' => 'community_puzzle', 'subject' => $work->public_id, 'root' => $root->public_id]))->assertOk()->getContent();
    expect($page)->not->toContain('ردّ من المحظور')->and($page)->toContain('ردّ من العادي');
    $this->actingAs($viewer)->get(route('discussions.permalink', DiscussionComment::query()->where('user_id', $blocked->id)->value('public_id')))->assertNotFound();
});

test('E36-045: block filtering costs no extra queries per blocked author (page query count is flat as blocked authors grow)', function () {
    [$work, $subject] = e36_pair();
    $viewer = e16User();
    $authors = e36_users(12);

    foreach ($authors as $a) {
        $root = e36_say($a, $subject);
        e36_say($authors[0], $subject, null, $root);
    }

    $url = e36_url($work);
    $this->actingAs($viewer)->get($url)->assertOk();   // تسخين
    $none = e27_queries(fn () => $this->actingAs($viewer)->get($url)->assertOk());

    foreach (array_slice($authors, 4) as $a) {
        app(BlockService::class)->block($viewer, $a);
    }

    $this->actingAs($viewer)->get($url)->assertOk();
    $many = e27_queries(fn () => $this->actingAs($viewer)->get($url)->assertOk());

    expect($many)->toBeLessThanOrEqual($none + 1)->and(app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->total())->toBeLessThan(12);
});
