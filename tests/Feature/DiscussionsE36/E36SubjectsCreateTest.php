<?php

use App\Models\DiscussionComment;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionThreadService;
use App\Services\Engagement\EngagementException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E36 AK1 (1-10) الموضوع والنقاش + AK2 (11-21) إنشاء التعليق.
| الموضوع يُحلّ فقط بالسجل المركزي (أحجية مجتمع ظاهرة / سلسلة ظاهرة / مجموعة عامة). النقاش كسول، واحد لكل كيان، ولا يُسرَّب لكيان غير ظاهر.
*/

beforeEach(fn () => e36_seed());

/* ===================== AK1. THREAD / SUBJECT ===================== */

test('E36-001: a visible community puzzle hosts a discussion (comment, thread, public page)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق على أحجية المجتمع'])->assertRedirect();

    $thread = e36_thread($subject);
    expect($thread)->not->toBeNull()->and($thread->subject_type)->toBe('community_puzzle')->and((int) $thread->subject_id)->toBe($work->id)->and((int) $thread->comments_count)->toBe(1);

    $html = $this->get(e36_url($work))->assertOk()->getContent();
    expect($html)->toContain('data-discussion')->and($html)->toContain('تعليق على أحجية المجتمع');
});

test('E36-002: a hidden community puzzle has no discussion (subject unavailable, writes 404, page 404, thread dormant)', function () {
    $work = e36_work();
    $user = e16User();
    e36_say($user, e36_subject_or_fail($work), 'تعليق قبل الإخفاء');

    e29_hide($work);

    expect(e36_subject($work->fresh()))->toBeNull();
    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق بعد الإخفاء'])->assertNotFound();
    $this->get(e36_url($work))->assertNotFound();
    expect(DiscussionComment::query()->count())->toBe(1);

    // الاستعادة تعيد النقاش بتعليقاته (تاريخ محفوظ).
    e29_unhide($work->fresh());
    $this->get(e36_url($work))->assertOk()->assertSee('تعليق قبل الإخفاء');
});

test('E36-003: a visible creator series hosts a discussion', function () {
    $series = e36_series();
    $subject = e36_subject_or_fail($series);
    $user = e16User();

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'creator_series', 'subject' => $series->public_id]), ['body' => 'سلسلة رائعة ومتدرجة'])->assertRedirect();

    expect(e36_thread($subject)->comments_count)->toBe(1);
    $this->get(e36_url($series))->assertOk()->assertSee('سلسلة رائعة ومتدرجة');
});

test('E36-004: a hidden creator series has no discussion', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works)->fresh();
    $user = e16User();
    e36_say($user, e36_subject_or_fail($series), 'تعليق قبل الإخفاء');

    e30s_hide_work($works[1]);
    expect(e30s_visible($series->fresh()))->toBeFalse()->and(e36_subject($series->fresh()))->toBeNull();

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'creator_series', 'subject' => $series->public_id]), ['body' => 'تعليق بعد الإخفاء'])->assertNotFound();
    $this->get(e36_url($series))->assertNotFound();
    expect(DiscussionComment::query()->count())->toBe(1);
});

test('E36-005: a public collection hosts a discussion', function () {
    $collection = e35_public();
    $subject = e36_subject_or_fail($collection);
    $user = e16User();

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'public_collection', 'subject' => $collection->public_id]), ['body' => 'مجموعة مرتبة بعناية'])->assertRedirect();

    expect(e36_thread($subject)->comments_count)->toBe(1);
    $this->get(e36_url($collection))->assertOk()->assertSee('مجموعة مرتبة بعناية');
});

test('E36-006: a private collection has no discussion (owner and others alike)', function () {
    $owner = e16User();
    $collection = e35_collection($owner, e35_works(2), 'private', 'خاصة بي');

    expect(e36_subject($collection, $owner))->toBeNull()->and(e36_subject($collection, e16User()))->toBeNull()->and(e36_subject($collection))->toBeNull();
    $this->actingAs($owner)->post(route('discussions.comments.store', ['type' => 'public_collection', 'subject' => $collection->public_id]), ['body' => 'محاولة على مجموعة خاصة'])->assertNotFound();
    expect(DiscussionThread::query()->count())->toBe(0);
});

test('E36-007: an unlisted collection hosts no discussion (policy: link-shared pages stay comment-free) yet the page itself still opens', function () {
    $owner = e16User();
    $collection = e35_collection($owner, e35_works(2), 'unlisted', 'للمشاركة بالرابط');

    expect(e36_subject($collection, $owner))->toBeNull()->and(e36_subject($collection))->toBeNull();
    $this->actingAs(e16User())->post(route('discussions.comments.store', ['type' => 'public_collection', 'subject' => $collection->public_id]), ['body' => 'تعليق على مجموعة غير مدرجة'])->assertNotFound();

    $html = $this->get(e36_url($collection))->assertOk()->getContent();
    expect($html)->not->toContain('data-discussion')->and(DiscussionThread::query()->count())->toBe(0);
});

test('E36-008: invalid subject types are rejected (unknown type, class names, official_puzzle, malformed ids) and never reach a model', function () {
    $user = e16User();
    $work = e36_work();

    foreach (['official_puzzle', 'user', 'App-Models-User', 'community_puzzles', 'COMMUNITY_PUZZLE'] as $type) {
        $this->actingAs($user)->post('/discussions/'.$type.'/'.$work->public_id.'/comments', ['body' => 'نوع غير مدعوم'])->assertNotFound();
    }

    $this->actingAs($user)->post('/discussions/community_puzzle/not-a-ulid/comments', ['body' => 'معرّف تالف'])->assertNotFound();
    $this->actingAs($user)->post('/discussions/community_puzzle/'.$work->id.'/comments', ['body' => 'معرّف رقمي داخلي'])->assertNotFound();

    $registry = e36_registry();
    expect($registry->supports('official_puzzle'))->toBeFalse()->and($registry->supports(User::class))->toBeFalse()->and($registry->supports('community_puzzle'))->toBeTrue()
        ->and($registry->resolve('official_puzzle', $work->public_id, null))->toBeNull()
        ->and($registry->resolve('community_puzzle', (string) $work->id, null))->toBeNull()
        ->and($registry->types())->toBe(['community_puzzle', 'creator_series', 'public_collection'])
        ->and(DiscussionThread::query()->count())->toBe(0);
});

test('E36-009: exactly one thread per subject (service-level reuse and the database unique constraint)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);

    e36_say(e16User(), $subject);
    e36_say(e16User(), $subject);
    e36_say(e16User(), $subject);
    expect(DiscussionThread::query()->count())->toBe(1);

    $other = e36_subject_or_fail(e36_work());
    e36_say(e16User(), $other);
    expect(DiscussionThread::query()->count())->toBe(2);

    expect(fn () => DB::table('discussion_threads')->insert(['subject_type' => $subject->type, 'subject_id' => $subject->id, 'comments_count' => 0, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

test('E36-010: threads are created lazily (viewing, failed or rejected writes create nothing; the first accepted comment creates the thread)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();

    $this->get(e36_url($work))->assertOk();
    $this->actingAs($user)->get(e36_url($work))->assertOk()->assertSee('كن أول من يبدأ النقاش');
    expect(DiscussionThread::query()->count())->toBe(0);

    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, '')))->not->toBeNull();
    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, '<b>x</b>')))->not->toBeNull();
    expect(DiscussionThread::query()->count())->toBe(0);

    e36_say($user, $subject);
    expect(DiscussionThread::query()->count())->toBe(1)->and(app(DiscussionThreadService::class)->findFor($subject)->comments_count)->toBe(1);
});

/* ===================== AK2. COMMENT CREATE ===================== */

test('E36-011: a guest cannot comment (redirected to login, nothing written)', function () {
    $work = e36_work();
    e36_subject_or_fail($work);

    $this->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق ضيف'])->assertRedirect(route('login'));
    expect(DiscussionComment::query()->count())->toBe(0)->and(DiscussionThread::query()->count())->toBe(0);
});

test('E36-012: an unverified user cannot comment (route and service)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User(['email_verified_at' => null]);

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق غير موثَّق'])->assertRedirect(route('verification.notice'));
    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, 'تعليق غير موثَّق')))->not->toBeNull()->and(DiscussionComment::query()->count())->toBe(0);
});

test('E36-013: a frozen user cannot comment, even if frozen after the session started (live read)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();
    $stale = $user->replicate();
    $stale->id = $user->id;

    DB::table('users')->where('id', $user->id)->update(['is_frozen' => true]);

    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($stale, $subject, 'حساب مجمَّد الآن')))->not->toBeNull();
    $this->actingAs($user->fresh())->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'حساب مجمَّد'])->assertRedirect();
    expect(DiscussionComment::query()->count())->toBe(0);
});

test('E36-014: an active verified user can comment (stored with the actor, visible, counted, redirected to the permalink)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();

    $response = $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => "  تعليق\n\n\n\nمن   لاعب  "]);
    $comment = DiscussionComment::query()->firstOrFail();

    $response->assertRedirect(route('discussions.permalink', $comment->public_id))->assertSessionHas('discussion_status');
    expect((int) $comment->user_id)->toBe($user->id)->and($comment->status)->toBe('visible')->and($comment->parent_id)->toBeNull()->and($comment->deleted_at)->toBeNull()
        ->and($comment->body)->toBe("تعليق\n\nمن لاعب")->and((int) $comment->likes_count)->toBe(0)->and(strlen($comment->public_id))->toBe(26)
        ->and((int) e36_thread($subject)->comments_count)->toBe(1);

    $this->actingAs($user)->get(route('discussions.permalink', $comment->public_id))->assertRedirect(e36_url($work).'#c-'.$comment->public_id);
});

test('E36-015: empty or meaningless text is rejected (route validation and service)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();
    $url = route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]);

    $this->actingAs($user)->post($url, ['body' => ''])->assertSessionHasErrorsIn('discussion', 'body');
    $this->actingAs($user)->post($url, [])->assertSessionHasErrorsIn('discussion', 'body');

    foreach (['   ', "\n\n", 'أ', '!!!', '...؟؟؟'] as $bad) {
        expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, $bad)))->not->toBeNull($bad);
    }

    expect(DiscussionComment::query()->count())->toBe(0)->and(e36_say($user, $subject, 'نص صالح')->body)->toBe('نص صالح');
});

test('E36-016: oversized text is rejected; exactly the maximum is accepted', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();
    $url = route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]);

    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, str_repeat('ا', 1001))))->not->toBeNull();
    $this->actingAs($user)->post($url, ['body' => str_repeat('ا', 5001)])->assertSessionHasErrorsIn('discussion', 'body');
    $this->actingAs($user)->post($url, ['body' => str_repeat('ب', 1001)])->assertRedirect()->assertSessionHas('discussion_error');
    expect(DiscussionComment::query()->count())->toBe(0);

    // السقف الصلب 1000 حتى لو رُفع الإعداد.
    config(['discussions.body.max' => 100000]);
    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, str_repeat('ا', 1001))))->not->toBeNull();

    $ok = e36_say($user, $subject, str_repeat('ج', 1000));
    expect(mb_strlen($ok->body))->toBe(1000);
});

test('E36-017: HTML/script/markup is rejected on input and escaped on output even when inserted raw into the database; links are never auto-linked', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();

    foreach (['<script>alert(1)</script>', 'مرحبا <b>عريض</b>', '<img src=x onerror=alert(1)>', '<!-- تعليق -->نص', "نص\x00خفي", "نص \u{202E}معكوس"] as $bad) {
        expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, $bad)))->not->toBeNull(json_encode($bad));
    }

    expect(DiscussionComment::query()->count())->toBe(0);

    // إدخال خام بالقاعدة (تجاوز كل تحقق) ← العرض يهرّب.
    $ok = e36_say($user, $subject, 'نص سليم أولًا');
    DB::table('discussion_comments')->where('id', $ok->id)->update(['body' => 'خام <script>alert("xss")</script> و[رابط](javascript:alert(1)) و example.test']);
    $html = $this->get(e36_url($work))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert("xss")</script>')->and($html)->toContain('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;')
        ->and($html)->not->toContain('<a href="javascript:')->and($html)->not->toContain('href="example.test"')->and($html)->not->toContain('href="http://example.test"');
});

test('E36-018: a client-supplied user_id is ignored (the actor is always the authenticated user)', function () {
    $work = e36_work();
    e36_subject_or_fail($work);
    [$actor, $victim] = e36_users(2);

    $this->actingAs($actor)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'محاولة انتحال', 'user_id' => $victim->id, 'author_id' => $victim->id])->assertRedirect();

    expect((int) DiscussionComment::query()->firstOrFail()->user_id)->toBe($actor->id);
});

test('E36-019: client-supplied moderation/status/counter/ownership fields are ignored', function () {
    $work = e36_work();
    e36_subject_or_fail($work);
    $user = e16User();

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), [
        'body' => 'محاولة حقن حقول', 'status' => 'moderation_hidden', 'likes_count' => 99, 'moderated_by' => $user->id, 'moderation_reason' => 'spam',
        'deleted_at' => now()->toDateTimeString(), 'parent_id' => 1, 'discussion_thread_id' => 999, 'edited_at' => now()->toDateTimeString(), 'public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ])->assertRedirect();

    $c = DiscussionComment::query()->firstOrFail();
    expect($c->status)->toBe('visible')->and((int) $c->likes_count)->toBe(0)->and($c->moderated_by)->toBeNull()->and($c->moderation_reason)->toBeNull()->and($c->deleted_at)->toBeNull()
        ->and($c->parent_id)->toBeNull()->and($c->edited_at)->toBeNull()->and($c->public_id)->not->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and((int) $c->discussion_thread_id)->toBe((int) DiscussionThread::query()->value('id'));

    expect(fn () => DiscussionComment::query()->create(['body' => 'x']))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('E36-020: a locked thread rejects new comments (route and service) but stays readable', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $user = e16User();
    e36_say($user, $subject, 'تعليق قبل القفل');
    e36_lock($subject);

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'تعليق بعد القفل'])->assertRedirect()->assertSessionHas('discussion_error');
    expect(e36_error(fn () => app(DiscussionCommentService::class)->create($user, $subject, 'تعليق بعد القفل')))->toContain('إغلاق')->and(DiscussionComment::query()->count())->toBe(1);

    $html = $this->actingAs($user)->get(e36_url($work))->assertOk()->getContent();
    expect($html)->toContain('تعليق قبل القفل')->and($html)->toContain('data-discussion-locked')->and($html)->not->toContain('id="discussion-body"');
});

test('E36-021: a hidden subject rejects new comments even for an existing thread, and a stale subject object cannot be used through the route', function () {
    $work = e36_work();
    $user = e16User();
    e36_say($user, e36_subject_or_fail($work), 'تعليق أول');

    e29_hide($work);

    $this->actingAs($user)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $work->public_id]), ['body' => 'بعد الإخفاء'])->assertNotFound();
    expect(DiscussionComment::query()->count())->toBe(1);
});
