<?php

use App\Models\DiscussionComment;
use App\Models\UserCollection;
use App\Models\UserReferral;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\Discussions\DiscussionReader;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Collections\CollectionVisibilityService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../ReferralE33/_support.php';

/*
| E36 AK11 (79-84) الخصوصية + AK12 (85-92) صفر اقتصاد/تقدّم.
| الخصوصية: لا بريد ولا بيانات إدارة ولا معرّف رقمي ولا نص محذوف/مخفي، والنقاش لا يسرّب كيانًا خاصًا. الاقتصاد: كل نشاط نقاش لا يمسّ جواهر ولا XP ولا مهام ولا سلسلة ولا إحالات ولا منافسة.
*/

beforeEach(fn () => e36_seed());

/**
 * كل ما يُقرأ علنًا عن نقاش كيان: الصفحة العامة + صفحة الردود + مخرجات القارئ (بصيغة JSON).
 * $sectionOnly: قسم النقاش وحده (نماذج بلاغات أخرى في الصفحة تحمل تسميات أسباب مشروعة؛ نقارن بنطاق النقاش فقط).
 */
function e36_public_surface($test, $work, $subject, $viewer = null, ?DiscussionComment $root = null, bool $sectionOnly = false): string
{
    $viewer ? $test->actingAs($viewer) : auth()->logout();
    $out = $test->get(e36_url($work))->getContent();

    if ($sectionOnly) {
        $at = strpos($out, 'data-discussion');
        $out = $at === false ? '' : substr($out, $at);
    }

    if ($root !== null) {
        $out .= $test->get(route('discussions.replies', ['type' => $subject->type, 'subject' => $subject->publicId, 'root' => $root->public_id]))->getContent();
    }

    return $out.json_encode(app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE);
}

/* ===================== AK11. PRIVACY ===================== */

test('E36-079: the author email never appears (page, replies page, reader, notifications, permalink target)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $author = e16User(['name' => 'صاحب تعليق خصوصي', 'email' => 'secret.author.e36@example.test']);
    $replier = e16User(['email' => 'secret.replier.e36@example.test']);
    $root = e36_say($author, $subject, 'تعليق جذري عام');
    e36_say($replier, $subject, 'ردّ عام', $root);

    foreach ([null, e16User(), $author, e36_mod()] as $viewer) {
        $surface = e36_public_surface($this, $work, $subject, $viewer, $root);
        expect($surface)->toContain('صاحب تعليق خصوصي')->and($surface)->not->toContain('secret.author.e36@example.test')->and($surface)->not->toContain('secret.replier.e36@example.test');
    }

    $notes = json_encode(DB::table('notifications')->pluck('data')->all(), JSON_UNESCAPED_UNICODE);
    expect($notes)->not->toContain('secret.author.e36@example.test')->and($notes)->not->toContain('secret.replier.e36@example.test');
});

test('E36-080: admin and moderation metadata never reach public surfaces (moderator, notes, report data, the specific reason for others)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    [$author, $reporter, $other] = e36_users(3);
    $mod = e36_mod();
    $mod->forceFill(['name' => 'المشرف السري الاسم', 'email' => 'mod.secret.e36@example.test'])->save();
    $root = e36_say($author, $subject, 'تعليق سيُبلَّغ عنه');
    $keep = e36_say($other, $subject, 'ردّ سليم يُبقي الجذر ظاهرًا', $root);
    $report = app(DiscussionReportService::class)->report($reporter, $root->public_id, 'hate_or_abuse');
    app(DiscussionModerationService::class)->actionAndHide($report, $mod, 'ملاحظة معالج حساسة');

    $forbidden = ['المشرف السري الاسم', 'mod.secret.e36@example.test', 'ملاحظة معالج حساسة', 'moderated_by', 'moderated_at', 'handler_note', 'body_snapshot', 'reporter_id', 'open_marker', 'reports_count', 'moderation_reason'];
    $label = \App\Models\DiscussionCommentReport::REASONS['hate_or_abuse'];

    foreach ([null, $other, $reporter, e28_creator()] as $viewer) {
        $surface = e36_public_surface($this, $work, $subject, $viewer, $root, true);
        foreach ($forbidden as $needle) {
            expect(str_contains($surface, $needle))->toBeFalse('leaked to public: '.$needle);
        }

        // نماذج «إبلاغ» تعرض كل الأسباب شرعيًا، فالسبب المحدّد لهذا الإخفاء يُفحص في الجذر المخفي نفسه: لحد محايد فقط.
        preg_match('/id="c-'.$root->public_id.'".*?data-comment-hidden>(.*?)<\/p>/su', $surface, $m);
        expect($m[1] ?? null)->toBe('تم إخفاء هذا التعليق من قبل الإدارة');
    }

    $reader = json_encode(app(DiscussionReader::class)->forSubject($subject, $other)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE);
    expect($reader)->not->toContain('hate_or_abuse')->and($reader)->not->toContain($label)->and($reader)->not->toContain('harassment');

    // الكاتب لا يرى هوية المبلِّغ ولا المشرف ولا الملاحظة، فقط سبب الإخفاء المهيكل (انظر E36-084).
    $mine = e36_public_surface($this, $work, $subject, $author, $root, true);
    expect($mine)->not->toContain('المشرف السري الاسم')->and($mine)->not->toContain($reporter->name)->and($mine)->not->toContain('ملاحظة معالج حساسة')->and($keep->fresh()->isLive())->toBeTrue();
});

test('E36-081: only public ULIDs are exposed; internal sequential IDs are never needed (HTML, forms, JSON; numeric ids route nowhere)', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    [$a, $b] = e36_users(2);
    $root = e36_say($a, $subject, 'تعليق عام');
    $reply = e36_say($b, $subject, 'ردّ عام', $root);

    $html = e36_public_surface($this, $work, $subject, $b, $root);
    preg_match_all('~/discussions/comments/([^/"\'?#\s]+)~', $html, $m);
    expect($m[1])->not->toBeEmpty();
    foreach (array_unique($m[1]) as $id) {
        expect(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id))->toBe(1, 'non-ULID in a discussion link: '.$id);
    }

    expect($html)->toContain('c-'.$root->public_id)->and($html)->not->toContain('c-'.$root->id.'"')->and($html)->not->toMatch('~/discussions/[a-z_]+/'.$work->id.'/~')->and($html)->not->toMatch('~/discussions/comments/\d+(?![0-9A-Za-z])~');
    $reader = json_encode(app(DiscussionReader::class)->forSubject($subject, $b)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE);
    expect($reader)->toContain($root->public_id)->and($reader)->not->toContain('"discussion_thread_id"')->and($reader)->not->toContain('"parent_id"')->and($reader)->not->toContain('"user_id"');

    foreach ([$root->id, $reply->id, $work->id] as $numeric) {
        $this->get('/discussions/comments/'.$numeric)->assertNotFound();
        $this->actingAs($a)->post('/discussions/comments/'.$numeric.'/like')->assertNotFound();
        $this->actingAs($a)->post('/discussions/comments/'.$numeric.'/report', ['reason' => 'spam'])->assertNotFound();
    }
});

test('E36-082: a private/unlisted/hidden subject never leaks its thread (permalink, replies page, writes, listing) - even to the owner', function () {
    $owner = e16User();
    $collection = e35_collection($owner, e35_works(2), 'public', 'مجموعة ستُخفى');
    $subject = e36_subject_or_fail($collection);
    $commenter = e16User();
    $root = e36_say($commenter, $subject, 'نقاش سابق على مجموعة عامة');
    e36_say($owner, $subject, 'ردّ المالك', $root);

    foreach (['private', 'unlisted'] as $visibility) {
        app(CollectionVisibilityService::class)->set($owner, $collection->public_id, $visibility);
        $collection = $collection->fresh();
        expect(e36_subject($collection, $owner))->toBeNull();

        foreach ([null, $commenter, $owner, e36_mod()] as $viewer) {
            $viewer ? $this->actingAs($viewer) : auth()->logout();
            $this->get(route('discussions.permalink', $root->public_id))->assertNotFound();
            $this->get(route('discussions.replies', ['type' => 'public_collection', 'subject' => $collection->public_id, 'root' => $root->public_id]))->assertNotFound();
            $page = $this->get(e36_url($collection));
            expect($page->getStatusCode())->toBeIn([200, 403, 404]);
            expect($page->getContent())->not->toContain('نقاش سابق على مجموعة عامة')->and($page->getContent())->not->toContain('data-discussion');
        }

        $this->actingAs($commenter)->post(route('discussions.replies.store', $root->public_id), ['body' => 'ردّ على مجموعة غير عامة'])->assertNotFound();
        $this->actingAs($commenter)->post(route('discussions.likes.store', $root->public_id))->assertNotFound();
        $this->actingAs($commenter)->post(route('discussions.reports.store', $root->public_id), ['reason' => 'spam'])->assertNotFound();
    }

    // تعليق مخفي بإخفاء الأحجية (E29): النقاش الخامل لا يظهر ولا يُعدّ.
    $work = e36_work();
    $ws = e36_subject_or_fail($work);
    $c = e36_say($commenter, $ws, 'تعليق على أحجية ستُخفى');
    e29_hide($work);
    $this->get(route('discussions.permalink', $c->public_id))->assertNotFound();

    // استعادة الظهور العام تعيد النقاش بتاريخه.
    app(CollectionVisibilityService::class)->set($owner, $collection->public_id, 'public');
    auth()->logout();
    $this->get(route('discussions.permalink', $root->public_id))->assertRedirect();
});

test('E36-083: a deleted comment body is absent everywhere (database, page, reader, replies page, edit form) while the tombstone stays', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    [$author, $replier] = e36_users(2);
    $root = e36_say($author, $subject, 'نص سيُحذف نهائيًا من العرض');
    e36_say($replier, $subject, 'ردّ يُبقي اللحد', $root);
    app(DiscussionCommentService::class)->delete($author, $root->public_id);

    expect(DB::table('discussion_comments')->where('id', $root->id)->value('body'))->toBe('')->and($root->fresh()->deleted_at)->not->toBeNull();

    foreach ([null, $replier, $author, e36_mod()] as $viewer) {
        $surface = e36_public_surface($this, $work, $subject, $viewer, $root);
        expect($surface)->not->toContain('نص سيُحذف نهائيًا من العرض')->and($surface)->toContain('ردّ يُبقي اللحد')->and($surface)->toContain('تم حذف هذا التعليق');
    }

    $this->actingAs($author)->patch(route('discussions.comments.update', $root->public_id), ['body' => 'إحياء تعليق محذوف'])->assertRedirect()->assertSessionHas('discussion_error');
    expect(DB::table('discussion_comments')->where('id', $root->id)->value('body'))->toBe('');
    expect(DB::table('discussion_comment_reports')->count())->toBe(0);
});

test('E36-084: the moderation reason is exposed to the comment author only (never to guests, other users, the content owner, the moderator page or other viewers\' reader data)', function () {
    $owner = e28_creator();
    $work = e36_work($owner);
    $subject = e36_subject_or_fail($work);
    [$author, $other] = e36_users(2);
    $c = e36_say($author, $subject, 'تعليق مخفي بسبب');
    e36_say($other, $subject, 'ردّ يُبقي الجذر المخفي ظاهرًا كلحد', $c);
    e36_hide($c, null, 'harassment');
    $label = \App\Models\DiscussionCommentReport::REASONS['harassment'];

    // نماذج الإبلاغ على الردّ الحيّ تحوي كل التسميات شرعيًا؛ المقياس هو فقرة الجذر المخفي نفسه ومخرجات القارئ.
    foreach ([null, $other, $owner, e36_mod()] as $viewer) {
        $surface = e36_public_surface($this, $work, $subject, $viewer, $c, true);
        preg_match('/id="c-'.$c->public_id.'".*?data-comment-hidden>(.*?)<\/p>/su', $surface, $m);
        expect($m[1] ?? '')->not->toContain($label)->and($m[1] ?? '')->not->toContain('harassment')->and($m[1] ?? '')->not->toBe('');
        expect(json_encode(app(DiscussionReader::class)->forSubject($subject, $viewer)['roots']->getCollection()->all(), JSON_UNESCAPED_UNICODE))->not->toContain($label)->and($surface)->not->toContain('تعليق مخفي بسبب');
    }

    $mine = e36_public_surface($this, $work, $subject, $author, $c, true);
    preg_match('/id="c-'.$c->public_id.'".*?data-comment-hidden>(.*?)<\/p>/su', $mine, $m);
    expect($m[1] ?? '')->toContain($label)->and($mine)->not->toContain('تعليق مخفي بسبب');

    // سبب آخر لتعليق ثانٍ: لا يتسرب لغير الكاتب أيضًا.
    $c2 = e36_say($author, $subject, 'تعليق آخر مخفي');
    e36_say($other, $subject, 'ردّ ثانٍ حيّ', $c2);
    e36_hide($c2, null, 'copyright');
    $theirs = e36_public_surface($this, $work, $subject, $other, $c2, true);
    preg_match('/id="c-'.$c2->public_id.'".*?data-comment-hidden>(.*?)<\/p>/su', $theirs, $m2);
    expect($m2[1] ?? '')->not->toContain(\App\Models\DiscussionCommentReport::REASONS['copyright'])->and($m2[1] ?? '')->not->toBe('');
});

/* ===================== AK12. ECONOMY / PROGRESSION ===================== */

/** يشغّل نشاط نقاش واسعًا من $actor وعليه ويعيد لقطات (قبل/بعد) للعالم الاقتصادي. */
function e36_flurry($test, $actor, $other, $subject): void
{
    $svc = app(DiscussionCommentService::class);
    $root = $svc->create($actor, $subject, e36_text(), null);
    $reply = $svc->create($actor, $subject, e36_text(), $root->public_id);
    $theirs = $svc->create($other, $subject, e36_text(), null);
    $svc->edit($actor, $root->public_id, 'تعديل سريع جدًا');
    app(DiscussionLikeService::class)->like($actor, $theirs->public_id);
    app(DiscussionLikeService::class)->unlike($actor, $theirs->public_id);
    app(DiscussionLikeService::class)->like($other, $reply->public_id);
    app(DiscussionReportService::class)->report($actor, $theirs->public_id, 'spam');
    $mod = e36_mod();
    e36_hide($theirs->fresh(), $mod);
    app(DiscussionModerationService::class)->restore($theirs->fresh(), $mod);
    e36_lock($subject, $mod);
    app(DiscussionModerationService::class)->unlockThread(e36_thread($subject), $mod);
    $svc->delete($actor, $reply->public_id);
}

test('E36-085: a comment grants zero gems or currency (wallet, ledgers, balances identical)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $before = e36_world($a, $b);

    e36_say($a, $subject);
    $this->actingAs($b)->post(route('discussions.comments.store', ['type' => 'community_puzzle', 'subject' => $subject->publicId]), ['body' => e36_text()]);

    $after = e36_world($a, $b);
    expect($after)->toBe($before)->and(DB::table('currency_transactions')->count())->toBe(0);
});

test('E36-086: a reply grants zero gems or currency (to the replier and to the root author)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $root = e36_say($a, $subject);
    $before = e36_world($a, $b);

    e36_say($b, $subject, e36_text(), $root);
    $this->actingAs($b)->post(route('discussions.replies.store', $root->public_id), ['body' => e36_text()]);

    expect(e36_world($a, $b))->toBe($before);
});

test('E36-087: likes (given and received) grant zero gems or currency and move no ledger', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $c = e36_say($a, $subject);
    $before = e36_world($a, $b);

    app(DiscussionLikeService::class)->like($b, $c->public_id);
    $this->actingAs($b)->delete(route('discussions.likes.destroy', $c->public_id));
    $this->actingAs($b)->post(route('discussions.likes.store', $c->public_id));

    expect(e36_world($a, $b))->toBe($before)->and((int) $c->fresh()->likes_count)->toBe(1);
});

test('E36-088: discussion activity grants zero XP and moves no level', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $before = e36_world($a, $b);

    e36_flurry($this, $a, $b, $subject);

    $after = e36_world($a, $b);
    expect($after)->toBe($before)->and(DB::table('xp_transactions')->count())->toBe(0);
    foreach ([$a, $b] as $u) {
        expect($after['per_user'][$u->id]['xp'])->toBe($before['per_user'][$u->id]['xp']);
    }
});

test('E36-089: discussion activity never progresses a quest (counts and values unchanged, even with an active daily quest)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $before = e36_world($a, $b);
    $quest = DB::table('user_quest_progress')->count();

    e36_flurry($this, $a, $b, $subject);

    expect(e36_world($a, $b))->toBe($before)->and(DB::table('user_quest_progress')->count())->toBe($quest)->and((int) DB::table('user_quest_progress')->sum('current_value'))->toBe(0);
});

test('E36-090: discussion activity never touches the streak (no row created, no day counted)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $before = e36_world($a, $b);

    e36_flurry($this, $a, $b, $subject);
    $this->travel(1)->days();
    e36_say($a, $subject, 'تعليق في اليوم التالي');

    expect(e36_world($a, $b))->toBe($before)->and(DB::table('player_streaks')->count())->toBe(0);
});

test('E36-091: discussion activity never qualifies or rewards a referral (a referred user who only discusses stays unqualified)', function () {
    $subject = e36_subject_or_fail(e36_work());
    $referrer = e33_user();
    $campaign = e33_reward_campaign([], true);
    [$referred, $referral] = e33_attr($referrer, $campaign);
    $other = e16User();
    $statusBefore = $referral->fresh()->status;
    $before = e36_world($referrer, $referred, $other);

    e36_flurry($this, $referred, $other, $subject);

    $after = $referral->fresh();
    expect($after->status)->toBe($statusBefore)->and($after->status)->not->toBe(UserReferral::STATUS_QUALIFIED ?? 'qualified')->and(e36_world($referrer, $referred, $other))->toBe($before)
        ->and(DB::table('currency_transactions')->count())->toBe(0);

    // الشاهد الإيجابي: الإجراء الرسمي ذو المعنى (لا النقاش) هو ما يؤهِّل الإحالة، فالتحقق أعلاه حساس لا فارغ.
    expect(e33_act($referred)?->status)->not->toBe($statusBefore);
});

test('E36-092: discussion activity has no competition/LiveOps effect (no participation, result, reward grant or fraud flag)', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$a, $b] = e36_users(2);
    $before = e36_world($a, $b);

    e36_flurry($this, $a, $b, $subject);

    $after = e36_world($a, $b);
    foreach (['competitive_event_participants', 'competitive_event_results', 'competitive_reward_grants', 'user_mission_assignments', 'fraud_flags', 'redemption_requests'] as $table) {
        expect($after['counts'][$table])->toBe($before['counts'][$table]);
    }

    expect($after)->toBe($before);

    // تدقيق مصدري: خدمات النقاش لا تستورد أي خدمة اقتصاد/تقدّم/منافسة/إحالات (حتى لا تُضاف صلة لاحقًا خلسة).
    $banned = ['Gem', 'Xp', 'XP', 'Quest', 'Streak', 'Referral', 'Competit', 'LiveOps', 'Wallet', 'Currency', 'Achievement', 'Campaign', 'Redemption', 'Progression', 'Reward'];
    $files = array_merge(glob(app_path('Services/Discussions/*.php')), glob(app_path('Http/Controllers/Discussions/*.php')), glob(app_path('Models/Discussion*.php')));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        preg_match_all('/^use\s+([^;]+);/m', $code, $uses);
        foreach ($uses[1] as $import) {
            foreach ($banned as $word) {
                expect(str_contains($import, $word))->toBeFalse(basename($file).' imports '.$import);
            }
        }
    }
});
