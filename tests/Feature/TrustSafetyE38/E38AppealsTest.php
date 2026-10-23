<?php

use App\Models\CreatorProfile;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Services\TrustSafety\RestrictedAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E38 (BF9-BF11) - الاعتراضات: التقديم والمهلة وواحد فعّال لكل إجراء، حواجز تضارب المراجِع، القبول يعكس عبر خدمة المجال دون محو التاريخ،
| الرفض يُبقي الإجراء، والوصول المقيَّد للحساب المجمَّد لصفحة الاعتراض وحدها.
*/

beforeEach(fn () => e38_seed());

const E38_APPEAL_TEXT = 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة واضحة من جهتي.';

/** حالة أحجية مجتمع مع إخفاء نُفِّذ، وصاحب العمل. @return array{0: ModerationAction, 1: User, 2: User, 3: \App\Models\ModerationCase, 4: \App\Models\CreatorPuzzle} action, creator, admin, case, work */
function e38_hidden_by_admin(): array
{
    [$case, $admin, $work, $creator] = e38_puzzle_case();
    $action = e38_apply($case, $admin, 'hide_content');

    return [$action, $creator, $admin, $case->fresh(), $work];
}

test('E38-81: the affected user can appeal a hide action once - the appeal is pending, active and recorded in the timeline and audit log', function () {
    [$action, $creator, , $case] = e38_hidden_by_admin();

    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    expect($appeal->status)->toBe('pending')->and($appeal->active_marker)->toBe(1)->and($appeal->appellant_id)->toBe($creator->id)
        ->and($appeal->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(e38_events($case))->toContain('appeal_filed')
        ->and(OperationalAuditLog::where('action', 'moderation_appeal_filed')->where('subject_id', $appeal->id)->exists())->toBeTrue();
});

test('E38-82: the appeal text is plain text with bounds - HTML, too short and too long texts are refused', function () {
    [$action, $creator] = e38_hidden_by_admin();

    expect(e38_error(fn () => e38_appeals()->file($creator, $action, '<script>alert(1)</script> اعتراض طويل بما يكفي للقبول')))->toBe('نص الاعتراض نص عادي فقط (بلا وسوم أو HTML).')
        ->and(e38_error(fn () => e38_appeals()->file($creator, $action, 'قصير')))->not->toBeNull()
        ->and(e38_error(fn () => e38_appeals()->file($creator, $action, str_repeat('ا', 1501))))->not->toBeNull()
        ->and(ModerationAppeal::count())->toBe(0);
});

test('E38-83: only the targeted user can appeal - other users and the reversal row itself are not appealable', function () {
    [$action, $creator, $admin, $case] = e38_hidden_by_admin();

    expect(e38_error(fn () => e38_appeals()->file(e16User(), $action, E38_APPEAL_TEXT)))->toBe('هذا الإجراء لا يخصّ حسابك.')
        ->and(e38_error(fn () => e38_appeals()->file($admin, $action, E38_APPEAL_TEXT)))->toBe('هذا الإجراء لا يخصّ حسابك.');

    $restore = e38_apply($case, $admin, 'restore_content');

    expect(e38_error(fn () => e38_appeals()->file($creator, $restore, E38_APPEAL_TEXT)))->toBe('هذا الإجراء لا يخصّ حسابك.');
});

test('E38-84: actions that are not appealable (lock, no-action, restore, unfreeze) refuse an appeal even from the target', function () {
    [$case, $mod, $comment, $author] = e38_comment_case();
    $lock = e38_apply($case, $mod, 'lock_discussion');
    $none = e38_apply($case, $mod, 'no_action');

    foreach ([$lock, $none] as $row) {
        expect(e38_error(fn () => e38_appeals()->file($author, $row, E38_APPEAL_TEXT)))->not->toBeNull();
    }

    expect(ModerationAppeal::count())->toBe(0)->and(config('trust_safety.actions.lock_discussion.appealable'))->toBeFalse();
});

test('E38-85: the appeal window is configurable per action and closes after it - warnings and hides 14 days, freezes 30 days', function () {
    [$action, $creator] = e38_hidden_by_admin();

    \Carbon\Carbon::setTestNow(now()->addDays(15));

    expect(e38_error(fn () => e38_appeals()->file($creator, $action, E38_APPEAL_TEXT)))->toBe('انتهت مهلة الاعتراض على هذا الإجراء.');

    \Carbon\Carbon::setTestNow();
    config(['trust_safety.appeals.window_days.hide_content' => 40]);
    [$later, $creator2] = e38_hidden_by_admin();
    \Carbon\Carbon::setTestNow(now()->addDays(39));

    expect(e38_appeals()->file($creator2, $later, E38_APPEAL_TEXT)->status)->toBe('pending');

    \Carbon\Carbon::setTestNow();
    expect(config('trust_safety.appeals.window_days.freeze_user'))->toBe(30)->and(config('trust_safety.appeals.window_days.warn_user'))->toBe(14);
});

test('E38-86: only one active appeal per action - the second is refused by the service and by the database', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $first = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    expect(e38_error(fn () => e38_appeals()->file($creator, $action, E38_APPEAL_TEXT.' ثانية')))->toBe('لديك اعتراض قيد المراجعة على هذا الإجراء.');

    $dup = new ModerationAppeal;
    $dup->forceFill(['moderation_action_id' => $action->id, 'appellant_id' => $creator->id, 'reason' => E38_APPEAL_TEXT, 'status' => 'pending', 'active_marker' => 1]);

    expect(fn () => $dup->save())->toThrow(UniqueConstraintViolationException::class)->and(ModerationAppeal::count())->toBe(1)->and($first->id)->toBeInt();
});

test('E38-87: after a final decision the same action cannot be re-appealed by default, but a withdrawn appeal can be re-filed', function () {
    [$action, $creator, $admin] = e38_hidden_by_admin();
    $reviewer = e38_mod();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    e38_appeals()->decide($appeal, $reviewer, false, 'بعد المراجعة يبقى القرار كما هو.');

    expect(config('trust_safety.appeals.allow_reappeal'))->toBeFalse()
        ->and(e38_error(fn () => e38_appeals()->file($creator, $action, E38_APPEAL_TEXT.' جديد')))->toBe('صدر قرار نهائي في اعتراضك على هذا الإجراء.');

    [$other, $creator2] = e38_hidden_by_admin();
    $withdrawn = e38_appeals()->file($creator2, $other, E38_APPEAL_TEXT);
    e38_appeals()->withdraw($creator2, $withdrawn);

    expect($withdrawn->fresh()->status)->toBe('withdrawn')->and($withdrawn->fresh()->active_marker)->toBeNull()
        ->and(e38_appeals()->file($creator2, $other, E38_APPEAL_TEXT.' بعد السحب')->status)->toBe('pending');
});

test('E38-88: withdrawal is for the owner while pending only - not after review starts and not by another user', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    expect(e38_error(fn () => e38_appeals()->withdraw(e16User(), $appeal)))->toBe('هذا الاعتراض ليس لك.');

    e38_appeals()->claim($appeal, e38_mod());

    expect(e38_error(fn () => e38_appeals()->withdraw($creator, $appeal->fresh())))->toBe('لا يمكن سحب الاعتراض بعد بدء مراجعته.');
});

test('E38-89: claiming an appeal is atomic - one reviewer wins, a stale second reviewer is refused', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $a = e38_mod();
    $b = e38_mod();
    $stale = ModerationAppeal::find($appeal->id);

    $won = e38_appeals()->claim($appeal, $a);

    expect($won->status)->toBe('in_review')->and($won->reviewer_id)->toBe($a->id)->and($won->claimed_at)->not->toBeNull()
        ->and(e38_error(fn () => e38_appeals()->claim($stale, $b)))->toBe('حُجز هذا الاعتراض من مراجع آخر أو لم يعد معلّقًا.')
        ->and(e38_appeals()->claim($appeal, $a)->reviewer_id)->toBe($a->id);
});

test('E38-90: reviewer conflicts - the appellant, the performer, the content owner and a reporter cannot review; a supervisor may review their own action with a flagged override', function () {
    [$action, $creator, $admin, $case] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $performer = $admin;

    $reporter = User::find(DB::table('moderation_case_sources')->where('moderation_case_id', $case->id)->value('reporter_id'));
    $reporter->assignRole('moderator');

    expect(e38_error(fn () => e38_appeals()->claim($appeal, $creator->assignRole('moderator'))))->not->toBeNull()
        ->and(e38_error(fn () => e38_appeals()->claim($appeal, $reporter)))->toBe('لا يمكنك التعامل مع حالة أنت مبلِّغ فيها.');

    // مشرف عادي نفّذ الإجراء ⇒ ممنوع؛ المشرف الأعلى (مدير) ⇒ استثناء موسوم
    [$modAction, $creator2, , $case2] = e38_hidden_by_admin();
    $plain = e38_mod_with(['users.freeze']);
    $caseB = e38_open([e38_puzzle_report(null, e36_work(e28_creator()), 'spam')], $plain);
    e38_cases()->claim($caseB, $plain);
    $actionB = e38_apply($caseB, $plain, 'hide_content');
    $appealB = e38_appeals()->file(User::find($actionB->target_user_id), $actionB, E38_APPEAL_TEXT);

    expect(e38_error(fn () => e38_appeals()->claim($appealB, $plain)))->toBe('لا يمكنك مراجعة اعتراض على إجراء نفّذته بنفسك؛ يحوَّل لمراجع آخر.');

    $caseC = e38_open([e38_puzzle_report(null, e36_work(e28_creator()), 'spam')], $performer);
    e38_cases()->claim($caseC, $performer);
    $actionC = e38_apply($caseC, $performer, 'hide_content');
    $appealC = e38_appeals()->file(User::find($actionC->target_user_id), $actionC, E38_APPEAL_TEXT);
    $claimed = e38_appeals()->claim($appealC, $performer);

    expect($claimed->conflict_flag)->toBeTrue()
        ->and(OperationalAuditLog::where('action', 'moderation_appeal_conflict_override')->where('subject_id', $claimed->id)->exists())->toBeTrue()
        ->and($modAction->id)->toBeInt()->and($creator2->id)->toBeInt()->and($case2->id)->toBeInt()->and($performer->id)->toBe($admin->id);
});

test('E38-91: accepting an appeal restores the hidden work through the domain service, adds a reversing action and keeps the original row and history', function () {
    [$action, $creator, $admin, $case, $work] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $reviewer = e38_mod();
    $before = ModerationAction::count();
    $eventsBefore = $case->events()->count();

    $decided = e38_appeals()->decide($appeal, $reviewer, true, 'راجعنا العمل وقبلنا اعتراضك.', 'ملاحظة داخلية للمراجعة');

    $original = $action->fresh();
    $reversal = ModerationAction::where('action', 'restore_content')->first();

    expect($decided->status)->toBe('accepted')->and($decided->active_marker)->toBeNull()->and($decided->decision_message)->toBe('راجعنا العمل وقبلنا اعتراضك.')
        ->and($work->fresh()->hidden_at)->toBeNull()
        ->and($original->reversed_at)->not->toBeNull()->and($original->reversed_by)->toBe($reviewer->id)->and($original->reason)->toBe('سبب داخلي واضح لاتخاذ الإجراء')
        ->and(ModerationAction::count())->toBe($before + 1)->and($reversal->performed_by)->toBe($reviewer->id)
        ->and($case->events()->count())->toBeGreaterThan($eventsBefore)->and(e38_events($case))->toContain('action_applied', 'appeal_filed', 'appeal_decided', 'action_reversed')
        ->and(ModerationAppeal::count())->toBe(1);
});

test('E38-92: accepting a freeze appeal unfreezes the account through UserAccountService and ends the restricted access context', function () {
    [$case, $admin, $subject] = e38_user_case();
    $action = e38_apply($case, $admin, 'freeze_user');
    $appeal = e38_appeals()->file($subject->fresh(), $action, E38_APPEAL_TEXT);

    expect($subject->fresh()->is_frozen)->toBeTrue();

    e38_appeals()->decide($appeal, e38_admin(), true, 'رُفع التجميد بعد مراجعة الاعتراض.');

    expect($subject->fresh()->is_frozen)->toBeFalse()->and($subject->fresh()->frozen_reason)->toBeNull()
        ->and($action->fresh()->reversed_at)->not->toBeNull()
        ->and(OperationalAuditLog::where('action', 'user_unfrozen')->where('subject_id', $subject->id)->exists())->toBeTrue();
});

test('E38-93: accepting a creator-suspension appeal restores the creator profile', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $action = e38_apply($case, $admin, 'suspend_creator', ['reason' => 'مخالفات متكررة ثابتة بعد مراجعة الأدلة كاملة']);
    $profile = CreatorProfile::where('user_id', $creator->id)->first();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    expect($profile->fresh()->status)->toBe('suspended');

    e38_appeals()->decide($appeal, e38_admin(), true, 'استعدنا ملفك بعد المراجعة.');

    expect($profile->fresh()->status)->toBe('approved')->and($action->fresh()->reversed_at)->not->toBeNull();
});

test('E38-94: if the effect was already reverted outside this layer, accepting only marks the original reversed without a second domain call', function () {
    [$action, $creator, $admin, , $work] = e38_hidden_by_admin();
    app(\App\Services\Creators\CreatorVisibilityService::class)->restore($work->fresh(), $admin);
    $appeal = e38_appeals()->file($creator, $action->fresh(), E38_APPEAL_TEXT);
    $before = ModerationAction::count();

    e38_appeals()->decide($appeal, e38_mod(), true, 'الأثر أُزيل بالفعل وقبلنا الاعتراض.');

    expect($action->fresh()->reversed_at)->not->toBeNull()->and(ModerationAction::count())->toBe($before)->and($appeal->fresh()->status)->toBe('accepted');
});

test('E38-95: accepting a warning appeal withdraws the warning with no domain call', function () {
    [$case, $mod, $work, $creator] = e38_puzzle_case();
    $warning = e38_apply($case, $mod, 'warn_user', ['public_note' => 'تنبيه رسمي بشأن المحتوى.']);
    $appeal = e38_appeals()->file($creator, $warning, E38_APPEAL_TEXT);
    $before = ModerationAction::count();

    e38_appeals()->decide($appeal, e38_mod(), true, 'سُحب التنبيه بعد المراجعة.');

    expect($warning->fresh()->reversed_at)->not->toBeNull()->and(ModerationAction::count())->toBe($before)->and($work->fresh()->hidden_at)->toBeNull();
});

test('E38-96: rejecting an appeal keeps the action in force and shows the decision message to the user', function () {
    [$action, $creator, , , $work] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    $decided = e38_appeals()->decide($appeal, e38_mod(), false, 'بعد المراجعة يبقى القرار كما هو.', 'مخالفة مؤكدة');

    expect($decided->status)->toBe('rejected')->and($decided->active_marker)->toBeNull()->and($work->fresh()->hidden_at)->not->toBeNull()
        ->and($action->fresh()->reversed_at)->toBeNull()->and($action->fresh()->active_marker)->toBe(1)
        ->and(app(\App\Services\TrustSafety\UserSafetyService::class)->item($creator, $action->public_id)['decision_message'])->toBe('بعد المراجعة يبقى القرار كما هو.');
});

test('E38-97: a decision needs a plain-text user message; the internal note is optional and never shown to the user', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $reviewer = e38_mod();

    expect(e38_error(fn () => e38_appeals()->decide($appeal, $reviewer, false, '')))->not->toBeNull()
        ->and(e38_error(fn () => e38_appeals()->decide($appeal, $reviewer, false, '<b>رفض</b> مع وسوم محظورة')))->toBe('رسالة القرار نص عادي فقط (بلا وسوم أو HTML).');

    e38_appeals()->decide($appeal, $reviewer, false, 'القرار نهائي بعد المراجعة.', 'ملاحظة داخلية سرية للمراجعين');
    $item = app(\App\Services\TrustSafety\UserSafetyService::class)->item($creator, $action->public_id);

    expect(json_encode($item, JSON_UNESCAPED_UNICODE))->not->toContain('سرية')->and($appeal->fresh()->decision_note)->toBe('ملاحظة داخلية سرية للمراجعين');
});

test('E38-98: a decided appeal cannot be decided again and a failed reversal rolls the whole decision back', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    // مراجع بصلاحية الاعتراضات لكن بلا صلاحية المجال (creators.review): العكس يفشل ⇒ القرار كله يُرجَع.
    $limited = e16User();
    $limited->givePermissionTo(['trust-safety.view', 'trust-safety.review-appeals']);

    expect(e38_error(fn () => e38_appeals()->decide($appeal, $limited, true, 'محاولة قبول بلا صلاحية المجال.')))->toBe('تحتاج صلاحية «creators.review» لتنفيذ هذا الإجراء.')
        ->and($appeal->fresh()->status)->toBe('pending')->and($action->fresh()->reversed_at)->toBeNull();

    e38_appeals()->decide($appeal, e38_mod(), false, 'القرار نهائي بعد المراجعة.');

    expect(e38_error(fn () => e38_appeals()->decide($appeal->fresh(), e38_mod(), true, 'قرار ثانٍ غير جائز.')))->toBe('تم البتّ في هذا الاعتراض بالفعل.');
});

test('E38-99: reviewing appeals requires the review-appeals permission', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);

    expect(fn () => e38_appeals()->claim($appeal, e16User()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_appeals()->decide($appeal, e28_reviewer('support'), false, 'رفض بلا صلاحية.'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => e38_appeals()->queue(e16User(), []))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('E38-100: the appeals queue and counters follow the lifecycle', function () {
    [$a1, $c1] = e38_hidden_by_admin();
    [$a2, $c2] = e38_hidden_by_admin();
    [$a3, $c3] = e38_hidden_by_admin();
    $p1 = e38_appeals()->file($c1, $a1, E38_APPEAL_TEXT);
    $p2 = e38_appeals()->file($c2, $a2, E38_APPEAL_TEXT);
    $p3 = e38_appeals()->file($c3, $a3, E38_APPEAL_TEXT);
    $me = e38_mod();
    e38_appeals()->claim($p2, $me);
    e38_appeals()->decide($p3, e38_mod(), false, 'القرار نهائي بعد المراجعة.');

    $ids = fn (string $view) => collect(e38_appeals()->queue($me, ['view' => $view])->items())->pluck('id')->sort()->values()->all();

    expect(e38_appeals()->counts($me))->toBe(['pending' => 1, 'in_review' => 1, 'mine' => 1])
        ->and($ids('active'))->toBe(collect([$p1, $p2])->pluck('id')->sort()->values()->all())
        ->and($ids('pending'))->toBe([$p1->id])->and($ids('in_review'))->toBe([$p2->id])->and($ids('mine'))->toBe([$p2->id])->and($ids('decided'))->toBe([$p3->id]);
});

test('E38-101: the appellant gets a fixed security notification on the decision - no reviewer name or internal note', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $reviewer = e38_mod();

    e38_appeals()->decide($appeal, $reviewer, false, 'القرار نهائي بعد المراجعة.', 'ملاحظة داخلية خاصة');
    $rows = DB::table('notifications')->where('notifiable_id', $creator->id)->where('type_key', 'moderation_appeal_decided')->get();
    $dump = json_encode($rows, JSON_UNESCAPED_UNICODE);

    expect($rows)->toHaveCount(1)->and($dump)->not->toContain($reviewer->name)->and($dump)->not->toContain('داخلية خاصة');
});

// ───────────────────────── صفحة «الأمان والإشراف» والوصول المقيَّد ─────────────────────────

test('E38-102: the safety page shows the user their own actions as the moderation team - no moderator, reporter, internal reason or case evidence', function () {
    [$action, $creator, $admin, $case] = e38_hidden_by_admin();
    $reporter = User::find(DB::table('moderation_case_sources')->where('moderation_case_id', $case->id)->value('reporter_id'));
    e38_cases()->addNote($case->fresh(), $admin, 'ملاحظة مشرف سرية جدًا');

    $html = $this->actingAs($creator)->get(route('account.safety.index'))->assertOk()->getContent();

    expect($html)->toContain('فريق الإشراف')->toContain($case->case_number)
        ->and($html)->not->toContain($admin->name)->not->toContain($reporter->name)->not->toContain('سبب داخلي واضح')->not->toContain('سرية جدًا');

    $this->actingAs($creator)->get(route('account.safety.show', $action->public_id))->assertOk()->assertSee('فريق الإشراف')->assertDontSee($admin->name);
});

test('E38-103: no IDOR - another user sees 404 for an action and cannot appeal or withdraw on another user\'s behalf', function () {
    [$action, $creator] = e38_hidden_by_admin();
    $appeal = e38_appeals()->file($creator, $action, E38_APPEAL_TEXT);
    $stranger = e16User();

    $this->actingAs($stranger)->get(route('account.safety.show', $action->public_id))->assertNotFound();
    $this->actingAs($stranger)->post(route('account.safety.appeal', $action->public_id), ['reason' => E38_APPEAL_TEXT])->assertNotFound();
    $this->actingAs($stranger)->post(route('account.safety.withdraw', $appeal->public_id))->assertNotFound();
    $this->actingAs($stranger)->get('/account/safety/actions/1')->assertNotFound();

    expect($appeal->fresh()->status)->toBe('pending');
});

test('E38-104: filing and withdrawing an appeal over HTTP works for the owner and never accepts HTML', function () {
    [$action, $creator] = e38_hidden_by_admin();

    $this->actingAs($creator)->post(route('account.safety.appeal', $action->public_id), ['reason' => '<img src=x onerror=1> اعتراض كافٍ الطول لكن فيه وسوم'])->assertSessionHasErrors('reason');
    $this->actingAs($creator)->post(route('account.safety.appeal', $action->public_id), ['reason' => E38_APPEAL_TEXT])->assertRedirect(route('account.safety.show', $action->public_id));

    $appeal = ModerationAppeal::first();

    $this->actingAs($creator)->post(route('account.safety.withdraw', $appeal->public_id))->assertSessionHas('success');

    expect($appeal->fresh()->status)->toBe('withdrawn')->and(ModerationAppeal::count())->toBe(1);
});

test('E38-105: a frozen account still cannot sign in (E26) but the correct password opens a restricted context for the safety page only', function () {
    [$case, $admin, $subject] = e38_user_case();
    $action = e38_apply($case, $admin, 'freeze_user');

    $this->from('/login')->post('/login', ['email' => $subject->email, 'password' => 'password'])->assertRedirect('/login')->assertSessionHas('safety_link');

    expect(session('errors')->first('email'))->toContain('تجميد')->and(session(RestrictedAccess::KEY)['user_id'])->toBe($subject->id);
    $this->assertGuest();

    $context = session(RestrictedAccess::KEY);

    $this->withSession([RestrictedAccess::KEY => $context])->get(route('account.safety.index'))->assertOk()->assertSee('فريق الإشراف')->assertSee('تجميد');
    $this->assertGuest();
});

test('E38-106: a wrong password creates no restricted context and the safety page redirects guests to login', function () {
    [$case, $admin, $subject] = e38_user_case();
    e38_apply($case, $admin, 'freeze_user');

    $this->from('/login')->post('/login', ['email' => $subject->email, 'password' => 'wrong-password'])->assertRedirect('/login');

    expect(session(RestrictedAccess::KEY))->toBeNull();
    $this->get(route('account.safety.index'))->assertRedirect(route('login'));
});

test('E38-107: the restricted context opens nothing else - protected pages and write actions stay unreachable for the frozen user', function () {
    [$case, $admin, $subject] = e38_user_case();
    e38_apply($case, $admin, 'freeze_user');
    $context = [RestrictedAccess::KEY => ['user_id' => $subject->id, 'until' => now()->addMinutes(20)->getTimestamp()]];

    $this->withSession($context)->get(route('creator.apply'))->assertRedirect(route('login'));
    $this->withSession($context)->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->withSession($context)->post(route('community.puzzles.report', '01HZZZZZZZZZZZZZZZZZZZZZZZ'), ['reason' => 'spam'])->assertRedirect(route('login'));
    $this->assertGuest();
});

test('E38-108: the restricted context expires on a short TTL and is re-validated against the live frozen state', function () {
    [$case, $admin, $subject] = e38_user_case();
    $action = e38_apply($case, $admin, 'freeze_user');
    $ttl = (int) config('trust_safety.restricted_access.ttl_minutes');

    $valid = [RestrictedAccess::KEY => ['user_id' => $subject->id, 'until' => now()->addMinutes($ttl)->getTimestamp()]];
    $expired = [RestrictedAccess::KEY => ['user_id' => $subject->id, 'until' => now()->subMinute()->getTimestamp()]];

    $this->withSession($expired)->get(route('account.safety.index'))->assertRedirect(route('login'));
    $this->withSession($valid)->get(route('account.safety.index'))->assertOk();

    e38_appeals()->decide(e38_appeals()->file($subject->fresh(), $action, E38_APPEAL_TEXT), e38_admin(), true, 'رُفع التجميد بعد المراجعة.');

    $this->withSession($valid)->get(route('account.safety.index'))->assertRedirect(route('login'));
    expect($ttl)->toBe(30);
});

test('E38-109: a frozen user can appeal through the restricted context and the appeal reaches the queue; leaving ends the context', function () {
    [$case, $admin, $subject] = e38_user_case();
    $action = e38_apply($case, $admin, 'freeze_user');
    $context = [RestrictedAccess::KEY => ['user_id' => $subject->id, 'until' => now()->addMinutes(20)->getTimestamp()]];

    $this->withSession($context)->post(route('account.safety.appeal', $action->public_id), ['reason' => E38_APPEAL_TEXT])->assertRedirect(route('account.safety.show', $action->public_id));

    expect(ModerationAppeal::first()->appellant_id)->toBe($subject->id)->and(ModerationAppeal::first()->status)->toBe('pending');

    $this->withSession($context)->post(route('account.safety.leave'))->assertRedirect(route('login'));

    expect(session(RestrictedAccess::KEY))->toBeNull();
});

test('E38-110: a user frozen while logged in is redirected from protected pages to the safety page for navigation and blocked from writes (E26 behaviour kept)', function () {
    [$case, $admin, $subject] = e38_user_case();
    e38_apply($case, $admin, 'freeze_user');
    $subject = $subject->fresh();
    $subject->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($subject)->get(route('creator.apply'))->assertRedirect(route('account.safety.index'));
    $this->actingAs($subject)->get(route('account.safety.index'))->assertOk();
    $this->actingAs($subject)->from('/')->post(route('community.puzzles.report', '01HZZZZZZZZZZZZZZZZZZZZZZZ'), ['reason' => 'spam'])->assertSessionHas('error');
});
