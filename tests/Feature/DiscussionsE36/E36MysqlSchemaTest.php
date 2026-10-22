<?php

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Models\User;
use App\Services\Discussions\DiscussionCleanup;
use App\Services\Discussions\DiscussionCommentService;
use App\Services\Discussions\DiscussionLikeService;
use App\Services\Discussions\DiscussionReconciler;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Discussions\DiscussionThreadService;
use App\Services\Engagement\EngagementCounters;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| E36 AN (100-106) - حدود السلامة في القاعدة نفسها. كلها سائق-محايدة: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (مهمة mysql-parity في CI).
| تثبت أن الفرادة/القيود/المفاتيح الأجنبية/العدّادات الذرية لا تعتمد على كود التطبيق وحده، وأن النص العربي يعبر القاعدة سالمًا.
*/

beforeEach(fn () => e36_seed());

/** صف تعليق خام (يتجاوز الخدمة عمدًا لاختبار القيد وحده). */
function e36_raw_comment(int $threadId, ?int $userId, ?int $parentId = null, string $body = 'نص خام', array $over = []): int
{
    return DB::table('discussion_comments')->insertGetId($over + [
        'public_id' => (string) Str::ulid(), 'discussion_thread_id' => $threadId, 'user_id' => $userId, 'parent_id' => $parentId, 'body' => $body,
        'status' => 'visible', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function e36_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

test('E36-100: a thread is lazily created exactly once per subject - unique in the database, race-safe in the service, distinct per type', function () {
    $work = e36_work();
    $subject = e36_subject_or_fail($work);
    $svc = app(DiscussionThreadService::class);

    $first = $svc->firstOrCreate($subject);
    $again = $svc->firstOrCreate($subject);
    expect($again->id)->toBe($first->id)->and(DiscussionThread::query()->count())->toBe(1);

    // الفرادة بقيد القاعدة لا بالخدمة.
    expect(e36_violates(fn () => DB::table('discussion_threads')->insert(['subject_type' => $subject->type, 'subject_id' => $subject->id, 'created_at' => now(), 'updated_at' => now()])))->toBeTrue();

    // الفرادة مركّبة (النوع + المعرّف): المعرّف الرقمي نفسه بنوع آخر لا يتصادم.
    expect(e36_violates(fn () => DB::table('discussion_threads')->insert(['subject_type' => 'creator_series', 'subject_id' => $subject->id, 'created_at' => now(), 'updated_at' => now()])))->toBeFalse()
        ->and(DiscussionThread::query()->count())->toBe(2);

    // سباق: يُدرَج صف النقاش من «عملية أخرى» بين findFor والإنشاء؛ الخاسر يعيد قراءة صف الفائز ولا يرمي ولا يكرّر.
    DB::table('discussion_threads')->where('subject_type', 'creator_series')->delete(); // صف التصادم المركّب أعلاه (المعرّف الرقمي نفسه بنوع آخر)
    $series = e36_subject_or_fail(e36_series());
    $winner = null;
    DiscussionThread::creating(function () use ($series, &$winner) {
        static $once = false;

        if (! $once) {
            $once = true;
            $winner = DB::table('discussion_threads')->insertGetId(['subject_type' => $series->type, 'subject_id' => $series->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $loser = $svc->firstOrCreate($series);
    expect($winner)->not->toBeNull()->and($loser->id)->toBe($winner)->and(DiscussionThread::query()->where('subject_type', $series->type)->where('subject_id', $series->id)->count())->toBe(1);
});

test('E36-101: a duplicate like is impossible at the database level; the service is idempotent; rows cascade with the comment', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$author, $liker] = e36_users(2);
    $c = e36_say($author, $subject);
    app(DiscussionLikeService::class)->like($liker, $c->public_id);

    expect(e36_violates(fn () => DB::table('discussion_comment_likes')->insert(['user_id' => $liker->id, 'discussion_comment_id' => $c->id, 'created_at' => now()])))->toBeTrue()
        ->and(DB::table('discussion_comment_likes')->count())->toBe(1)
        ->and(app(DiscussionLikeService::class)->like($liker, $c->public_id)['changed'])->toBeFalse()->and((int) $c->fresh()->likes_count)->toBe(1);

    // مفتاح أجنبي: لا إعجاب لمستخدم أو تعليق غير موجود.
    expect(e36_violates(fn () => DB::table('discussion_comment_likes')->insert(['user_id' => 999999, 'discussion_comment_id' => $c->id, 'created_at' => now()])))->toBeTrue()
        ->and(e36_violates(fn () => DB::table('discussion_comment_likes')->insert(['user_id' => $liker->id, 'discussion_comment_id' => 999999, 'created_at' => now()])))->toBeTrue();

    DB::table('discussion_comments')->where('id', $c->id)->delete();
    expect(DB::table('discussion_comment_likes')->count())->toBe(0);
});

test('E36-102: only one open report per (comment, reporter) is possible; closed ones repeat; different reporters coexist; a null reporter never blocks', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$author, $r1, $r2] = e36_users(3);
    $c = e36_say($author, $subject);
    $svc = app(DiscussionReportService::class);
    $first = $svc->report($r1, $c->public_id, 'spam');
    $row = fn (int $reporter, ?int $marker, string $status = 'open') => ['discussion_comment_id' => $c->id, 'reporter_id' => $reporter, 'reason' => 'spam', 'status' => $status, 'open_marker' => $marker, 'body_snapshot' => 'x', 'created_at' => now(), 'updated_at' => now()];

    expect(e36_violates(fn () => DB::table('discussion_comment_reports')->insert($row($r1->id, 1))))->toBeTrue()
        ->and(e36_violates(fn () => DB::table('discussion_comment_reports')->insert($row($r2->id, 1))))->toBeFalse();

    // مغلقة (open_marker NULL) تتكرر بلا حدّ: NULL لا يتصادم في الفهرس الفريد على SQLite وMySQL.
    expect(e36_violates(fn () => DB::table('discussion_comment_reports')->insert($row($r1->id, null, 'dismissed'))))->toBeFalse()
        ->and(e36_violates(fn () => DB::table('discussion_comment_reports')->insert($row($r1->id, null, 'dismissed'))))->toBeFalse();

    // خدمة: بلاغ مكرر مفتوح يُرفض برسالة، لا بخطأ قاعدة.
    expect(e36_error(fn () => $svc->report($r1, $c->public_id, 'harassment')))->not->toBeNull()->and($first->fresh()->status)->toBe('open');

    // مبلِّغ محذوف (reporter_id NULL): البلاغان يبقيان ولا يتصادمان.
    DB::table('discussion_comment_reports')->where('reporter_id', $r2->id)->update(['reporter_id' => null]);
    expect(e36_violates(fn () => DB::table('discussion_comment_reports')->insert(['reporter_id' => null] + $row($r2->id, 1))))->toBeFalse();
});

test('E36-103: the parent/thread integrity is enforced by a composite foreign key; replies cascade; depth anomalies are detected', function () {
    $a = e36_subject_or_fail(e36_work());
    $b = e36_subject_or_fail(e36_work());
    $user = e16User();
    $rootA = e36_say($user, $a);
    $rootB = e36_say($user, $b);
    $threadA = (int) $rootA->discussion_thread_id;
    $threadB = (int) $rootB->discussion_thread_id;

    // ردّ يتبع أبًا من نقاش آخر: مرفوض بالقاعدة حتى لو كُسرت الخدمة.
    expect(e36_violates(fn () => e36_raw_comment($threadA, $user->id, $rootB->id)))->toBeTrue()
        ->and(e36_violates(fn () => e36_raw_comment($threadB, $user->id, $rootB->id)))->toBeFalse()
        ->and(e36_violates(fn () => e36_raw_comment($threadA, $user->id, 999999)))->toBeTrue();

    // الخدمة تسطّح الرد على ردّ إلى الجذر (عمق واحد)، والقاعدة وحدها تسمح بالعمق؛ فالمصالحة تكشف أي صف عميق.
    $reply = e36_say(e16User(), $a, e36_text(), $rootA);
    $flat = e36_say($user, $a, e36_text(), $reply);
    expect((int) $flat->parent_id)->toBe((int) $rootA->id)->and(app(DiscussionReconciler::class)->anomalies())->toBe([]);
    $deep = e36_raw_comment($threadA, $user->id, $reply->id);
    $found = collect(app(DiscussionReconciler::class)->anomalies())->where('type', 'deep_reply')->pluck('id')->all();
    expect($found)->toBe([$deep]);

    // حذف الجذر يتتابع إلى ردوده (والردود العميقة) والإعجابات والبلاغات كلها.
    app(DiscussionLikeService::class)->like(e16User(), $reply->public_id);
    app(DiscussionReportService::class)->report(e16User(), $reply->public_id, 'spam');
    DB::table('discussion_comments')->where('discussion_thread_id', $threadA)->whereNotNull('parent_id')->orderByDesc('id')->get()->each(fn ($r) => DB::table('discussion_comments')->where('id', $r->id)->delete());
    DB::table('discussion_comments')->where('id', $rootA->id)->delete();
    expect(DB::table('discussion_comments')->where('discussion_thread_id', $threadA)->count())->toBe(0)->and(DB::table('discussion_comment_likes')->count())->toBe(0)->and(DB::table('discussion_comment_reports')->count())->toBe(0);

    // حذف النقاش يتتابع إلى كل تعليقاته.
    e36_say(e16User(), $b, e36_text(), $rootB);
    DB::table('discussion_comments')->where('discussion_thread_id', $threadB)->whereNotNull('parent_id')->delete();
    DB::table('discussion_threads')->where('id', $threadB)->delete();
    expect(DB::table('discussion_comments')->where('discussion_thread_id', $threadB)->count())->toBe(0);
});

test('E36-104: counters are atomic SQL updates (stale instances never lose a count; underflow clamps at zero); reconcile repairs drift', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$u1, $u2, $u3, $author] = e36_users(4);
    $svc = app(DiscussionCommentService::class);

    // «عمليتان متزامنتان»: كل منهما تحمل نسخة قديمة من النقاش (comments_count=0) ثم تكتب؛ العدّاد يُحدَّث بتعبير SQL ذري لا بقيمة الذاكرة.
    $staleA = app(DiscussionThreadService::class)->firstOrCreate($subject);
    $staleB = DiscussionThread::query()->find($staleA->id);
    $root = $svc->create($u1, $subject, e36_text());
    $svc->create($u2, $subject, e36_text());
    $svc->create($u3, $subject, e36_text());
    expect((int) $staleA->comments_count)->toBe(0)->and((int) $staleB->comments_count)->toBe(0)->and((int) e36_thread($subject)->comments_count)->toBe(3);

    $staleComment1 = DiscussionComment::query()->find($root->id);
    $staleComment2 = DiscussionComment::query()->find($root->id);
    app(DiscussionLikeService::class)->like($u2, $root->public_id);
    app(DiscussionLikeService::class)->like($u3, $root->public_id);
    EngagementCounters::adjust('discussion_comments', $root->id, ['likes_count' => 1]);
    expect((int) $staleComment1->likes_count)->toBe(0)->and((int) $staleComment2->likes_count)->toBe(0)->and((int) $root->fresh()->likes_count)->toBe(3);

    // انخفاض يتجاوز الصفر يُقصّ عند صفر لا يلتفّ (unsigned) ولا يسقط.
    EngagementCounters::adjust('discussion_comments', $root->id, ['likes_count' => -50]);
    EngagementCounters::adjust('discussion_threads', (int) $root->discussion_thread_id, ['comments_count' => -50]);
    expect((int) $root->fresh()->likes_count)->toBe(0)->and((int) e36_thread($subject)->comments_count)->toBe(0);

    // عمود غير مدرج في القائمة البيضاء مرفوض.
    expect(fn () => EngagementCounters::adjust('discussion_comments', $root->id, ['user_id' => 1]))->toThrow(InvalidArgumentException::class);

    // المصالحة تعيد الحقيقة (3 تعليقات، 2 إعجاب فعلي) وتكرارها لا يغيّر شيئًا.
    $dry = app(DiscussionReconciler::class)->run(true);
    expect(count($dry['drift']))->toBeGreaterThanOrEqual(2);
    app(DiscussionReconciler::class)->run(false);
    expect((int) $root->fresh()->likes_count)->toBe(2)->and((int) e36_thread($subject)->comments_count)->toBe(3)->and(app(DiscussionReconciler::class)->run(true)['drift'])->toBe([]);
});

test('E36-105: soft-delete and foreign-key policy - tombstones keep their row and reports, hard deletes cascade, deleting accounts nulls actors and leaves no attributed text', function () {
    $subject = e36_subject_or_fail(e36_work());
    [$author, $reporter, $other] = e36_users(3);
    $mod = e36_mod();
    $c = e36_say($author, $subject, 'نص سيبقى منسوبًا لو لم نحذفه');
    $keep = e36_say($other, $subject, 'تعليق شخص آخر');
    $report = app(DiscussionReportService::class)->report($reporter, $keep->public_id, 'spam');
    app(DiscussionLikeService::class)->like($author, $keep->public_id);
    app(DiscussionLikeService::class)->like($other, $c->public_id);
    e36_lock($subject, $mod, 'سبب القفل');

    // حذف ناعم: الصف باقٍ والبلاغات باقية (دليل للمشرف)، والجسم فارغ.
    app(DiscussionCommentService::class)->delete($other, $keep->public_id);
    expect(DB::table('discussion_comments')->where('id', $keep->id)->count())->toBe(1)->and($keep->fresh()->body)->toBe('')->and(DB::table('discussion_comment_reports')->where('id', $report->id)->value('body_snapshot'))->toBe('تعليق شخص آخر');

    // حذف حساب المبلِّغ: البلاغ يبقى بلا هوية.
    $reporter->delete();
    expect(DB::table('discussion_comment_reports')->where('id', $report->id)->value('reporter_id'))->toBeNull()->and(DB::table('discussion_comment_reports')->where('id', $report->id)->exists())->toBeTrue();

    // حذف حساب المشرف: الأفعال تبقى والفاعل NULL (قيد القاعدة).
    $thread = e36_thread($subject);
    expect((int) $thread->locked_by)->toBe($mod->id);
    DB::table('discussion_comment_reports')->where('id', $report->id)->update(['handled_by' => $mod->id]);
    $mod->delete();
    expect($thread->fresh()->locked_by)->toBeNull()->and($thread->fresh()->is_locked)->toBeTrue()->and(DB::table('discussion_comment_reports')->where('id', $report->id)->value('handled_by'))->toBeNull();

    // حذف حساب الكاتب: تعليقه لحد محذوف بلا نص ولا انتساب، وإعجاباته تُنقَص، والعدّادات سليمة.
    $likesBefore = (int) $keep->fresh()->likes_count;
    $author->delete();
    $mine = DB::table('discussion_comments')->where('id', $c->id)->first();
    expect($mine->body)->toBe('')->and($mine->deleted_at)->not->toBeNull()->and($mine->user_id)->toBeNull()
        ->and(DB::table('discussion_comment_likes')->where('user_id', $author->id)->count())->toBe(0)->and($likesBefore)->toBe(1)->and((int) $keep->fresh()->likes_count)->toBe(0)
        ->and(app(DiscussionReconciler::class)->run(true)['drift'])->toBe([]);

    // حذف النقاش بالتنظيف الخدمي يتتابع إلى كل ما تحته.
    app(DiscussionCleanup::class)->forSubject($subject->type, $subject->id);
    expect(DiscussionThread::query()->count())->toBe(0)->and(DiscussionComment::query()->count())->toBe(0)->and(DB::table('discussion_comment_likes')->count())->toBe(0)->and(DB::table('discussion_comment_reports')->count())->toBe(0);
});

test('E36-106: Arabic text round-trips through the database intact (diacritics, ZWNJ/ZWJ, mixed scripts, emoji-free 1000-char body, newlines)', function () {
    $subject = e36_subject_or_fail(e36_work());
    $user = e16User();
    $svc = app(DiscussionCommentService::class);

    $samples = [
        'سَأَلْتُ نَفْسِي: هل الأحجيةُ صعبةٌ حقًّا؟ نعم، ولكنها ممتعة!',
        "سطر أول\n\nسطر ثانٍ بعد فراغ — وفيه «اقتباس» و(أقواس) و[مربعة]",
        'خليط Latin و عربي مع أرقام ٠١٢٣٤٥٦٧٨٩ و 0123456789 و‌نصٍّ يحوي ZWNJ',
        'Türkçe karışık: ığüşöç İĞÜŞÖÇ ve العربية في جملة واحدة',
    ];

    foreach ($samples as $text) {
        $c = $svc->create($user, $subject, $text);
        $stored = DB::table('discussion_comments')->where('id', $c->id)->value('body');
        expect($stored)->toBe(\App\Services\Discussions\DiscussionBody::normalize($text))->and(mb_strlen($stored))->toBeGreaterThan(1);
        $this->travel(3)->minutes(); // تفادي رفض النص المكرر/المعدّل
    }

    // 1000 حرف عربي بالضبط (الحد الأقصى) يُخزَّن كاملًا وبلا قصّ بنصف حرف متعدد البايتات.
    $long = '';
    $alphabet = 'ابتثجحخدذرزسشصضطظعغفقكلمنهوي';

    while (mb_strlen($long) < 1000) {
        $long .= mb_substr($alphabet, mb_strlen($long) % mb_strlen($alphabet), 1);
    }

    $big = $svc->create(e16User(), $subject, $long);
    $stored = DB::table('discussion_comments')->where('id', $big->id)->value('body');
    expect(mb_strlen($stored))->toBe(1000)->and($stored)->toBe($long)->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue();

    // ما فوق 1000 يُرفض بالخدمة قبل القاعدة (لا قصّ صامت).
    expect(e36_error(fn () => $svc->create(e16User(), $subject, $long.'ا')))->not->toBeNull();

    // الاسترجاع بالقارئ يعيد النص نفسه كما خُزِّن.
    $back = collect(app(\App\Services\Discussions\DiscussionReader::class)->forSubject($subject, null)['roots']->getCollection())->pluck('body')->all();
    expect($back)->toContain($long)->and($back)->toContain(\App\Services\Discussions\DiscussionBody::normalize($samples[0]));
    expect(Schema::hasColumn('discussion_comments', 'body'))->toBeTrue();
});
