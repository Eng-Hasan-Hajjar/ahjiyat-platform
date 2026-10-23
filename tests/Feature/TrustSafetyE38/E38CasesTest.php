<?php

use App\Models\ModerationCase;
use App\Models\ModerationCaseEvent;
use App\Models\ModerationCaseSource;
use App\Models\ModerationEvidence;
use App\Models\OperationalAuditLog;
use App\Services\TrustSafety\CaseService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E38 (BF2-BF4) - حالات الإشراف: الفتح والأرقام والمصادر (بلاغ واحد في حالة فعّالة واحدة)، الأدلة والملاحظات، التسلسل الزمني الإلحاقي،
| الحجز الذرّي والتعيين وحواجز التضارب، وحسم/إغلاق/إعادة فتح عبر خدمات المجال.
*/

beforeEach(fn () => e38_seed());

test('E38-18: a case gets an unguessable ULID public id and a readable SAFE-YYYY-NNNNNN number with internal SLA targets', function () {
    $case = e38_open([e38_puzzle_report()]);

    expect($case->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($case->case_number)->toBe(sprintf('SAFE-%s-%s', now()->format('Y'), str_pad((string) $case->id, 6, '0', STR_PAD_LEFT)))
        ->and($case->status)->toBe('open')->and($case->priority)->toBe('normal')
        ->and($case->first_response_due_at)->not->toBeNull()->and($case->resolution_due_at)->not->toBeNull()
        ->and($case->resolution_due_at->gt($case->first_response_due_at))->toBeTrue();
});

test('E38-19: opening a case requires the manage-cases permission', function () {
    $report = e38_puzzle_report();

    expect(fn () => e38_cases()->open(e16User(), ['community_puzzle_report:'.$report->id]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_cases()->open(e28_reviewer('support'), ['community_puzzle_report:'.$report->id]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(ModerationCase::count())->toBe(0);

    expect(e38_open([$report])->exists)->toBeTrue();
});

test('E38-20: source references are a closed allow-list - unknown types, class names, junk ids and oversized batches are rejected', function () {
    $mod = e38_mod();
    $report = e38_puzzle_report();

    foreach (['users:1', 'App\\Models\\User:1', 'community_puzzle_report:abc', 'community_puzzle_report:0', 'community_puzzle_report:-4', 'community_puzzle_report', '', 'fraud_flag:1; DROP TABLE users'] as $bad) {
        expect(e38_error(fn () => e38_cases()->open($mod, [$bad])))->not->toBeNull();
    }

    expect(e38_error(fn () => e38_cases()->open($mod, [])))->toBe('اختر بلاغًا واحدًا على الأقل.')
        ->and(e38_error(fn () => e38_cases()->open($mod, array_map(fn ($i) => 'community_puzzle_report:'.($i + 1), range(1, 51)))))->toBe('الحد الأقصى 50 بلاغًا في العملية الواحدة.')
        ->and(e38_error(fn () => e38_cases()->open($mod, ['community_puzzle_report:99999'])))->toBe('بلاغ غير موجود.')
        ->and(ModerationCase::count())->toBe(0)
        ->and($report->id)->toBeInt();
});

test('E38-21: several reports on the same subject join one case while mixed subjects are refused', function () {
    $work = e36_work(e28_creator());
    $a = e38_puzzle_report(null, $work, 'spam');
    $b = e38_puzzle_report(null, $work, 'misleading');
    $other = e38_puzzle_report();

    $case = e38_open([$a, $b]);

    expect($case->sources()->where('active_marker', 1)->count())->toBe(2)
        ->and(e38_error(fn () => e38_cases()->open(e38_mod(), [e38_ref($other), 'community_puzzle_report:'.$a->id])))->toBe('كل بلاغات الحالة يجب أن تخصّ الموضوع نفسه.');

    $mixed = e38_cases()->open(e38_mod(), [e38_ref($other)]);

    expect($mixed->id)->not->toBe($case->id);
});

test('E38-22: duplicate-source policy - a report sits in at most one active case and is released when the case ends', function () {
    $report = e38_puzzle_report();
    $first = e38_open([$report]);

    expect(e38_error(fn () => e38_cases()->open(e38_mod(), [e38_ref($report)])))->toBe('أحد البلاغات مرتبط بحالة فعّالة بالفعل.');

    $mod = e38_admin();
    e38_cases()->claim($first, $mod);
    e38_cases()->close($first->fresh(), $mod, 'فُتحت بالخطأ وأُغلقت لإعادة الفتح');

    $second = e38_cases()->open(e38_mod(), [e38_ref($report)]);

    expect($second->id)->not->toBe($first->id)
        ->and(ModerationCaseSource::query()->where('source_type', 'community_puzzle_report')->where('source_id', $report->id)->where('active_marker', 1)->count())->toBe(1)
        ->and(ModerationCaseSource::query()->where('source_type', 'community_puzzle_report')->where('source_id', $report->id)->count())->toBe(2);
});

test('E38-23: the one-active-source rule is a database constraint, not just a service check (concurrent insert is refused)', function () {
    $report = e38_puzzle_report();
    e38_open([$report]);
    $other = e38_open([e38_puzzle_report()]);
    $duplicate = fn () => (new ModerationCaseSource)->forceFill([
        'moderation_case_id' => $other->id, 'source_type' => 'community_puzzle_report', 'source_id' => $report->id, 'category' => 'spam', 'active_marker' => 1,
    ])->save();

    expect($duplicate)->toThrow(UniqueConstraintViolationException::class);

    DB::table('moderation_case_sources')->where('source_id', $report->id)->update(['active_marker' => null]);

    expect($duplicate())->toBeTrue();
});

test('E38-24: only open reports can be linked - a dismissed report and a resolved fraud signal are refused', function () {
    $dismissed = e38_puzzle_report();
    app(\App\Services\Creators\CommunityReportService::class)->dismiss($dismissed, e38_admin(), 'لا مخالفة');
    $flag = e38_fraud_flag();
    app(\App\Services\FraudDetectionService::class)->resolve($flag, e38_admin(), 'تمت المراجعة');

    expect(e38_error(fn () => e38_cases()->open(e38_mod(), [e38_ref($dismissed)])))->toBe('يمكن ربط البلاغات المفتوحة فقط.')
        ->and(e38_error(fn () => e38_cases()->open(e38_mod(), [e38_ref($flag)])))->toBe('يمكن ربط البلاغات المفتوحة فقط.');
});

test('E38-25: attach adds more reports on the same subject to an active case; foreign subjects and non-drivers are refused', function () {
    $work = e36_work(e28_creator());
    $a = e38_puzzle_report(null, $work, 'spam');
    $b = e38_puzzle_report(null, $work, 'copyright');
    $foreign = e38_puzzle_report();
    $mod = e38_mod();
    $case = e38_open([$a], $mod);
    e38_cases()->claim($case, $mod);

    e38_cases()->attach($case->fresh(), $mod, [e38_ref($b)]);

    expect($case->sources()->where('active_marker', 1)->count())->toBe(2)
        ->and(e38_error(fn () => e38_cases()->attach($case->fresh(), $mod, [e38_ref($foreign)])))->toBe('البلاغ لا يخصّ موضوع هذه الحالة.')
        ->and(e38_error(fn () => e38_cases()->attach($case->fresh(), e38_mod(), [e38_ref(e38_puzzle_report(null, $work, 'other'))])))->toBe('احجز الحالة أولًا (أو اطلب من المشرف الأعلى تعيينها لك).');
});

test('E38-26: detach releases one report for another case but never the last source', function () {
    $work = e36_work(e28_creator());
    $a = e38_puzzle_report(null, $work, 'spam');
    $b = e38_puzzle_report(null, $work, 'copyright');
    $mod = e38_admin();
    $case = e38_open([$a, $b], $mod);
    e38_cases()->claim($case, $mod);
    $rows = $case->sources()->orderBy('id')->get();

    e38_cases()->detach($case->fresh(), $mod, $rows[0]->id);

    expect(e38_error(fn () => e38_cases()->detach($case->fresh(), $mod, $rows[1]->id)))->toBe('لا يمكن فصل آخر مصدر؛ أغلق الحالة بدلًا من ذلك.')
        ->and(e38_error(fn () => e38_cases()->detach($case->fresh(), $mod, $rows[0]->id)))->toBe('المصدر غير موجود أو مفصول بالفعل.')
        ->and(ModerationCaseSource::find($rows[0]->id)->detached_at)->not->toBeNull()
        ->and(e38_cases()->open(e38_mod(), [e38_ref($a)])->exists)->toBeTrue()
        ->and(e38_events($case))->toContain('source_detached');
});

test('E38-27: suggested priority comes from the policy category or fraud severity only - popularity, count and reputation never raise it', function () {
    $hate = e38_open([e38_comment_with_report(null, null, 'hate_or_abuse')[1]]);
    $spam = e38_open([e38_puzzle_report(null, null, 'spam')]);
    $fraudHigh = e38_open([e38_fraud_flag(null, 'multiple_accounts', 'high')]);
    $fraudLow = e38_open([e38_fraud_flag(null, 'rapid_solving', 'low')]);

    $popular = e36_work(e28_creator());
    $reports = array_map(fn ($r) => e38_puzzle_report(null, $popular, $r), ['spam', 'spam', 'spam', 'spam', 'spam'][0] ? ['spam', 'misleading', 'other', 'copyright', 'inappropriate'] : []);
    $many = e38_open($reports);

    expect($hate->priority)->toBe('high')->and($hate->category)->toBe('hate_or_abuse')
        ->and($spam->priority)->toBe('normal')
        ->and($fraudHigh->priority)->toBe('high')->and($fraudHigh->category)->toBe('fraud_or_abuse')
        ->and($fraudLow->priority)->toBe('low')
        ->and($many->priority)->toBe('normal');
});

test('E38-28: an explicit valid priority is honoured with matching SLA targets; an invalid one is refused', function () {
    $urgent = e38_open([e38_puzzle_report()], null, null, 'urgent');
    $low = e38_open([e38_puzzle_report()], null, null, 'low');

    expect($urgent->priority)->toBe('urgent')
        ->and($urgent->resolution_due_at->lt($low->resolution_due_at))->toBeTrue()
        ->and(e38_error(fn () => e38_cases()->open(e38_mod(), [e38_ref(e38_puzzle_report())], null, 'critical')))->toBe('أولوية غير صالحة.');
});

test('E38-29: a content snapshot is saved as append-only evidence at open and survives later edits or deletion of the content', function () {
    $author = e16User();
    [$comment, $report] = e38_comment_with_report(null, $author, 'harassment');
    $case = e38_open([$report]);
    $snapshot = $case->evidence()->where('kind', 'content_snapshot')->first();
    $text = $snapshot->body;

    DB::table('discussion_comments')->where('id', $comment->id)->update(['body' => 'نص معدَّل بعد البلاغ']);

    expect($snapshot)->not->toBeNull()->and($snapshot->added_by)->toBeNull()->and($text)->toContain((string) $comment->body)
        ->and($case->evidence()->where('kind', 'content_snapshot')->first()->body)->toBe($text)
        ->and(fn () => $snapshot->forceFill(['body' => 'تعديل'])->save())->toThrow(LogicException::class)
        ->and(fn () => $snapshot->delete())->toThrow(LogicException::class);
});

test('E38-30: moderator notes are admin-only, plain text, bounded and append-only', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);

    $note = e38_cases()->addNote($case->fresh(), $mod, "ملاحظة داخلية أولى\nمع سطر ثانٍ");

    expect($note->kind)->toBe('moderator_note')->and($note->added_by)->toBe($mod->id)
        ->and(e38_error(fn () => e38_cases()->addNote($case->fresh(), $mod, '<b>وسم</b> محظور')))->toBe('الملاحظة نص عادي فقط (بلا وسوم أو HTML).')
        ->and(e38_error(fn () => e38_cases()->addNote($case->fresh(), $mod, 'ab')))->not->toBeNull()
        ->and(e38_error(fn () => e38_cases()->addNote($case->fresh(), $mod, str_repeat('ا', 1001))))->not->toBeNull()
        ->and(fn () => e38_cases()->addNote($case->fresh(), e16User(), 'ملاحظة من مستخدم عادي'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $note->forceFill(['body' => 'تعديل'])->save())->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
});

test('E38-31: the timeline records every step in order and its events can neither be edited nor deleted', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);
    e38_cases()->addNote($case->fresh(), $mod, 'ملاحظة للتسلسل الزمني');
    e38_cases()->changeStatus($case->fresh(), $mod, 'awaiting_user', 'بانتظار توضيح');
    e38_cases()->setPriority($case->fresh(), $mod, 'high');

    $events = $case->events()->orderBy('id')->get();

    expect($events->pluck('type')->all())->toBe(['case_opened', 'claimed', 'note_added', 'status_changed', 'priority_changed'])
        ->and($events[3]->from_value)->toBe('investigating')->and($events[3]->to_value)->toBe('awaiting_user')
        ->and(fn () => $events[0]->forceFill(['note' => 'تلاعب'])->save())->toThrow(LogicException::class)
        ->and(fn () => $events[0]->delete())->toThrow(LogicException::class)
        ->and(fn () => ModerationCaseEvent::query()->delete())->not->toThrow(Exception::class);
});

test('E38-32: case lifecycle writes the existing operational audit log (reuse, no parallel authority)', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);
    e38_cases()->close($case->fresh(), $mod, 'إغلاق لأغراض التدقيق');

    $actions = OperationalAuditLog::query()->where('subject_type', ModerationCase::class)->where('subject_id', $case->id)->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe(['moderation_case_opened', 'moderation_case_claimed', 'moderation_case_closed']);
});

test('E38-33: claiming is atomic - one winner, the stale loser is refused, the same moderator may re-claim idempotently', function () {
    $case = e38_open([e38_puzzle_report()]);
    $a = e38_mod();
    $b = e38_mod();
    $staleForB = ModerationCase::find($case->id);

    $won = e38_cases()->claim($case, $a);

    expect($won->assigned_to)->toBe($a->id)->and($won->status)->toBe('investigating')->and($won->first_responded_at)->not->toBeNull()
        ->and($staleForB->assigned_to)->toBeNull()
        ->and(e38_error(fn () => e38_cases()->claim($staleForB, $b)))->toBe('حُجزت هذه الحالة من مشرف آخر أو لم تعد فعّالة.')
        ->and(e38_cases()->claim($case, $a)->assigned_to)->toBe($a->id)
        ->and($case->events()->where('type', 'claimed')->count())->toBe(1);
});

test('E38-34: release returns the case to the queue and only its assignee may release', function () {
    $case = e38_open([e38_puzzle_report()]);
    $a = e38_mod();
    $b = e38_mod();
    e38_cases()->claim($case, $a);

    expect(e38_error(fn () => e38_cases()->release($case->fresh(), $b)))->toBe('الحالة ليست معيَّنة لك.');

    $released = e38_cases()->release($case->fresh(), $a);

    expect($released->assigned_to)->toBeNull()->and($released->status)->toBe('open')
        ->and(e38_cases()->claim($released, $b)->assigned_to)->toBe($b->id);
});

test('E38-35: reassignment is supervisor-only, the assignee needs manage-cases and must not conflict with the case', function () {
    $case = e38_open([e38_puzzle_report()]);
    $a = e38_mod();
    $admin = e38_admin();
    e38_cases()->claim($case, $a);

    expect(fn () => e38_cases()->assign($case->fresh(), e38_mod(), e38_mod()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(e38_error(fn () => e38_cases()->assign($case->fresh(), $admin, e16User())))->toBe('المشرف المختار لا يملك صلاحية إدارة الحالات.');

    $b = e38_mod();
    $moved = e38_cases()->assign($case->fresh(), $admin, $b);

    expect($moved->assigned_to)->toBe($b->id)->and($case->events()->whereIn('type', ['reassigned'])->count())->toBe(1)
        ->and(e38_cases()->assign($moved, $admin, null)->assigned_to)->toBeNull();
});

test('E38-36: self-case guard - a moderator cannot open, claim or be assigned a case about their own content or account', function () {
    $mod = e38_mod();
    $creator = e28_creator($mod);
    $work = e36_work($creator);
    $report = e38_puzzle_report(null, $work, 'spam');

    expect(e38_error(fn () => e38_cases()->open($mod, [e38_ref($report)])))->toBe('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.');

    $case = e38_open([$report], e38_admin());

    expect(e38_error(fn () => e38_cases()->claim($case, $mod)))->toBe('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.')
        ->and(e38_error(fn () => e38_cases()->assign($case, e38_admin(), $mod)))->toBe('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.');

    $ownFlag = e38_fraud_flag($mod);

    expect(e38_error(fn () => e38_cases()->open($mod, [e38_ref($ownFlag)])))->toBe('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.');
});

test('E38-37: reporter-conflict guard - a moderator who filed a report in the case cannot work it, even after the source is detached', function () {
    $mod = e38_mod();
    $work = e36_work(e28_creator());
    $own = e38_puzzle_report($mod, $work, 'spam');
    $other = e38_puzzle_report(null, $work, 'copyright');

    expect(e38_error(fn () => e38_cases()->open($mod, [e38_ref($own)])))->toBe('لا يمكنك التعامل مع حالة أنت مبلِّغ فيها.');

    $admin = e38_admin();
    $case = e38_open([$own, $other], $admin);
    $rows = $case->sources()->orderBy('id')->get();
    e38_cases()->claim($case, $admin);
    e38_cases()->detach($case->fresh(), $admin, $rows->firstWhere('reporter_id', $mod->id)->id);

    expect(e38_error(fn () => e38_cases()->claim($case->fresh(), $mod)))->toBe('لا يمكنك التعامل مع حالة أنت مبلِّغ فيها.');
});

test('E38-38: only the assignee or a supervisor drives a case - other moderators cannot change status, priority or resolve it', function () {
    $case = e38_open([e38_puzzle_report()]);
    $owner = e38_mod();
    $intruder = e38_mod();
    e38_cases()->claim($case, $owner);
    $msg = 'احجز الحالة أولًا (أو اطلب من المشرف الأعلى تعيينها لك).';

    expect(e38_error(fn () => e38_cases()->changeStatus($case->fresh(), $intruder, 'awaiting_user')))->toBe($msg)
        ->and(e38_error(fn () => e38_cases()->setPriority($case->fresh(), $intruder, 'urgent')))->toBe($msg)
        ->and(e38_error(fn () => e38_cases()->resolve($case->fresh(), $intruder, 'no_violation')))->toBe($msg)
        ->and(e38_error(fn () => e38_cases()->close($case->fresh(), $intruder, 'إغلاق غير مصرَّح به')))->toBe($msg)
        ->and(e38_cases()->setPriority($case->fresh(), e38_admin(), 'urgent')->priority)->toBe('urgent');
});

test('E38-39: status transitions are limited to the active states; resolving and closing use dedicated actions', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);

    expect(e38_error(fn () => e38_cases()->changeStatus($case->fresh(), $mod, 'resolved')))->toBe('انتقال الحالة غير مسموح؛ استخدم «حسم» أو «إغلاق».')
        ->and(e38_error(fn () => e38_cases()->changeStatus($case->fresh(), $mod, 'closed')))->toBe('انتقال الحالة غير مسموح؛ استخدم «حسم» أو «إغلاق».')
        ->and(e38_error(fn () => e38_cases()->changeStatus($case->fresh(), $mod, 'investigating')))->toBe('الحالة هي نفسها بالفعل.')
        ->and(e38_cases()->changeStatus($case->fresh(), $mod, 'awaiting_user')->status)->toBe('awaiting_user')
        ->and(e38_cases()->changeStatus($case->fresh(), $mod, 'open')->status)->toBe('open');
});

test('E38-40: resolving as no-violation dismisses the domain reports through the domain service and releases the sources', function () {
    $mod = e38_admin();
    $report = e38_puzzle_report();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);

    $resolved = e38_cases()->resolve($case->fresh(), $mod, 'no_violation', 'لا مخالفة بعد المراجعة');
    $fresh = $report->fresh();

    expect($resolved->status)->toBe('resolved')->and($resolved->resolution)->toBe('no_violation')->and($resolved->resolved_at)->not->toBeNull()
        ->and($fresh->status)->toBe('dismissed')->and($fresh->handled_by)->toBe($mod->id)->and($fresh->handler_note)->toContain($case->case_number)
        ->and($case->sources()->where('active_marker', 1)->count())->toBe(0)
        ->and($case->events()->where('type', 'resolved')->count())->toBe(1);
});

test('E38-41: resolving as action-taken needs a recorded action first and then marks the reports actioned', function () {
    [$case, $mod, $work] = e38_puzzle_case();

    expect(e38_error(fn () => e38_cases()->resolve($case->fresh(), $mod, 'action_taken')))->toBe('سجّل إجراءً أولًا أو اختر «لا مخالفة».');

    e38_apply($case, $mod, 'hide_content');
    $resolved = e38_cases()->resolve($case->fresh(), $mod, 'action_taken');
    $report = DB::table('community_puzzle_reports')->where('creator_puzzle_id', $work->id)->first();

    expect($resolved->resolution)->toBe('action_taken')->and($report->status)->toBe('actioned');
});

test('E38-42: a fraud-signal case needs the fraud permission to be resolved and the signal itself never freezes anyone', function () {
    $user = e16User();
    $user->givePermissionTo(['trust-safety.view', 'trust-safety.manage-cases']);
    $subject = e16User();
    $flag = e38_fraud_flag($subject, 'multiple_accounts', 'high');
    $case = e38_open([$flag], $user);
    e38_cases()->claim($case, $user);

    expect(e38_error(fn () => e38_cases()->resolve($case->fresh(), $user, 'no_violation')))->toBe('تحتاج صلاحية «fraud.resolve» لحسم بلاغات هذا النوع.');

    $admin = e38_admin();
    e38_cases()->assign($case->fresh(), $admin, $admin);
    e38_cases()->resolve($case->fresh(), $admin, 'no_violation');

    expect($flag->fresh()->resolved)->toBeTrue()->and($subject->fresh()->frozen_at)->toBeNull();
});

test('E38-43: a case manager without the domain permission cannot resolve that source type and nothing changes', function () {
    $user = e16User();
    $user->givePermissionTo(['trust-safety.view', 'trust-safety.manage-cases']);
    $report = e38_collection_report();
    $case = e38_open([$report], $user);
    e38_cases()->claim($case, $user);

    expect(e38_error(fn () => e38_cases()->resolve($case->fresh(), $user, 'no_violation')))->toBe('تحتاج صلاحية «collections.moderate» لحسم بلاغات هذا النوع.')
        ->and($report->fresh()->status)->toBe('open')->and($case->fresh()->status)->toBe('investigating')
        ->and($case->sources()->where('active_marker', 1)->count())->toBe(1);
});

test('E38-44: administrative close needs a reason, releases the sources and leaves domain reports untouched', function () {
    $mod = e38_admin();
    $report = e38_puzzle_report();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);

    expect(e38_error(fn () => e38_cases()->close($case->fresh(), $mod, 'قصير')))->not->toBeNull();

    $closed = e38_cases()->close($case->fresh(), $mod, 'فُتحت الحالة بالخطأ وتُغلق إداريًا');

    expect($closed->status)->toBe('closed')->and($closed->closed_at)->not->toBeNull()
        ->and($report->fresh()->status)->toBe('open')
        ->and($case->sources()->where('active_marker', 1)->count())->toBe(0)
        ->and(e38_error(fn () => e38_cases()->close($closed, $mod, 'إغلاق مكرر لا يجوز')))->toBe('الحالة مغلقة بالفعل.');
});

test('E38-45: a closed or resolved case is read-only for status changes and notes; only a supervisor may reopen it', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);
    $closed = e38_cases()->close($case->fresh(), $mod, 'إغلاق إداري بسبب التكرار');

    expect(e38_error(fn () => e38_cases()->addNote($closed, $mod, 'ملاحظة بعد الإغلاق')))->toBe('الحالة مغلقة.')
        ->and(e38_error(fn () => e38_cases()->changeStatus($closed, $mod, 'awaiting_user')))->toBe('الحالة غير فعّالة.')
        ->and(e38_error(fn () => e38_cases()->setPriority($closed, $mod, 'urgent')))->toBe('الحالة غير فعّالة.')
        ->and(fn () => e38_cases()->reopen($closed, e38_mod(), 'محاولة إعادة فتح بلا صلاحية'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $reopened = e38_cases()->reopen($closed, $mod, 'ظهرت معلومات جديدة تستدعي المراجعة');

    expect($reopened->status)->toBe('open')->and($reopened->resolution)->toBeNull()->and($reopened->closed_at)->toBeNull()
        ->and($reopened->events()->where('type', 'reopened')->count())->toBe(1);
});

test('E38-46: reopening is refused when a source was meanwhile linked to another active case', function () {
    $mod = e38_admin();
    $report = e38_puzzle_report();
    $first = e38_open([$report], $mod);
    e38_cases()->claim($first, $mod);
    $closed = e38_cases()->close($first->fresh(), $mod, 'إغلاق إداري مؤقت للاختبار');
    e38_open([$report], $mod);

    expect(e38_error(fn () => e38_cases()->reopen($closed, $mod, 'محاولة إعادة فتح مع ازدواج مصدر')))->not->toBeNull()
        ->and($closed->fresh()->status)->toBe('closed');
});

test('E38-47: work-queue views (open, mine, unassigned, urgent, overdue, resolved) filter correctly with accurate counts', function () {
    $a = e38_admin();
    $mine = e38_open([e38_puzzle_report()], $a);
    e38_cases()->claim($mine, $a);
    $free = e38_open([e38_puzzle_report()], $a);
    $urgent = e38_open([e38_puzzle_report()], $a, null, 'urgent');
    $overdue = e38_open([e38_puzzle_report()], $a);
    DB::table('moderation_cases')->where('id', $overdue->id)->update(['resolution_due_at' => now()->subHour(), 'first_response_due_at' => now()->subHours(3)]);
    $done = e38_open([e38_puzzle_report()], $a);
    e38_cases()->claim($done, $a);
    e38_cases()->resolve($done->fresh(), $a, 'no_violation');

    $ids = fn (string $view) => collect(e38_cases()->queue($a, ['view' => $view])->items())->pluck('id')->sort()->values()->all();
    $counts = e38_cases()->queueCounts($a);

    expect($ids('open'))->toBe(collect([$mine, $free, $urgent, $overdue])->pluck('id')->sort()->values()->all())
        ->and($ids('mine'))->toBe([$mine->id])
        ->and($ids('unassigned'))->toBe(collect([$free, $urgent, $overdue])->pluck('id')->sort()->values()->all())
        ->and($ids('urgent'))->toBe([$urgent->id])
        ->and($ids('overdue'))->toBe([$overdue->id])
        ->and($ids('resolved'))->toBe([$done->id])
        ->and($counts)->toBe(['open' => 4, 'mine' => 1, 'unassigned' => 3, 'urgent' => 1, 'overdue' => 1]);
});

test('E38-48: the queue orders by priority then age - never by number of reports or popularity', function () {
    $a = e38_admin();
    $normalOld = e38_open([e38_puzzle_report()], $a);
    $urgent = e38_open([e38_puzzle_report()], $a, null, 'urgent');
    $popular = e36_work(e28_creator());
    $many = e38_open(array_map(fn ($r) => e38_puzzle_report(null, $popular, $r), ['spam', 'misleading', 'other', 'copyright', 'inappropriate']), $a);

    $order = collect(e38_cases()->queue($a, ['view' => 'open'])->items())->pluck('id')->all();

    expect($order)->toBe([$urgent->id, $normalOld->id, $many->id]);
});

test('E38-49: SLA targets are internal suggestions - states ok/due-soon/overdue are computed and vanish once the case ends', function () {
    $a = e38_admin();
    $case = e38_open([e38_puzzle_report()], $a, null, 'high');

    expect(CaseService::slaState($case))->toBe('ok');

    DB::table('moderation_cases')->where('id', $case->id)->update(['first_response_due_at' => now()->addHours(2), 'resolution_due_at' => now()->addHours(3)]);
    $soon = $case->fresh();

    expect(CaseService::slaState($soon))->toBe('due_soon');

    DB::table('moderation_cases')->where('id', $case->id)->update(['resolution_due_at' => now()->subMinute(), 'first_response_due_at' => now()->subHour()]);

    expect(CaseService::slaState($case->fresh()))->toBe('overdue');

    e38_cases()->claim($case->fresh(), $a);
    e38_cases()->resolve($case->fresh(), $a, 'no_violation');

    expect(CaseService::slaState($case->fresh()))->toBe('none');
});

test('E38-50: case detail loads with a bounded number of queries regardless of timeline length', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);
    $mod->can('trust-safety.view');

    $measure = function () use ($case): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        e38_cases()->detail($case->fresh());
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $small = $measure();

    foreach (range(1, 25) as $i) {
        e38_cases()->addNote($case->fresh(), $mod, 'ملاحظة رقم '.$i.' للتحقق من ثبات الاستعلامات');
    }

    $large = $measure();

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(12);
});

test('E38-51: evidence and timeline lists are bounded by config limits', function () {
    $mod = e38_admin();
    $case = e38_open([e38_puzzle_report()], $mod);
    e38_cases()->claim($case, $mod);
    config(['trust_safety.lists.timeline_limit' => 3, 'trust_safety.lists.evidence_limit' => 2]);

    foreach (range(1, 6) as $i) {
        e38_cases()->addNote($case->fresh(), $mod, 'ملاحظة محدودة رقم '.$i);
    }

    $detail = e38_cases()->detail($case->fresh());

    expect($detail['events'])->toHaveCount(3)->and($detail['evidence'])->toHaveCount(2)
        ->and(ModerationEvidence::where('moderation_case_id', $case->id)->count())->toBe(7);
});
