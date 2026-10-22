<?php

use App\Models\DiscussionComment;
use App\Models\User;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationUrlResolver;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E36 AL (72-78) إشعارات النقاش عبر E15: ثلاثة أنواع فقط (ردّ على تعليقك / تعليق على محتواك / إخفاء تعليقك)، بلا اسم المعلِّق ولا نص التعليق،
| بلا إشعار ذاتي ولا على الإعجاب والبلاغ والقفل، بمنع إغراق بنافذة زمنية، وفشل الإشعار لا يمسّ التعليق أبدًا.
*/

beforeEach(fn () => e36_seed());

/** @return \Illuminate\Support\Collection<int, DatabaseNotification> */
function e36_notes(User $user, ?string $type = null)
{
    return DatabaseNotification::query()->where('notifiable_id', $user->id)->whereIn('type_key', $type ? [$type] : ['comment_reply_received', 'new_comment_on_your_content', 'comment_hidden'])->orderBy('created_at')->get();
}

/** إشعارات النقاش الثلاثة لأي مستخدم في القاعدة كلها. */
function e36_all_notes(): int
{
    return DatabaseNotification::query()->whereIn('type_key', ['comment_reply_received', 'new_comment_on_your_content', 'comment_hidden'])->count();
}

/** يثبّت الزمن على بداية نافذة (ثوانٍ) + إزاحة؛ المفتاح الدلالي دلو ثابت لا نافذة منزلقة. */
function e36_align(int $windowSeconds, int $offsetSeconds = 30): Carbon
{
    return Carbon::createFromTimestamp(intdiv(now()->timestamp, $windowSeconds) * $windowSeconds + $offsetSeconds);
}

test('E36-072: replying to a root comment notifies its author once with a safe link and no commenter identity or body', function () {
    $owner = e28_creator();
    $work = e36_work($owner);
    $subject = e36_subject_or_fail($work);
    [$author, $replier] = e36_users(2);
    $replier->forceFill(['name' => 'مُعلِّق سرّي الاسم'])->save();
    $root = e36_say($author, $subject, 'تعليقي الأصلي');
    $reply = e36_say($replier, $subject, 'نص الرد الخاص جدًا', $root);

    $notes = e36_notes($author, 'comment_reply_received');
    expect($notes)->toHaveCount(1);
    $n = $notes->first();
    $data = (array) $n->data;
    $flat = json_encode($data, JSON_UNESCAPED_UNICODE);

    expect($data['category'])->toBe('social')->and($data['action_route'])->toBe('discussions.permalink')->and($data['action_params'])->toBe(['comment' => $reply->public_id])->and($data['title'])->toContain($subject->title)
        ->and($flat)->not->toContain('مُعلِّق سرّي الاسم')->and($flat)->not->toContain('نص الرد الخاص جدًا')->and($flat)->not->toContain($replier->email)->and($flat)->not->toContain($root->body);
    expect(app(NotificationUrlResolver::class)->resolve($data))->toBe(route('discussions.permalink', $reply->public_id, false));

    $this->actingAs($author)->post(route('notifications.open', $n->id))->assertRedirect(route('discussions.permalink', $reply->public_id, false));
    expect($n->fresh()->read_at)->not->toBeNull();
    $this->actingAs($author)->get(route('discussions.permalink', $reply->public_id))->assertRedirect();
    $this->actingAs($author)->get('/notifications')->assertOk()->assertSee('ردّ جديد على تعليقك')->assertDontSee('مُعلِّق سرّي الاسم');
});

test('E36-073: no self-notification (reply to yourself; the owner commenting or replying on their own content)', function () {
    $owner = e28_creator();
    $subject = e36_subject_or_fail(e36_work($owner));
    $other = e16User();

    $mine = e36_say($owner, $subject, 'تعليق المالك على محتواه');
    e36_say($owner, $subject, 'ردّ المالك على نفسه', $mine);
    expect(e36_all_notes())->toBe(0);

    $root = e36_say($other, $subject, 'جذر شخص آخر');
    DB::table('notifications')->delete();
    e36_say($other, $subject, 'ردّ على نفسه', $root);
    expect(e36_all_notes())->toBe(1)->and(e36_notes($other))->toHaveCount(0)->and(e36_notes($owner, 'new_comment_on_your_content'))->toHaveCount(1);
});

test('E36-074: the content owner is notified of a new comment (puzzle, series and public collection), once and never twice for the same comment', function () {
    $creator = e28_creator();
    $commenter = e16User();
    $puzzle = e36_subject_or_fail(e36_work($creator));
    $series = e36_subject_or_fail(e36_series($creator));
    $collectionOwner = e16User();
    $collection = e36_subject_or_fail(e35_public($collectionOwner));

    e36_say($commenter, $puzzle, 'تعليق على أحجية');
    e36_say($commenter, $series, 'تعليق على سلسلة');
    e36_say($commenter, $collection, 'تعليق على مجموعة');

    $mine = e36_notes($creator, 'new_comment_on_your_content');
    expect($mine)->toHaveCount(2)->and(e36_notes($collectionOwner, 'new_comment_on_your_content'))->toHaveCount(1)->and(e36_notes($commenter))->toHaveCount(0);
    $data = (array) $mine->first()->data;
    expect($data['action_route'])->toBe('discussions.permalink')->and($data['title'])->toContain($puzzle->title)->and(json_encode($data, JSON_UNESCAPED_UNICODE))->not->toContain('تعليق على أحجية');

    // المالك هو كاتب الجذر نفسه: يصله إشعار الردّ فقط لا نسخة ثانية «محتواك».
    $ownerRoot = e36_say($creator, $puzzle, 'جذر المالك');
    $ownerBefore = e36_notes($creator, 'new_comment_on_your_content')->count();
    expect(e36_notes($creator, 'comment_reply_received'))->toHaveCount(0);
    e36_say($commenter, $puzzle, 'ردّ على المالك', $ownerRoot);
    expect(e36_notes($creator, 'comment_reply_received'))->toHaveCount(1)->and(e36_notes($creator, 'new_comment_on_your_content')->count())->toBe($ownerBefore);
});

test('E36-075: likes, unlikes, reports, locks and unlocks never notify anyone', function () {
    $owner = e28_creator();
    $subject = e36_subject_or_fail(e36_work($owner));
    [$author, $liker, $reporter] = e36_users(3);
    $c = e36_say($author, $subject, 'تعليق للتفاعل');
    DB::table('notifications')->delete();

    app(DiscussionLikeService::class)->like($liker, $c->public_id);
    app(DiscussionLikeService::class)->unlike($liker, $c->public_id);
    $this->actingAs($liker)->post(route('discussions.likes.store', $c->public_id));
    app(DiscussionReportService::class)->report($reporter, $c->public_id, 'spam');
    $mod = e36_mod();
    e36_lock($subject, $mod);
    app(DiscussionModerationService::class)->unlockThread(e36_thread($subject), $mod);
    app(DiscussionModerationService::class)->restore(e36_hide($c, $mod), $mod);

    expect(DatabaseNotification::query()->whereNotIn('type_key', ['comment_hidden'])->count())->toBe(0)
        ->and(DatabaseNotification::query()->where('type_key', 'comment_hidden')->count())->toBe(1);
    foreach ([$owner, $liker, $reporter, $mod] as $u) {
        expect(e36_notes($u))->toHaveCount(0);
    }
});

test('E36-076: a failing notification system never rolls back or blocks the comment, the reply or the moderation action', function () {
    $owner = e28_creator();
    $subject = e36_subject_or_fail(e36_work($owner));
    [$author, $replier] = e36_users(2);
    $root = e36_say($author, $subject, 'جذر قبل العطل');

    $this->mock(NotificationDispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('notifications down')));

    $reply = e36_say($replier, $subject, 'ردّ أثناء عطل الإشعارات', $root);
    $second = e36_say($replier, $subject, 'تعليق ثانٍ أثناء العطل');
    expect($reply->isLive())->toBeTrue()->and($second->isLive())->toBeTrue()->and(DiscussionComment::query()->count())->toBe(3)->and((int) e36_thread($subject)->comments_count)->toBe(3);

    $hidden = e36_hide($reply);
    expect($hidden->status)->toBe('moderation_hidden')->and((int) e36_thread($subject)->comments_count)->toBe(2)->and(e36_audit('comment_hidden'))->not->toBeNull();

    $this->actingAs($replier)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $subject->publicId]), ['body' => e36_text()])->assertRedirect()->assertSessionMissing('discussion_error');
    expect(DiscussionComment::query()->count())->toBe(4);
});

test('E36-077: a moderation-hidden comment notifies its author with the closed reason label only (no body, no moderator, no internal note)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$author, $other] = e36_users(2);
    $mod = e36_mod();
    $mod->forceFill(['name' => 'المشرف المعروف'])->save();
    $c = e36_say($author, $subject, 'نص التعليق المخالف تمامًا');
    e36_hide($c, $mod, 'harassment', 'ملاحظة داخلية حساسة للتدقيق');

    $notes = e36_notes($author, 'comment_hidden');
    expect($notes)->toHaveCount(1)->and(e36_notes($other))->toHaveCount(0);
    $data = (array) $notes->first()->data;
    $flat = json_encode($data, JSON_UNESCAPED_UNICODE);
    expect($data['title'])->toBe('أُخفي تعليقك من قبل الإشراف')->and($data['body'])->toContain(\App\Models\DiscussionCommentReport::REASONS['harassment'])->and($data['action_params'])->toBe(['comment' => $c->public_id])
        ->and($flat)->not->toContain('نص التعليق المخالف تمامًا')->and($flat)->not->toContain('المشرف المعروف')->and($flat)->not->toContain('ملاحظة داخلية حساسة للتدقيق');

    // حساب كاتب محذوف (user_id NULL): الإخفاء لا يفشل ولا إشعار.
    $orphan = e36_say(e16User(), $subject, 'تعليق صاحبه سيُحذف');
    DB::table('discussion_comments')->where('id', $orphan->id)->update(['user_id' => null]);
    $before = e36_all_notes();
    expect(e36_hide($orphan, $mod)->status)->toBe('moderation_hidden')->and(e36_all_notes())->toBe($before);

    // إخفاء ثانٍ بعد استعادة (وقت مختلف) إشعار جديد مشروع؛ والاستعادة نفسها صامتة.
    $this->travel(2)->minutes();
    app(DiscussionModerationService::class)->restore($c->fresh(), $mod);
    expect(e36_notes($author, 'comment_hidden'))->toHaveCount(1);
    $this->travel(2)->minutes();
    e36_hide($c->fresh(), $mod, 'spam');
    expect(e36_notes($author, 'comment_hidden'))->toHaveCount(2);
});

test('E36-078: anti-spam policy - one reply notification per root per window, one owner notification per thread per window, new window = new notification', function () {
    $owner = e28_creator();
    $subject = e36_subject_or_fail(e36_work($owner));
    $author = e16User();
    $repliers = e36_users(5);

    $this->travelTo(e36_align(21600, 60));
    $root1 = e36_say($author, $subject, 'جذر أول');
    $root2 = e36_say($author, $subject, 'جذر ثانٍ');
    expect(e36_notes($owner, 'new_comment_on_your_content'))->toHaveCount(1);

    foreach ($repliers as $i => $r) {
        $this->travelTo(e36_align(3600, 3600 * 0 + 60 + $i)); // داخل دلو الساعة نفسه
        e36_say($r, $subject, 'ردّ '.$i, $root1);
    }

    // عاصفة: 5 ردود على جذر واحد في نافذة واحدة = إشعار ردّ واحد للكاتب؛ والمالك إشعار واحد في نافذته لا 7.
    expect(e36_notes($author, 'comment_reply_received'))->toHaveCount(1);
    expect(e36_notes($owner, 'new_comment_on_your_content'))->toHaveCount(1);

    // جذر آخر = إشعار ردّ مستقل في النافذة نفسها.
    $this->travelTo(e36_align(3600, 90));
    e36_say($repliers[0], $subject, 'ردّ على الجذر الثاني', $root2);
    expect(e36_notes($author, 'comment_reply_received'))->toHaveCount(2);

    // نافذة جديدة (بعد ساعتين) = إشعار ردّ جديد على الجذر الأول.
    $this->travelTo(e36_align(3600, 0)->addMinutes(125));
    e36_say($repliers[1], $subject, 'ردّ لاحق في نافذة جديدة', $root1);
    expect(e36_notes($author, 'comment_reply_received'))->toHaveCount(3);

    // المالك: نافذة جديدة (بعد 7 ساعات) = إشعار جديد؛ ثم تعليقات كثيرة في الدلو نفسه تبقى إشعارًا واحدًا.
    $this->travelTo(e36_align(21600, 0)->addHours(13));
    $before = e36_notes($owner, 'new_comment_on_your_content')->count();
    foreach (e36_users(6) as $i => $u) {
        $this->travelTo(e36_align(21600, 120 + $i));
        e36_say($u, $subject, 'عاصفة تعليقات '.$i);
    }

    expect(e36_notes($owner, 'new_comment_on_your_content')->count() - $before)->toBe(1);

    // الإرسال المكرر المباشر بالمفتاح نفسه: Duplicate لا خطأ ولا صف ثانٍ.
    $row = e36_notes($owner, 'new_comment_on_your_content')->last();
    $dup = app(NotificationDispatcher::class)->dispatch($owner, \App\Services\Notifications\NotificationType::NewCommentOnYourContent, ['title' => 'x'], (string) $row->idempotency_key);
    expect($dup)->toBe(\App\Services\Notifications\DispatchResult::Duplicate);
    $this->travelBack();
});
