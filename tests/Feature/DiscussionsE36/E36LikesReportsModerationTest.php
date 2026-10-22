<?php

use App\Filament\Resources\DiscussionCommentReportResource\Pages\ListDiscussionCommentReports;
use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\User;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Discussions\DiscussionReader;
use App\Services\Discussions\DiscussionReconciler;
use App\Services\Discussions\DiscussionReportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

/*
| E36 AK7 (46-54) الإعجابات + AK8 (55-61) البلاغات + AK9 (62-71) الإشراف.
| إعجاب واحد لكل (مستخدم، تعليق) بقيد قاعدة، من أعجب خاص. بلاغ مفتوح واحد لكل (تعليق، مبلِّغ) ولقطة نص للإشراف وحده. الإشراف سلطة المنصة بصلاحية discussions.moderate.
*/

beforeEach(fn () => e36_seed());

/** @return array{0: \App\Models\CreatorPuzzle, 1: \App\Services\Discussions\DiscussionSubject} */
function e36_lr_pair(?User $creator = null): array
{
    $work = e36_work($creator);

    return [$work, e36_subject_or_fail($work)];
}

/* ===================== AK7. LIKES ===================== */

test('E36-046: a verified user likes a comment (route): row stored, counter incremented, state shown to the liker only', function () {
    [$work, $subject] = e36_lr_pair();
    [$author, $liker] = e36_users(2);
    $c = e36_say($author, $subject, 'تعليق يستحق الإعجاب');

    $this->actingAs($liker)->post(route('discussions.likes.store', $c->public_id))->assertRedirect(route('discussions.permalink', $c->public_id));

    expect(DB::table('discussion_comment_likes')->where('user_id', $liker->id)->where('discussion_comment_id', $c->id)->count())->toBe(1)->and((int) $c->fresh()->likes_count)->toBe(1);

    $mine = e36_comment_html($this->actingAs($liker)->get(e36_url($work))->getContent(), $c);
    $theirs = e36_comment_html($this->actingAs(e16User())->get(e36_url($work))->getContent(), $c);
    expect($mine)->toContain('aria-pressed="true"')->and($theirs)->toContain('aria-pressed="false"')->and($theirs)->toContain('1 إعجاب');
    auth()->logout();
    $this->post(route('discussions.likes.store', $c->public_id))->assertRedirect(route('login'));
});

test('E36-047: liking your own comment is rejected', function () {
    [, $subject] = e36_lr_pair();
    $author = e16User();
    $c = e36_say($author, $subject);

    expect(e36_error(fn () => app(DiscussionLikeService::class)->like($author, $c->public_id)))->not->toBeNull();
    $this->actingAs($author)->post(route('discussions.likes.store', $c->public_id))->assertRedirect()->assertSessionHas('discussion_error');
    expect(DB::table('discussion_comment_likes')->count())->toBe(0)->and((int) $c->fresh()->likes_count)->toBe(0);
});

test('E36-048: a duplicate like is safe (one row, counter once), also when posted twice through the route', function () {
    [, $subject] = e36_lr_pair();
    [$author, $liker] = e36_users(2);
    $c = e36_say($author, $subject);
    $svc = app(DiscussionLikeService::class);

    expect($svc->like($liker, $c->public_id)['changed'])->toBeTrue()->and($svc->like($liker, $c->public_id)['changed'])->toBeFalse();
    $this->actingAs($liker)->post(route('discussions.likes.store', $c->public_id))->assertRedirect();
    $this->actingAs($liker)->post(route('discussions.likes.store', $c->public_id))->assertRedirect();

    expect(DB::table('discussion_comment_likes')->count())->toBe(1)->and((int) $c->fresh()->likes_count)->toBe(1);
});

test('E36-049: unlike works, is idempotent and the counter never goes negative', function () {
    [, $subject] = e36_lr_pair();
    [$author, $liker] = e36_users(2);
    $c = e36_say($author, $subject);
    $svc = app(DiscussionLikeService::class);
    $svc->like($liker, $c->public_id);

    $this->actingAs($liker)->delete(route('discussions.likes.destroy', $c->public_id))->assertRedirect();
    expect(DB::table('discussion_comment_likes')->count())->toBe(0)->and((int) $c->fresh()->likes_count)->toBe(0)->and($svc->unlike($liker, $c->public_id)['changed'])->toBeFalse()->and((int) $c->fresh()->likes_count)->toBe(0);

    DB::table('discussion_comments')->where('id', $c->id)->update(['likes_count' => 0]);
    $svc->unlike($liker, $c->public_id);
    expect((int) $c->fresh()->likes_count)->toBe(0);
});

test('E36-050: likes_count matches the rows (several likers, an unlike, and reconcile detects and repairs drift)', function () {
    [, $subject] = e36_lr_pair();
    $author = e16User();
    $likers = e36_users(4);
    $c = e36_say($author, $subject);
    $svc = app(DiscussionLikeService::class);

    foreach ($likers as $l) {
        $svc->like($l, $c->public_id);
    }

    $svc->unlike($likers[0], $c->public_id);
    expect((int) $c->fresh()->likes_count)->toBe(3)->and(DB::table('discussion_comment_likes')->where('discussion_comment_id', $c->id)->count())->toBe(3)->and(app(DiscussionReconciler::class)->run(true)['drift'])->toBe([]);

    DB::table('discussion_comments')->where('id', $c->id)->update(['likes_count' => 99]);
    $dry = app(DiscussionReconciler::class)->run(true);
    expect($dry['drift'])->toHaveCount(1)->and($dry['drift'][0])->toMatchArray(['column' => 'likes_count', 'stored' => 99, 'actual' => 3])->and((int) $c->fresh()->likes_count)->toBe(99);

    $fixed = app(DiscussionReconciler::class)->run(false);
    expect($fixed['fixed'])->toBe(1)->and((int) $c->fresh()->likes_count)->toBe(3);
});

test('E36-051: who liked is never public (no names on the page, no likers in the reader, no GET route lists likers)', function () {
    [$work, $subject] = e36_lr_pair();
    $author = e16User();
    $liker = e16User(['name' => 'معجب سري فريد']);
    $c = e36_say($author, $subject);
    app(DiscussionLikeService::class)->like($liker, $c->public_id);

    $html = $this->get(e36_url($work))->getContent().$this->actingAs(e16User())->get(e36_url($work))->getContent();
    $reader = json_encode(app(DiscussionReader::class)->forSubject($subject, e16User())['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE);
    expect($html)->not->toContain('معجب سري فريد')->and($html)->not->toContain($liker->email)->and($reader)->not->toContain('معجب سري فريد')->and($reader)->not->toContain($liker->email)->and($reader)->not->toContain('likers');

    $likeRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'like'));
    expect($likeRoutes->isNotEmpty())->toBeTrue()->and($likeRoutes->every(fn ($r) => ! in_array('GET', $r->methods(), true)))->toBeTrue();
});

test('E36-052: a moderation-hidden comment cannot receive a new like (existing likes are kept for a restore)', function () {
    [, $subject] = e36_lr_pair();
    [$author, $early, $late] = e36_users(3);
    $c = e36_say($author, $subject);
    app(DiscussionLikeService::class)->like($early, $c->public_id);
    e36_hide($c);

    expect(e36_error(fn () => app(DiscussionLikeService::class)->like($late, $c->public_id)))->not->toBeNull()->and(DB::table('discussion_comment_likes')->count())->toBe(1)
        ->and(app(DiscussionReader::class)->forSubject($subject, $author)['roots']->getCollection()->first()['likes'])->toBe(0);

    app(DiscussionModerationService::class)->restore($c->fresh(), e36_mod());
    expect((int) $c->fresh()->likes_count)->toBe(1);
});

test('E36-053: a locked thread rejects new likes but still lets users remove theirs', function () {
    [, $subject] = e36_lr_pair();
    [$author, $liker, $newcomer] = e36_users(3);
    $c = e36_say($author, $subject);
    app(DiscussionLikeService::class)->like($liker, $c->public_id);
    e36_lock($subject);

    expect(e36_error(fn () => app(DiscussionLikeService::class)->like($newcomer, $c->public_id)))->toContain('إغلاق')->and(DB::table('discussion_comment_likes')->count())->toBe(1);
    expect(app(DiscussionLikeService::class)->unlike($liker, $c->public_id)['changed'])->toBeTrue()->and((int) $c->fresh()->likes_count)->toBe(0);
});

test('E36-054: likes and unlikes have zero economy/progression effect on the liker or the author', function () {
    [, $subject] = e36_lr_pair();
    [$author, $liker] = e36_users(2);
    $c = e36_say($author, $subject);
    $before = e36_world($author, $liker);

    app(DiscussionLikeService::class)->like($liker, $c->public_id);
    $this->actingAs($liker)->delete(route('discussions.likes.destroy', $c->public_id));
    app(DiscussionLikeService::class)->like($liker, $c->public_id);

    expect(e36_world($author, $liker))->toBe($before);
});

/* ===================== AK8. REPORTS ===================== */

test('E36-055: a user reports a comment (route): one open report with a closed reason and the reporter recorded', function () {
    [, $subject] = e36_lr_pair();
    [$author, $reporter] = e36_users(2);
    $c = e36_say($author, $subject);
    $notes = DB::table('notifications')->count();

    $this->actingAs($reporter)->post(route('discussions.reports.store', $c->public_id), ['reason' => 'harassment'])->assertRedirect(route('discussions.permalink', $c->public_id))->assertSessionHas('discussion_status');
    $report = DiscussionCommentReport::query()->firstOrFail();

    expect($report->reason)->toBe('harassment')->and($report->status)->toBe('open')->and((int) $report->reporter_id)->toBe($reporter->id)->and((int) $report->open_marker)->toBe(1)->and((int) $report->discussion_comment_id)->toBe($c->id)
        ->and(DB::table('notifications')->count())->toBe($notes);
    auth()->logout();
    $this->post(route('discussions.reports.store', $c->public_id), ['reason' => 'spam'])->assertRedirect(route('login'));
});

test('E36-056: reporting your own comment is rejected', function () {
    [, $subject] = e36_lr_pair();
    $author = e16User();
    $c = e36_say($author, $subject);

    expect(e36_error(fn () => app(DiscussionReportService::class)->report($author, $c->public_id, 'spam')))->not->toBeNull();
    $this->actingAs($author)->post(route('discussions.reports.store', $c->public_id), ['reason' => 'spam'])->assertRedirect()->assertSessionHas('discussion_error');
    expect(DiscussionCommentReport::query()->count())->toBe(0);
});

test('E36-057: a duplicate open report is prevented (service and database); after closing, a new report is allowed; others may report', function () {
    [, $subject] = e36_lr_pair();
    [$author, $r1, $r2] = e36_users(3);
    $c = e36_say($author, $subject);
    $svc = app(DiscussionReportService::class);
    $first = $svc->report($r1, $c->public_id, 'spam');

    expect(e36_error(fn () => $svc->report($r1, $c->public_id, 'harassment')))->not->toBeNull()->and(DiscussionCommentReport::query()->count())->toBe(1);
    $forged = ['discussion_comment_id' => $c->id, 'reporter_id' => $r1->id, 'reason' => 'spam', 'status' => 'open', 'open_marker' => 1, 'body_snapshot' => 'x', 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::table('discussion_comment_reports')->insert($forged))->toThrow(QueryException::class);

    expect($svc->report($r2, $c->public_id, 'spam')->id)->not->toBe($first->id);
    app(DiscussionModerationService::class)->dismiss($first->fresh(), e36_mod(), 'لا مخالفة');
    expect($svc->report($r1, $c->public_id, 'inappropriate')->status)->toBe('open')->and(DiscussionCommentReport::query()->count())->toBe(3);
});

test('E36-058: the reason is validated against the closed list (route, service, free text refused); every listed reason works', function () {
    [, $subject] = e36_lr_pair();
    $author = e16User();
    $reporter = e16User();
    $c = e36_say($author, $subject);

    foreach (['', 'not-a-reason', '<script>alert(1)</script>', 'SPAM', 'تحرش'] as $bad) {
        $this->actingAs($reporter)->post(route('discussions.reports.store', $c->public_id), ['reason' => $bad])->assertSessionHasErrorsIn('discussion', 'reason');
    }

    expect(e36_error(fn () => app(DiscussionReportService::class)->report($reporter, $c->public_id, 'free text reason')))->not->toBeNull()->and(DiscussionCommentReport::query()->count())->toBe(0);

    foreach (array_keys(DiscussionCommentReport::REASONS) as $reason) {
        $comment = e36_say($author, $subject);
        expect(app(DiscussionReportService::class)->report($reporter, $comment->public_id, $reason)->reason)->toBe($reason);
    }

    expect(DiscussionCommentReport::query()->count())->toBe(count(DiscussionCommentReport::REASONS));
});

test('E36-059: the body snapshot is stored at report time (normalized text, kept after delete)', function () {
    [, $subject] = e36_lr_pair();
    [$author, $reporter] = e36_users(2);
    $c = e36_say($author, $subject, "  نص   للقطة\n\n\n\nالدليل ");

    $report = app(DiscussionReportService::class)->report($reporter, $c->public_id, 'misleading');
    expect($report->body_snapshot)->toBe("نص للقطة\n\nالدليل")->and($report->body_snapshot)->toBe($c->body);

    app(DiscussionCommentService::class)->delete($author, $c->public_id);
    expect($c->fresh()->body)->toBe('')->and($report->fresh()->body_snapshot)->toBe("نص للقطة\n\nالدليل");
});

test('E36-060: the snapshot is admin-only (never on public pages or reader data; moderators read it; others get 403)', function () {
    [$work, $subject] = e36_lr_pair();
    [$author, $reporter, $plain] = e36_users(3);
    $c = e36_say($author, $subject, 'نص الدليل السري للإشراف');
    $report = app(DiscussionReportService::class)->report($reporter, $c->public_id, 'spam');
    app(DiscussionCommentService::class)->edit($author, $c->public_id, 'نص جديد علني');

    $public = $this->get(e36_url($work))->getContent().$this->actingAs($reporter)->get(e36_url($work))->getContent();
    $reader = json_encode(app(DiscussionReader::class)->forSubject($subject, $reporter)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE);
    expect($public)->not->toContain('نص الدليل السري للإشراف')->and($reader)->not->toContain('نص الدليل السري للإشراف');

    $this->actingAs(e36_mod())->get('/admin/discussion-comment-reports/'.$report->getKey())->assertOk()->assertSee('نص الدليل السري للإشراف');
    $this->actingAs($plain)->get('/admin/discussion-comment-reports/'.$report->getKey())->assertForbidden();
    $this->actingAs($reporter)->get('/admin/discussion-comment-reports')->assertForbidden();
    auth()->logout();
    $this->get('/admin/discussion-comment-reports')->assertRedirect();
});

test('E36-061: an ordinary user cannot moderate (services abort 403 and no route or admin screen is reachable)', function () {
    [, $subject] = e36_lr_pair();
    [$author, $plain] = e36_users(2);
    $c = e36_say($author, $subject);
    $report = app(DiscussionReportService::class)->report($plain, $c->public_id, 'spam');
    $thread = e36_thread($subject);
    $svc = app(DiscussionModerationService::class);

    foreach ([fn () => $svc->hide($c, $plain, 'spam'), fn () => $svc->restore($c, $plain), fn () => $svc->lockThread($thread, $plain), fn () => $svc->unlockThread($thread, $plain),
        fn () => $svc->dismiss($report, $plain), fn () => $svc->action($report, $plain), fn () => $svc->actionAndHide($report, $plain)] as $call) {
        expect($call)->toThrow(HttpException::class);
    }

    expect($c->fresh()->status)->toBe('visible')->and($thread->fresh()->is_locked)->toBeFalse()->and($report->fresh()->status)->toBe('open');
    foreach (['discussion-comment-reports', 'discussion-comments', 'discussion-threads', 'discussions-analytics'] as $slug) {
        $this->actingAs($plain)->get('/admin/'.$slug)->assertForbidden();
    }

    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'discussions') && preg_match('/hide|restore|lock|moderat/', $r->uri()))->count())->toBe(0);
});

/* ===================== AK9. MODERATION ===================== */

test('E36-062: a moderator hides a comment: status, actor, structured reason, counter, and open reports closed as actioned', function () {
    [, $subject] = e36_lr_pair();
    [$author, $reporter] = e36_users(2);
    $mod = e36_mod();
    $c = e36_say($author, $subject);
    e36_say(e16User(), $subject);
    $report = app(DiscussionReportService::class)->report($reporter, $c->public_id, 'spam');

    $hidden = app(DiscussionModerationService::class)->hide($c, $mod, 'harassment', 'ملاحظة للتدقيق');
    expect($hidden->status)->toBe('moderation_hidden')->and((int) $hidden->moderated_by)->toBe($mod->id)->and($hidden->moderated_at)->not->toBeNull()->and($hidden->moderation_reason)->toBe('harassment')
        ->and((int) e36_thread($subject)->comments_count)->toBe(1)->and($report->fresh()->status)->toBe('actioned')->and($report->fresh()->open_marker)->toBeNull()->and((int) $report->fresh()->handled_by)->toBe($mod->id);

    expect(e36_error(fn () => app(DiscussionModerationService::class)->hide($c->fresh(), $mod, 'spam')))->not->toBeNull()
        ->and(e36_error(fn () => app(DiscussionModerationService::class)->hide($c->fresh(), $mod, 'not-a-reason')))->not->toBeNull();
});

test('E36-063: a hidden comment body never reaches the public (guests, others, reporter, content owner), only a neutral or role-specific placeholder', function () {
    $owner = e28_creator();
    [$work, $subject] = e36_lr_pair($owner);
    [$author, $other] = e36_users(2);
    $c = e36_say($author, $subject, 'نص مخالف سيخفى تمامًا');
    e36_say($other, $subject, 'ردّ يبقي الجذر ظاهرًا كلحد', $c);
    e36_hide($c, null, 'hate_or_abuse');

    foreach ([null, $other, $owner] as $viewer) {
        $viewer ? $this->actingAs($viewer) : auth()->logout();
        $html = $this->get(e36_url($work))->assertOk()->getContent();
        expect($html)->not->toContain('نص مخالف سيخفى تمامًا')->and($html)->toContain('تم إخفاء هذا التعليق من قبل الإدارة')->and($html)->not->toContain('الإساءة')->and($html)->toContain('ردّ يبقي الجذر ظاهرًا كلحد');
    }

    $mine = $this->actingAs($author)->get(e36_url($work))->getContent();
    expect($mine)->toContain('أخفت الإدارة تعليقك')->and($mine)->not->toContain('نص مخالف سيخفى تمامًا');
    $mod = $this->actingAs(e36_mod())->get(e36_url($work))->getContent();
    expect($mod)->toContain('تعليق مخفي إشرافيًا')->and($mod)->not->toContain('نص مخالف سيخفى تمامًا');

    foreach ([null, $other] as $viewer) {
        expect(json_encode(app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE))->not->toContain('نص مخالف سيخفى تمامًا');
    }
});

test('E36-064: a moderator restores a hidden comment (visible again, counter restored, reason cleared)', function () {
    [$work, $subject] = e36_lr_pair();
    $author = e16User();
    $c = e36_say($author, $subject, 'نص سيُستعاد');
    e36_hide($c);
    expect((int) e36_thread($subject)->comments_count)->toBe(0);

    $restored = app(DiscussionModerationService::class)->restore($c->fresh(), e36_mod());
    expect($restored->status)->toBe('visible')->and($restored->moderation_reason)->toBeNull()->and($restored->moderated_by)->toBeNull()->and((int) e36_thread($subject)->comments_count)->toBe(1);
    $this->get(e36_url($work))->assertOk()->assertSee('نص سيُستعاد');
    expect(e36_error(fn () => app(DiscussionModerationService::class)->restore($c->fresh(), e36_mod())))->not->toBeNull();
});

test('E36-065: hiding is audited with the actor and the structured reason', function () {
    [, $subject] = e36_lr_pair();
    $mod = e36_mod();
    $c = e36_say(e16User(), $subject);
    e36_hide($c, $mod, 'copyright', 'سبب للتدقيق');

    $row = e36_audit('comment_hidden');
    expect($row)->not->toBeNull()->and((int) $row->actor_user_id)->toBe($mod->id)->and($row->metadata['reason'])->toBe('copyright')->and($row->metadata['comment'])->toBe($c->public_id);
});

test('E36-066: restoring is audited with the actor', function () {
    [, $subject] = e36_lr_pair();
    $mod = e36_mod();
    $c = e36_say(e16User(), $subject);
    e36_hide($c, $mod);
    app(DiscussionModerationService::class)->restore($c->fresh(), $mod);

    $row = e36_audit('comment_restored');
    expect($row)->not->toBeNull()->and((int) $row->actor_user_id)->toBe($mod->id)->and($row->metadata['comment'])->toBe($c->public_id);
});

test('E36-067: locking a thread (audited) blocks comments, replies, edits and new likes, keeps reading, reporting and the author’s own delete', function () {
    [$work, $subject] = e36_lr_pair();
    [$author, $other] = e36_users(3);
    $mod = e36_mod();
    $root = e36_say($author, $subject, 'تعليق قبل القفل');
    $locked = e36_lock($subject, $mod, 'نقاش محتدم');

    $svc = app(DiscussionCommentService::class);
    expect($locked->is_locked)->toBeTrue()->and((int) $locked->locked_by)->toBe($mod->id)->and($locked->lock_reason)->toBe('نقاش محتدم')->and($locked->locked_at)->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($other, $subject, e36_text())))->not->toBeNull()
        ->and(e36_error(fn () => $svc->create($other, $subject, e36_text(), $root->public_id)))->not->toBeNull()
        ->and(e36_error(fn () => $svc->edit($author, $root->public_id, 'تعديل بعد القفل')))->not->toBeNull()
        ->and(e36_error(fn () => app(DiscussionLikeService::class)->like($other, $root->public_id)))->not->toBeNull();

    $this->get(e36_url($work))->assertOk()->assertSee('تعليق قبل القفل');
    expect(app(DiscussionReportService::class)->report($other, $root->public_id, 'spam')->status)->toBe('open');
    $svc->delete($author, $root->public_id);
    expect($root->fresh()->isDeleted())->toBeTrue();

    $row = e36_audit('thread_locked');
    expect($row)->not->toBeNull()->and((int) $row->actor_user_id)->toBe($mod->id)->and(e36_error(fn () => e36_lock($subject, $mod)))->not->toBeNull();
});

test('E36-068: unlocking a thread (audited) restores writing', function () {
    [, $subject] = e36_lr_pair();
    [$a, $b] = e36_users(2);
    $mod = e36_mod();
    e36_say($a, $subject);
    $thread = e36_lock($subject, $mod);
    expect(e36_error(fn () => e36_say($b, $subject)))->not->toBeNull();

    $unlocked = app(DiscussionModerationService::class)->unlockThread($thread, $mod);
    expect($unlocked->is_locked)->toBeFalse()->and($unlocked->locked_by)->toBeNull()->and($unlocked->lock_reason)->toBeNull()->and($unlocked->locked_at)->toBeNull()
        ->and(e36_say($b, $subject)->isLive())->toBeTrue()->and((int) e36_audit('thread_unlocked')->actor_user_id)->toBe($mod->id)
        ->and(e36_error(fn () => app(DiscussionModerationService::class)->unlockThread($unlocked, $mod)))->not->toBeNull();
});

test('E36-069: the content owner (creator or collection owner) and non-moderator roles cannot hide criticism; permission, not role name, is what counts', function () {
    $creator = e28_creator();
    [, $subject] = e36_lr_pair($creator);
    $critic = e16User();
    $c = e36_say($critic, $subject, 'نقد قاسٍ لهذه الأحجية');
    $svc = app(DiscussionModerationService::class);

    $manager = e35_manager();
    $support = e16User();
    $support->assignRole('support');
    foreach ([$creator, $manager, $support, $critic] as $actor) {
        expect(fn () => $svc->hide($c->fresh(), $actor, 'spam'))->toThrow(HttpException::class)->and($actor->can('discussions.moderate'))->toBeFalse();
        // جلسة نظيفة لكل ممثل: تبديل المستخدمين داخل جلسة اختبار واحدة يُسقط AuthenticateSession (تلاعب كلمة المرور) فيحجب 403 الحقيقي.
        $this->flushSession();
        $this->actingAs($actor)->get('/admin/discussion-comments')->assertForbidden();
        auth()->logout();
    }

    expect($c->fresh()->status)->toBe('visible');
    $viaPermission = e16User();
    $viaPermission->givePermissionTo('discussions.moderate');
    expect($svc->hide($c->fresh(), $viaPermission, 'spam')->status)->toBe('moderation_hidden')->and(e35_admin()->can('discussions.moderate'))->toBeTrue();

    // مالك مجموعة عامة كذلك: لا سلطة على تعليقات صفحته.
    $owner = e16User();
    $collection = e35_public($owner);
    $cc = e36_say($critic, e36_subject_or_fail($collection), 'نقد لمجموعة عامة');
    expect(fn () => $svc->hide($cc, $owner, 'spam'))->toThrow(HttpException::class);
});

test('E36-070: dismissing a report closes it without touching the comment; the user can report again later (audited)', function () {
    [, $subject] = e36_lr_pair();
    [$author, $reporter] = e36_users(2);
    $mod = e36_mod();
    $c = e36_say($author, $subject);
    $report = app(DiscussionReportService::class)->report($reporter, $c->public_id, 'spam');

    $closed = app(DiscussionModerationService::class)->dismiss($report, $mod, 'لا مخالفة');
    expect($closed->status)->toBe('dismissed')->and($closed->open_marker)->toBeNull()->and((int) $closed->handled_by)->toBe($mod->id)->and($closed->handler_note)->toBe('لا مخالفة')->and($closed->handled_at)->not->toBeNull()
        ->and($c->fresh()->isLive())->toBeTrue()->and((int) e36_thread($subject)->comments_count)->toBe(1)->and((int) e36_audit('comment_report_dismissed')->actor_user_id)->toBe($mod->id);
    expect(e36_error(fn () => app(DiscussionModerationService::class)->dismiss($closed, $mod)))->not->toBeNull();

    expect(app(DiscussionReportService::class)->report($reporter, $c->public_id, 'harassment')->status)->toBe('open');

    // من لوحة الإدارة (Filament): رفض بلاغ مفتوح.
    $open = DiscussionCommentReport::query()->where('status', 'open')->firstOrFail();
    Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class)->assertCanSeeTableRecords([$open])->callTableAction('dismiss', $open, data: ['note' => 'مرفوض من اللوحة'])->assertHasNoTableActionErrors();
    expect($open->fresh()->status)->toBe('dismissed')->and($open->fresh()->handler_note)->toBe('مرفوض من اللوحة');
});

test('E36-071: actioning a report (with or without hiding) is audited; hide closes every open report on that comment', function () {
    [, $subject] = e36_lr_pair();
    [$author, $r1, $r2, $r3] = e36_users(4);
    $mod = e36_mod();
    $svc = app(DiscussionModerationService::class);

    $plain = e36_say($author, $subject);
    $report = app(DiscussionReportService::class)->report($r1, $plain->public_id, 'misleading');
    $actioned = $svc->action($report, $mod, 'تم التنبيه');
    expect($actioned->status)->toBe('actioned')->and($plain->fresh()->isLive())->toBeTrue()->and((int) e36_audit('comment_report_actioned')->actor_user_id)->toBe($mod->id);

    $bad = e36_say($author, $subject);
    $a = app(DiscussionReportService::class)->report($r1, $bad->public_id, 'spam');
    $b = app(DiscussionReportService::class)->report($r2, $bad->public_id, 'harassment');
    $svc->actionAndHide($a, $mod, 'مخالف');
    expect($bad->fresh()->status)->toBe('moderation_hidden')->and($bad->fresh()->moderation_reason)->toBe('spam')->and($a->fresh()->status)->toBe('actioned')->and($b->fresh()->status)->toBe('actioned')
        ->and(DiscussionCommentReport::query()->where('status', 'open')->count())->toBe(0)->and(e36_error(fn () => $svc->actionAndHide($a->fresh(), $mod)))->not->toBeNull();

    $third = e36_say($author, $subject);
    $c = app(DiscussionReportService::class)->report($r3, $third->public_id, 'inappropriate');
    Livewire::actingAs($mod)->test(ListDiscussionCommentReports::class)->callTableAction('hide', $c, data: ['note' => 'إخفاء من اللوحة'])->assertHasNoTableActionErrors();
    expect($third->fresh()->status)->toBe('moderation_hidden')->and($c->fresh()->status)->toBe('actioned');
});
