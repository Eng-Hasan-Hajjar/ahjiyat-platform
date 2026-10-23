<?php

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\ModerationAction;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\TrustSafety\PolicyCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E38 (BF5-BF8) - التنفيذ عبر خدمات المجال الرسمية فقط: قائمة إجراءات بيضاء، تأكيد الإجراءات الشديدة، طبقتا الصلاحيات، ذرّية المعاملة،
| حماية Super Admin، فصل السبب الداخلي عن الرسالة العامة، مزامنة البلاغات، وعزل الاقتصاد والسمعة.
*/

beforeEach(fn () => e38_seed());

test('E38-52: the action list is a closed allow-list - unknown actions are refused and the target never comes from input', function () {
    [$case, $mod, $work] = e38_puzzle_case();
    $victim = e16User();

    expect(array_keys(config('trust_safety.actions')))->toBe(['no_action', 'warn_user', 'hide_content', 'restore_content', 'lock_discussion', 'unlock_discussion', 'suspend_creator', 'restore_creator', 'freeze_user', 'unfreeze_user'])
        ->and(e38_error(fn () => e38_apply($case, $mod, 'delete_user')))->toBe('إجراء غير مسموح.')
        ->and(e38_error(fn () => e38_apply($case, $mod, 'ban_permanently')))->toBe('إجراء غير مسموح.')
        ->and(e38_error(fn () => e38_apply($case, $mod, 'lock_discussion')))->toBe('هذا الإجراء لا ينطبق على موضوع الحالة.');

    e38_apply($case, $mod, 'hide_content', ['target_user_id' => $victim->id, 'subject_id' => 999999, 'user_id' => $victim->id]);

    $row = ModerationAction::first();

    expect($row->subject_id)->toBe($work->id)->and($row->target_user_id)->not->toBe($victim->id)->and($victim->fresh()->is_frozen)->toBeFalse();
});

test('E38-53: hide_content on a community puzzle goes through the creator visibility service and records a complete action row', function () {
    [$case, $mod, $work, $creator] = e38_puzzle_case();

    $row = e38_apply($case, $mod, 'hide_content', ['reason' => 'إخفاء بعد مراجعة المحتوى بدقة', 'public_note' => 'مخالف للإرشادات.']);

    expect($work->fresh()->hidden_at)->not->toBeNull()->and($work->fresh()->hidden_reason)->toBe(CreatorPuzzle::HIDDEN_MODERATION)
        ->and($row->action)->toBe('hide_content')->and($row->target_user_id)->toBe($creator->id)->and($row->performed_by)->toBe($mod->id)
        ->and($row->category)->toBe($case->category)->and($row->policy_version)->toBe(1)->and($row->user_visible)->toBeTrue()
        ->and($row->appealable_until->between(now()->addDays(13), now()->addDays(15)))->toBeTrue()
        ->and($row->active_marker)->toBe(1)->and($row->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(e38_events($case))->toContain('action_applied')
        ->and(OperationalAuditLog::where('action', 'moderation_action_applied')->where('subject_id', $row->id)->exists())->toBeTrue();
});

test('E38-54: restore_content reverses the hide through the service, marks the original reversed and keeps both rows', function () {
    [$case, $mod, $work] = e38_puzzle_case();
    $hide = e38_apply($case, $mod, 'hide_content');
    $restore = e38_apply($case, $mod, 'restore_content');

    expect($work->fresh()->hidden_at)->toBeNull()
        ->and($hide->fresh()->reversed_at)->not->toBeNull()->and($hide->fresh()->reversed_by)->toBe($mod->id)->and($hide->fresh()->active_marker)->toBeNull()
        ->and($restore->active_marker)->toBeNull()->and($restore->user_visible)->toBeFalse()
        ->and(ModerationAction::count())->toBe(2)
        ->and(e38_events($case))->toContain('action_reversed');
});

test('E38-55: hide and restore a public collection through the collection moderation service', function () {
    [$case, $mod, $collection] = e38_collection_case();

    e38_apply($case, $mod, 'hide_content');

    expect($collection->fresh()->is_moderation_hidden)->toBeTrue();

    e38_apply($case, $mod, 'restore_content');

    expect($collection->fresh()->is_moderation_hidden)->toBeFalse();
});

test('E38-56: hide and restore a discussion comment, mapping the policy category to the structured hide reason', function () {
    [$case, $mod, $comment] = e38_comment_case();

    e38_apply($case, $mod, 'hide_content');

    expect($comment->fresh()->status)->toBe(DiscussionComment::STATUS_HIDDEN)
        ->and(DB::table('discussion_comments')->where('id', $comment->id)->value('moderation_reason'))->toBe('harassment');

    e38_apply($case, $mod, 'restore_content');

    expect($comment->fresh()->status)->toBe(DiscussionComment::STATUS_VISIBLE);
});

test('E38-57: lock and unlock the discussion thread through the discussion moderation service', function () {
    [$case, $mod, $comment] = e38_comment_case();
    $thread = $comment->thread;

    $lock = e38_apply($case, $mod, 'lock_discussion');

    expect($thread->fresh()->is_locked)->toBeTrue()->and($lock->target_key)->toBe('discussion_thread:'.$thread->id)->and($lock->user_visible)->toBeFalse()
        ->and(e38_error(fn () => e38_apply($case, $mod, 'lock_discussion')))->toBe('هذا الإجراء مُطبَّق بالفعل على الهدف.');

    e38_apply($case, $mod, 'unlock_discussion');

    expect($thread->fresh()->is_locked)->toBeFalse()->and($lock->fresh()->reversed_at)->not->toBeNull();
});

test('E38-58: a reported chat message can be hidden and restored; the evidence is only that reported message', function () {
    $author = e16User();
    [$message, $report] = e38_chat_with_report(null, $author, 'harassment');
    \Carbon\Carbon::setTestNow(now()->addSeconds(11));
    chatMessages()->send($author, chatGlobal(), 'رسالة أخرى غير مبلَّغ عنها');
    $mod = e38_admin();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);
    $evidence = $case->evidence()->pluck('body')->implode("\n");

    e38_apply($case, $mod, 'hide_content');

    expect($message->fresh()->isHidden())->toBeTrue()->and($evidence)->not->toContain('رسالة أخرى غير مبلَّغ عنها');

    e38_apply($case, $mod, 'restore_content');

    expect($message->fresh()->isHidden())->toBeFalse();
});

test('E38-59: warn_user is a formal notice only - it needs a public message, changes no domain state and is appealable and visible to the user', function () {
    [$case, $mod, $work, $creator] = e38_puzzle_case();

    expect(e38_error(fn () => e38_apply($case, $mod, 'warn_user', ['public_note' => ''])))->not->toBeNull();

    $row = e38_apply($case, $mod, 'warn_user', ['public_note' => 'يرجى الالتزام بالإرشادات.']);

    expect($row->user_visible)->toBeTrue()->and($row->appealable_until)->not->toBeNull()->and($row->active_marker)->toBeNull()
        ->and($work->fresh()->hidden_at)->toBeNull()->and($creator->fresh()->is_frozen)->toBeFalse();
});

test('E38-60: no_action records the decision without touching content or notifying anyone', function () {
    [$case, $mod, $work, $creator] = e38_puzzle_case();
    $before = DB::table('notifications')->count();

    $row = e38_apply($case, $mod, 'no_action', ['public_note' => null]);

    expect($row->user_visible)->toBeFalse()->and($row->appealable_until)->toBeNull()->and($work->fresh()->hidden_at)->toBeNull()
        ->and(DB::table('notifications')->count())->toBe($before);
});

test('E38-61: suspending a creator is a severe action - it needs a long reason and an explicit confirmation, then runs through the creator application service', function () {
    [$case, $mod, , $creator] = e38_puzzle_case();
    $profile = CreatorProfile::where('user_id', $creator->id)->first();

    expect(e38_error(fn () => e38_apply($case, $mod, 'suspend_creator', ['confirm' => false])))->toBe('هذا إجراء شديد ويتطلب تأكيدًا صريحًا.')
        ->and(e38_error(fn () => e38_apply($case, $mod, 'suspend_creator', ['reason' => 'سبب قصير'])))->not->toBeNull()
        ->and($profile->fresh()->status)->toBe(CreatorProfile::STATUS_APPROVED);

    $row = e38_apply($case, $mod, 'suspend_creator', ['reason' => 'مخالفات متكررة ثابتة بعد مراجعة الأدلة كاملة']);

    expect($profile->fresh()->status)->toBe(CreatorProfile::STATUS_SUSPENDED)->and($row->target_key)->toBe('creator_profile:'.$profile->id)
        ->and($row->appealable_until->between(now()->addDays(13), now()->addDays(15)))->toBeTrue();

    e38_apply($case, $mod, 'restore_creator');

    expect($profile->fresh()->status)->toBe(CreatorProfile::STATUS_APPROVED)->and($row->fresh()->reversed_at)->not->toBeNull();
});

test('E38-62: freezing an account runs through UserAccountService with a fixed case reference as its visible reason, never the internal reason', function () {
    [$case, $mod, $subject] = e38_user_case();
    $internal = 'سبب داخلي حساس لا يظهر للمستخدم ولا في حقل التجميد';

    $row = e38_apply($case, $mod, 'freeze_user', ['reason' => $internal]);
    $user = $subject->fresh();

    expect($user->is_frozen)->toBeTrue()->and($user->frozen_reason)->toBe('حالة إشراف '.$case->case_number)->and($user->frozen_reason)->not->toContain('حساس')
        ->and($row->reason)->toBe($internal)->and($row->appealable_until->between(now()->addDays(29), now()->addDays(31)))->toBeTrue();

    e38_apply($case, $mod, 'unfreeze_user');

    expect($subject->fresh()->is_frozen)->toBeFalse()->and($row->fresh()->reversed_at)->not->toBeNull();
});

test('E38-63: severe actions never run without the explicit confirmation flag and a long enough reason, and refusal changes nothing', function () {
    [$case, $mod, $subject] = e38_user_case();

    foreach ([false, null, '', '0', 'no'] as $confirm) {
        expect(e38_error(fn () => e38_apply($case, $mod, 'freeze_user', ['confirm' => $confirm])))->toBe('هذا إجراء شديد ويتطلب تأكيدًا صريحًا.');
    }

    expect($subject->fresh()->is_frozen)->toBeFalse()->and(ModerationAction::count())->toBe(0)
        ->and(e38_error(fn () => e38_apply($case, $mod, 'freeze_user', ['reason' => 'قصير'])))->not->toBeNull();
});

test('E38-64: permission matrix - moderators moderate content but cannot freeze or suspend; ordinary users and view-only staff cannot act', function () {
    [$case, , , $creator] = e38_puzzle_case();
    $mod = e38_mod();
    e38_cases()->assign($case->fresh(), e38_admin(), $mod);

    expect(fn () => e38_apply($case, $mod, 'freeze_user'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_apply($case, $mod, 'suspend_creator'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_apply($case, e16User(), 'hide_content'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $viewer = e16User();
    $viewer->givePermissionTo(['trust-safety.view', 'trust-safety.moderate-content']);

    expect(fn () => e38_apply($case, $viewer, 'hide_content'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(e38_apply($case, $mod, 'hide_content')->action)->toBe('hide_content');
});

test('E38-65: two permission layers - the domain service permission is also required, with a clear message and no partial change', function () {
    $mod = e16User();
    $mod->givePermissionTo(['trust-safety.view', 'trust-safety.manage-cases', 'trust-safety.moderate-content']);
    $work = e36_work(e28_creator());
    $case = e38_open([e38_puzzle_report(null, $work, 'spam')], $mod);
    e38_cases()->claim($case, $mod);

    expect(e38_error(fn () => e38_apply($case, $mod, 'hide_content')))->toBe('تحتاج صلاحية «creators.review» لتنفيذ هذا الإجراء.')
        ->and($work->fresh()->hidden_at)->toBeNull()->and(ModerationAction::count())->toBe(0);
});

test('E38-66: the whole enforcement is one transaction - a domain failure leaves no action row, event or audit entry behind', function () {
    [$case, $mod, $work] = e38_puzzle_case();
    // إخفاء سابق خارج الطبقة (الخدمة الرسمية) ⇒ خدمة المجال سترفض إخفاءً ثانيًا.
    app(\App\Services\Creators\CreatorVisibilityService::class)->hide($work, $mod, 'إخفاء سابق');

    $events = $case->events()->count();
    $audits = OperationalAuditLog::where('action', 'moderation_action_applied')->count();

    expect(e38_error(fn () => e38_apply($case, $mod, 'hide_content')))->not->toBeNull()
        ->and(ModerationAction::count())->toBe(0)->and($case->events()->count())->toBe($events)
        ->and(OperationalAuditLog::where('action', 'moderation_action_applied')->count())->toBe($audits);
});

test('E38-67: a second active identical action on the same target is blocked by the database unique constraint', function () {
    [$case, $mod] = e38_puzzle_case();
    $first = e38_apply($case, $mod, 'hide_content');

    expect(e38_error(fn () => e38_apply($case, $mod, 'hide_content')))->not->toBeNull();

    $clone = new ModerationAction;
    $clone->forceFill(array_merge($first->only(['moderation_case_id', 'action', 'target_user_id', 'subject_type', 'subject_id', 'target_key', 'category', 'reason']), ['active_marker' => 1]));

    expect(fn () => $clone->save())->toThrow(UniqueConstraintViolationException::class)
        ->and(ModerationAction::count())->toBe(1);
});

test('E38-68: only the assignee or a supervisor can enforce, and only on an active case', function () {
    [$case, $assignee] = e38_puzzle_case(e38_mod_with(['users.freeze']));
    $intruder = e38_mod();

    expect(e38_error(fn () => e38_apply($case, $intruder, 'hide_content')))->toBe('احجز الحالة أولًا (أو اطلب من المشرف الأعلى تعيينها لك).');

    e38_cases()->close($case->fresh(), $assignee, 'إغلاق إداري قبل التنفيذ');

    expect(e38_error(fn () => e38_apply($case, $assignee, 'hide_content')))->toBe('الحالة غير فعّالة.')
        ->and(ModerationAction::count())->toBe(0);
});

test('E38-69: Super Admin safety - an administrator cannot freeze a Super Admin, and the domain guard message is preserved', function () {
    $superAdmin = e38_super();
    $flag = e38_fraud_flag($superAdmin);
    $admin = e38_admin();
    $case = e38_open([$flag], $admin);
    e38_cases()->claim($case, $admin);

    $message = e38_error(fn () => e38_apply($case, $admin, 'freeze_user'));

    expect($message)->not->toBeNull()->and($superAdmin->fresh()->is_frozen)->toBeFalse()->and(ModerationAction::count())->toBe(0)
        ->and($case->fresh()->status)->toBe('investigating');
});

test('E38-70: the last Super Admin can never be frozen, even by another Super Admin acting through a moderation case', function () {
    $only = e38_super();
    $other = e38_super();
    $other->removeRole('super-admin');
    $actor = e38_super();
    $flag = e38_fraud_flag($only);
    $case = e38_open([$flag], $actor);
    e38_cases()->claim($case, $actor);

    // يوجد Super Admin آخر (الفاعل) فيجوز؛ ثم نخفض العدد إلى واحد ونتأكد من الرفض.
    $actor->removeRole('super-admin');
    $admin = e38_admin();
    e38_cases()->assign($case->fresh(), $admin, $admin);

    expect(e38_error(fn () => e38_apply($case, $admin, 'freeze_user')))->not->toBeNull()->and($only->fresh()->is_frozen)->toBeFalse();
});

test('E38-71: self-moderation is refused - a moderator can neither open nor freeze a case about themselves', function () {
    $admin = e38_admin();
    $flag = e38_fraud_flag($admin);

    expect(e38_error(fn () => e38_cases()->open($admin, [e38_ref($flag)])))->toBe('لا يمكنك التعامل مع حالة تخصّ حسابك أو محتواك.')
        ->and($admin->fresh()->is_frozen)->toBeFalse();
});

test('E38-72: a Super Admin enforcer still works through the same services and may freeze an ordinary user', function () {
    $super = e38_super();
    $subject = e16User();
    $case = e38_open([e38_fraud_flag($subject, 'multiple_accounts', 'high')], $super);
    e38_cases()->claim($case, $super);

    $row = e38_apply($case, $super, 'freeze_user');

    expect($subject->fresh()->is_frozen)->toBeTrue()->and($row->performed_by)->toBe($super->id)
        ->and(OperationalAuditLog::where('action', 'user_frozen')->where('subject_id', $subject->id)->exists())->toBeTrue();
});

test('E38-73: the visible domain note carries the category label, never the internal reason', function () {
    [$case, $mod, $work] = e38_puzzle_case();
    $internal = 'سبب داخلي سري يخص المشرفين فقط';

    e38_apply($case, $mod, 'hide_content', ['reason' => $internal, 'public_note' => 'العنوان مضلِّل.']);
    $dump = json_encode(DB::table('creator_puzzles')->where('id', $work->id)->first(), JSON_UNESCAPED_UNICODE).json_encode(DB::table('operational_audit_logs')->where('action', 'like', 'creator_%')->get(), JSON_UNESCAPED_UNICODE);

    expect($dump)->not->toContain('سري')->and($dump)->toContain(PolicyCatalog::categoryLabel($case->category));
});

test('E38-74: hiding a collection through the domain service closes its open reports as actioned and a later resolve does not double-close', function () {
    [$case, $mod, $collection] = e38_collection_case();
    $second = e38_collection_report(null, $collection, 'spam');

    e38_apply($case, $mod, 'hide_content');

    expect($second->fresh()->status)->toBe('actioned');

    $resolved = e38_cases()->resolve($case->fresh(), $mod, 'action_taken');

    expect($resolved->status)->toBe('resolved')->and(DB::table('user_collection_reports')->where('user_collection_id', $collection->id)->where('status', 'open')->count())->toBe(0);
});

test('E38-75: a fraud signal never freezes anyone by itself - open, claim, notes and resolve leave the account active; only an explicit confirmed action freezes', function () {
    [$case, $mod, $subject] = e38_user_case();
    e38_cases()->addNote($case->fresh(), $mod, 'مراجعة يدوية للإشارة');
    e38_cases()->changeStatus($case->fresh(), $mod, 'awaiting_user');

    expect($subject->fresh()->is_frozen)->toBeFalse();

    e38_cases()->resolve($case->fresh(), $mod, 'insufficient_evidence');

    expect($subject->fresh()->is_frozen)->toBeFalse()->and(DB::table('fraud_flags')->where('user_id', $subject->id)->value('resolved'))->toBe(1)
        ->and(ModerationAction::count())->toBe(0);
});

test('E38-76: the actions offered to the UI depend on the case subject and the actor permissions', function () {
    [$puzzleCase, $admin] = e38_puzzle_case();
    [$userCase] = e38_user_case($admin);
    [$commentCase] = e38_comment_case($admin);
    $mod = e38_mod();
    $keys = fn ($case, $actor) => array_column(e38_enforce()->available($case, $actor), 'key');

    expect($keys($puzzleCase, $admin))->toContain('hide_content', 'suspend_creator', 'warn_user')->not->toContain('lock_discussion', 'freeze_user')
        ->and($keys($userCase, $admin))->toContain('freeze_user', 'warn_user')->not->toContain('hide_content')
        ->and($keys($commentCase, $admin))->toContain('lock_discussion', 'hide_content')
        ->and($keys($commentCase, $mod))->not->toContain('freeze_user')->not->toContain('suspend_creator');
});

test('E38-77: the enforcement notification is a fixed security notice - no moderator, reporter or internal reason - and reporters are never notified', function () {
    $reporter = e16User();
    $creator = e28_creator();
    $work = e36_work($creator);
    $report = e38_puzzle_report($reporter, $work, 'inappropriate');
    $mod = e38_admin();
    $case = e38_open([$report], $mod);
    e38_cases()->claim($case, $mod);

    e38_apply($case, $mod, 'hide_content', ['reason' => 'سبب داخلي لا يجوز تسريبه']);
    e38_cases()->resolve($case->fresh(), $mod, 'action_taken');

    $creatorNotes = DB::table('notifications')->where('notifiable_id', $creator->id)->where('type_key', 'moderation_notice')->get();
    $dump = json_encode($creatorNotes, JSON_UNESCAPED_UNICODE);

    expect($creatorNotes)->toHaveCount(1)->and($dump)->not->toContain('تسريبه')->and($dump)->not->toContain($mod->name)->and($dump)->not->toContain($reporter->name)
        ->and(DB::table('notifications')->where('notifiable_id', $reporter->id)->where('type_key', 'like', 'moderation%')->count())->toBe(0);
});

test('E38-78: enforcement is isolated from the economy, progression and E37 reputation - no gems, XP, quest or streak row changes and refresh is the only reputation effect', function () {
    [$case, $mod, $work, $creator] = e38_puzzle_case();
    $tables = ['gem_wallets', 'gem_transactions', 'xp_events', 'user_quest_progress', 'user_streaks', 'referrals'];
    $snapshot = fn () => collect($tables)->filter(fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $before = $snapshot();

    e38_apply($case, $mod, 'hide_content');
    e38_apply($case, $mod, 'warn_user', ['public_note' => 'تنبيه رسمي.']);

    expect($snapshot())->toBe($before)
        ->and(config('reputation.penalties', null))->toBeNull()
        ->and(DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->count())->toBeLessThanOrEqual(1);
});

test('E38-79: no automatic permanent ban, AI decision or secret trust score exists in the action vocabulary', function () {
    $actions = array_keys(config('trust_safety.actions'));
    $dump = strtolower(json_encode(config('trust_safety')));

    expect($actions)->not->toContain('ban_user')->not->toContain('delete_user')->not->toContain('permanent_ban')
        ->and($dump)->not->toContain('trust_score')->not->toContain('ai_decision')->not->toContain('auto_freeze');
});

test('E38-80: the moderator of a case can later be a different person than the one who opened it, and the action keeps the actual performer', function () {
    $opener = e38_mod();
    $case = e38_open([e38_puzzle_report()], $opener);
    $admin = e38_admin();
    e38_cases()->claim($case, $admin);

    $row = e38_apply($case, $admin, 'warn_user', ['public_note' => 'تنبيه رسمي للمبدع.']);

    expect($row->performed_by)->toBe($admin->id)->and($case->fresh()->opened_by)->toBe($opener->id);
});
