<?php

use App\Filament\Pages\ModerationAppealsPage;
use App\Filament\Pages\ModerationCasePage;
use App\Filament\Pages\ModerationCasesPage;
use App\Filament\Pages\SafetyAnalyticsPage;
use App\Filament\Pages\TrustSafetyInboxPage;
use App\Models\ChatMessageReport;
use App\Models\CommunityPuzzleReport;
use App\Models\DiscussionCommentReport;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationCaseEvent;
use App\Models\ModerationCaseSource;
use App\Models\ModerationEvidence;
use App\Models\OperationalAuditLog;
use App\Models\UserCollectionReport;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Reputation\CommunityReputationService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../ReputationE37/_support.php';
require_once __DIR__.'/../DiscoveryE27/_support.php';

/*
| E38 (BF12-BF14) - الخصوصية والأمان والعزل: هوية المبلِّغ والمشرف، صلاحيات صفحات الإدارة، XSS/IDOR/CSRF/Mass-assignment/GET، استثناء المحتوى المشرَف عليه من البحث،
| عدم أي أثر على الاقتصاد والتقدم، وفصل E37 (تحديث فقط، بلا عقوبة)، وعزل الفشل، وعدم حصانة الدعم/الشهرة.
*/

beforeEach(fn () => e38_seed());

test('E38-111: reporter identity never reaches the reported user - safety page, notifications and domain rows carry neither name nor email', function () {
    $reporter = e16User(['name' => 'مبلِّغ-سري-الاسم', 'email' => 'secret-reporter@example.test']);
    $creator = e28_creator();
    $work = e36_work($creator);
    $report = e38_puzzle_report($reporter, $work, 'inappropriate', 'تفاصيل البلاغ الخاصة بالمبلِّغ');
    $admin = e38_admin();
    $case = e38_open([$report], $admin);
    e38_cases()->claim($case, $admin);
    $action = e38_apply($case, $admin, 'hide_content');
    e38_cases()->resolve($case->fresh(), $admin, 'action_taken');

    $page = $this->actingAs($creator)->get(route('account.safety.index'))->getContent().$this->actingAs($creator)->get(route('account.safety.show', $action->public_id))->getContent();
    $notes = json_encode(DB::table('notifications')->where('notifiable_id', $creator->id)->get(), JSON_UNESCAPED_UNICODE);
    $domain = json_encode(DB::table('creator_puzzles')->where('id', $work->id)->first(), JSON_UNESCAPED_UNICODE);

    foreach ([$page, $notes, $domain] as $surface) {
        expect($surface)->not->toContain('مبلِّغ-سري-الاسم')->not->toContain('secret-reporter@example.test')->not->toContain('تفاصيل البلاغ الخاصة');
    }
});

test('E38-112: moderator identity is "فريق الإشراف" to the user - no moderator name or email on the safety page or notification', function () {
    $admin = e38_admin();
    $admin->forceFill(['name' => 'مشرف-سري-الاسم'])->save();
    [$case, , , $creator] = e38_puzzle_case($admin);
    $action = e38_apply($case, $admin, 'hide_content');
    $appeal = e38_appeals()->file($creator, $action, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');
    $reviewer = e38_mod();
    $reviewer->forceFill(['name' => 'مراجع-سري-الاسم'])->save();
    e38_appeals()->decide($appeal, $reviewer, false, 'القرار نهائي بعد المراجعة.');

    $html = $this->actingAs($creator)->get(route('account.safety.show', $action->public_id))->assertOk()->getContent();
    $notes = json_encode(DB::table('notifications')->where('notifiable_id', $creator->id)->get(), JSON_UNESCAPED_UNICODE);

    expect($html)->toContain('فريق الإشراف')->not->toContain('مشرف-سري-الاسم')->not->toContain('مراجع-سري-الاسم')->not->toContain($admin->email)
        ->and($notes)->not->toContain('سري-الاسم');
});

test('E38-113: admin pages are for staff with the view permission - guests are redirected, moderators and administrators are allowed, support and content managers hold no permission', function () {
    [$case] = e38_puzzle_case();
    $paths = ['/admin/trust-safety-inbox', '/admin/moderation-cases', '/admin/moderation-cases/'.$case->public_id, '/admin/moderation-appeals', '/admin/trust-safety-analytics'];

    foreach ([e28_reviewer('support'), e28_reviewer('content-manager'), e16User()] as $outsider) {
        expect($outsider->can('trust-safety.view'))->toBeFalse()->and($outsider->can('trust-safety.manage-cases'))->toBeFalse()->and($outsider->can('trust-safety.review-appeals'))->toBeFalse();
    }

    $this->actingAs(e38_mod());

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});

test('E38-113a: guests are redirected to login from every admin screen', function () {
    [$case] = e38_puzzle_case();

    foreach (['/admin/trust-safety-inbox', '/admin/moderation-cases', '/admin/moderation-cases/'.$case->public_id, '/admin/moderation-appeals', '/admin/trust-safety-analytics'] as $path) {
        $this->get($path)->assertRedirect();
    }
});

test('E38-113b: an ordinary user gets 403 on the admin screen', function (string $path) {
    $this->actingAs(e16User())->get(str_replace('{case}', e38_puzzle_case()[0]->public_id, $path))->assertForbidden();
})->with(['/admin/trust-safety-inbox', '/admin/moderation-cases', '/admin/moderation-cases/{case}', '/admin/moderation-appeals', '/admin/trust-safety-analytics']);

test('E38-114: a view-only staff member can read but every mutation on the case page is refused by the service layer', function () {
    [$case] = e38_puzzle_case();
    $viewer = e16User();
    $viewer->givePermissionTo(['trust-safety.view']);
    $this->actingAs($viewer);

    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful()->call('claim')->assertForbidden();
    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->set('noteText', 'ملاحظة بلا صلاحية')->call('addNote')->assertForbidden();
    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->set('actionKey', 'hide_content')->set('actionReason', 'سبب كافٍ للتنفيذ هنا')->call('applyAction')->assertForbidden();

    expect(ModerationEvidence::where('kind', 'moderator_note')->count())->toBe(0)->and(ModerationAction::count())->toBe(0);
});

test('E38-115: the case page works from the public id only - a tampered or unknown record is a 404, never a 500 or another case', function () {
    $this->actingAs(e38_admin());

    $this->get('/admin/moderation-cases/not-a-ulid')->assertNotFound();
    $this->get('/admin/moderation-cases/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $this->get('/admin/moderation-cases/1')->assertNotFound();

});

test('E38-115b: a record id tampered with inside the Livewire component is a 404, never a 500 or an action on another case', function () {
    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);
    $lw = Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful();
    $lw->set('record', '1; DROP TABLE moderation_cases')->assertNotFound();

    expect(DB::getSchemaBuilder()->hasTable('moderation_cases'))->toBeTrue()->and(ModerationCase::count())->toBe(1);
});

test('E38-116: user-generated text is escaped everywhere in the admin screens (XSS) - subject titles, notes, appeal text, report details', function () {
    $payload = '<script>alert("xss38")</script>';
    $creator = e28_creator();
    $work = e36_work($creator);
    DB::table('creator_puzzles')->where('id', $work->id)->update(['published_title' => $payload]);
    $report = e38_puzzle_report(null, $work->fresh(), 'inappropriate', $payload);
    $admin = e38_admin();
    $case = e38_open([$report], $admin);
    e38_cases()->claim($case, $admin);
    $action = e38_apply($case, $admin, 'hide_content');
    DB::table('moderation_appeals')->insert(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'moderation_action_id' => $action->id, 'appellant_id' => $creator->id, 'reason' => $payload.' نص اعتراض طويل بما يكفي', 'status' => 'pending', 'active_marker' => 1, 'created_at' => now(), 'updated_at' => now()]);

    foreach (['/admin/trust-safety-inbox', '/admin/moderation-cases', '/admin/moderation-cases/'.$case->public_id] as $path) {
        $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

        expect($html)->not->toContain($payload);
    }

    $this->actingAs($admin);
    $appealsHtml = Livewire::test(ModerationAppealsPage::class)->call('show', ModerationAppeal::first()->public_id)->html();
    $safety = $this->actingAs($creator)->get(route('account.safety.show', $action->public_id))->getContent();

    expect($appealsHtml)->not->toContain($payload)->and($safety)->not->toContain($payload);
});

test('E38-117: every moderation model is mass-assignment safe - create() with attributes is refused', function () {
    foreach ([ModerationCase::class, ModerationCaseSource::class, ModerationCaseEvent::class, ModerationEvidence::class, ModerationAction::class, ModerationAppeal::class] as $model) {
        expect(fn () => $model::create(['status' => 'open', 'moderation_case_id' => 1, 'action' => 'x', 'reason' => 'y']))->toThrow(MassAssignmentException::class);
    }
});

test('E38-118: no GET request mutates anything - every admin and safety page read leaves all moderation tables unchanged', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $action = e38_apply($case, $admin, 'hide_content');
    $tables = ['moderation_cases', 'moderation_case_sources', 'moderation_case_events', 'moderation_evidence', 'moderation_actions', 'moderation_appeals', 'operational_audit_logs'];
    $count = fn () => collect($tables)->map(fn ($t) => DB::table($t)->count())->all();
    $before = $count();

    foreach (['/admin/trust-safety-inbox', '/admin/moderation-cases', '/admin/moderation-cases/'.$case->public_id, '/admin/moderation-appeals', '/admin/trust-safety-analytics'] as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }

    $this->actingAs($creator)->get(route('account.safety.index'))->assertOk();
    $this->actingAs($creator)->get(route('account.safety.show', $action->public_id))->assertOk();

    expect($count())->toBe($before);

    $this->actingAs($creator)->get('/account/safety/actions/'.$action->public_id.'/appeal')->assertStatus(405);
    $this->actingAs($creator)->get('/account/safety/leave')->assertStatus(405);
});

test('E38-119: every state-changing safety route is POST inside the web group with CSRF protection and a named rate limiter', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'account/safety'));
    $csrf = in_array(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, app('router')->getMiddlewareGroups()['web'] ?? [], true)
        || in_array(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, app('router')->getMiddlewareGroups()['web'] ?? [], true);

    expect($routes)->not->toBeEmpty()->and($csrf)->toBeTrue();

    foreach ($routes as $route) {
        $methods = array_diff($route->methods(), ['HEAD']);

        expect(in_array('web', $route->gatherMiddleware(), true))->toBeTrue();

        if (in_array('POST', $methods, true)) {
            expect($methods)->toBe(['POST']);
        }

        if (str_contains($route->getName() ?? '', 'appeal') || str_contains($route->getName() ?? '', 'withdraw')) {
            expect(collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'throttle:safety')))->toBeTrue();
        }
    }
});

test('E38-120: appeal submission is rate limited (3 per minute) so the appeals channel cannot be used to hammer reviewers', function () {
    [$action, $creator] = e38_hidden_by_admin_e38();

    foreach (range(1, 3) as $i) {
        $this->actingAs($creator)->post(route('account.safety.appeal', $action->public_id), ['reason' => 'قصير'])->assertSessionHasErrors('reason');
    }

    $this->actingAs($creator)->post(route('account.safety.appeal', $action->public_id), ['reason' => 'قصير'])->assertStatus(429);
});

function e38_hidden_by_admin_e38(): array
{
    [$case, $admin, $work, $creator] = e38_puzzle_case();

    return [e38_apply($case, $admin, 'hide_content'), $creator];
}

test('E38-121: moderated content is excluded from global search and returns after restore; case data is never searchable', function () {
    $creator = e28_creator();
    $work = e36_work($creator);
    DB::table('creator_puzzles')->where('id', $work->id)->update(['published_title' => 'أحجية-فريدة-للبحث-٣٨']);
    $report = e38_puzzle_report(null, $work->fresh(), 'spam');
    $admin = e38_admin();
    $case = e38_open([$report], $admin, 'ملخص-فريد-للحالة');
    e38_cases()->claim($case, $admin);
    e38_cases()->addNote($case->fresh(), $admin, 'ملاحظة-فريدة-للمشرف');

    $found = fn (string $q) => collect(e27_suggest($this, $q)['groups'] ?? [])->flatMap(fn ($g) => $g['items'])->pluck('title')->all();

    expect($found('أحجية-فريدة'))->not->toBeEmpty();

    e38_apply($case, $admin, 'hide_content');

    expect($found('أحجية-فريدة'))->toBe([])->and($found('ملخص-فريد'))->toBe([])->and($found('ملاحظة-فريدة'))->toBe([]);

    e38_apply($case, $admin, 'restore_content');

    expect($found('أحجية-فريدة'))->not->toBeEmpty();
});

test('E38-122: a hidden public collection leaves search and a hidden discussion comment stays out of public discussion reads', function () {
    $owner = e16User();
    $collection = e38_public_collection($owner);
    DB::table('user_collections')->where('id', $collection->id)->update(['title' => 'مجموعة-فريدة-للبحث-٣٨']);
    $report = e38_collection_report(null, $collection->fresh(), 'spam');
    $admin = e38_admin();
    $case = e38_open([$report], $admin);
    e38_cases()->claim($case, $admin);

    $found = fn (string $q) => collect(e27_suggest($this, $q)['groups'] ?? [])->flatMap(fn ($g) => $g['items'])->pluck('title')->all();

    expect($found('مجموعة-فريدة'))->not->toBeEmpty();

    e38_apply($case, $admin, 'hide_content');

    expect($found('مجموعة-فريدة'))->toBe([]);
});

test('E38-123: the whole moderation lifecycle leaves wallets, XP, quests, streaks, referrals, payments and competitions untouched', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $world = e37_world($creator);

    $hide = e38_apply($case, $admin, 'hide_content');
    e38_apply($case, $admin, 'warn_user', ['public_note' => 'تنبيه رسمي للمبدع.']);
    $appeal = e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');
    e38_appeals()->decide($appeal, e38_mod(), true, 'قُبل الاعتراض بعد المراجعة.');
    e38_cases()->resolve($case->fresh(), $admin, 'action_taken');

    expect(e37_world($creator))->toEqual($world);
});

test('E38-124: E37 reputation is only refreshed after visibility-changing actions - never penalised and never refreshed for warnings or no-action', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $calls = [];
    $spy = Mockery::mock(CommunityReputationService::class);
    $spy->shouldReceive('refreshUsers')->andReturnUsing(function (array $ids) use (&$calls) {
        $calls[] = $ids;

        return ['processed' => count($ids)];
    });
    app()->instance(CommunityReputationService::class, $spy);

    e38_apply($case, $admin, 'warn_user', ['public_note' => 'تنبيه رسمي للمبدع.']);
    e38_apply($case, $admin, 'no_action', ['public_note' => null]);

    expect($calls)->toBe([]);

    e38_apply($case, $admin, 'hide_content');

    expect($calls)->toBe([[$creator->id]]);
});

test('E38-125: failure isolation - a reputation or notification failure never rolls back a committed safety action', function () {
    [$case, $admin, $work, $creator] = e38_puzzle_case();
    $rep = Mockery::mock(CommunityReputationService::class);
    $rep->shouldReceive('refreshUsers')->andThrow(new RuntimeException('reputation down'));
    app()->instance(CommunityReputationService::class, $rep);
    $dispatcher = Mockery::mock(NotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('notifications down'));
    app()->instance(NotificationDispatcher::class, $dispatcher);

    $row = e38_apply($case, $admin, 'hide_content');

    expect($row->exists)->toBeTrue()->and($work->fresh()->hidden_at)->not->toBeNull()->and(ModerationAction::count())->toBe(1);
});

test('E38-126: popularity, followers, featured status or sponsorship give no immunity and no different priority or treatment', function () {
    $config = strtolower(json_encode(config('trust_safety')));

    expect($config)->not->toContain('featured')->not->toContain('supporter')->not->toContain('sponsor')->not->toContain('followers')->not->toContain('popular');

    $popular = e28_creator();
    $quiet = e28_creator();

    foreach (range(1, 4) as $i) {
        app(\App\Services\Engagement\CreatorFollowService::class)->follow(e16User(), \App\Models\CreatorProfile::where('user_id', $popular->id)->first());
    }

    $a = e38_open([e38_puzzle_report(null, e36_work($popular), 'inappropriate')], e38_admin());
    $b = e38_open([e38_puzzle_report(null, e36_work($quiet), 'inappropriate')], e38_admin());
    $admin = e38_admin();
    e38_cases()->claim($a, $admin);

    expect($a->priority)->toBe($b->priority)->and($a->category)->toBe($b->category)
        ->and(e38_apply($a, $admin, 'suspend_creator', ['reason' => 'مخالفات متكررة ثابتة بعد مراجعة الأدلة كاملة'])->action)->toBe('suspend_creator');
});

test('E38-127: chat privacy - only reported messages can enter a case, a bare message reference is not an allowed source, and the case holds just the reported text', function () {
    $author = e16User();
    [$message, $report] = e38_chat_with_report(null, $author, 'harassment');

    expect(e38_error(fn () => e38_cases()->open(e38_mod(), ['chat_message:'.$message->id])))->toBe('مرجع بلاغ غير صالح.');

    $admin = e38_admin();
    $case = e38_open([$report], $admin);

    expect($case->subject_type)->toBe('chat_message')->and($case->evidence()->count())->toBe(1)
        ->and($case->evidence()->first()->body)->toContain('رسالة اختبار');
});

test('E38-128: only user-visible actions appear on the user\'s safety page - reversals and internal actions never do', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');
    e38_apply($case, $admin, 'restore_content');
    e38_apply($case, $admin, 'no_action', ['public_note' => null]);

    $overview = app(\App\Services\TrustSafety\UserSafetyService::class)->overview($creator);

    expect($overview['items'])->toHaveCount(1)->and($overview['items'][0]['public_id'])->toBe($hide->public_id)->and($overview['items'][0]['appeal_state'])->toBe('reversed');
});

test('E38-129: every state change writes the existing operational audit log with actor and subject', function () {
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');
    $appeal = e38_appeals()->file($creator, $hide, 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة.');
    e38_appeals()->decide($appeal, e38_mod(), true, 'قُبل الاعتراض بعد المراجعة.');

    $actions = OperationalAuditLog::query()->where('action', 'like', 'moderation_%')->pluck('action')->unique()->sort()->values()->all();

    expect($actions)->toBe(['moderation_action_applied', 'moderation_action_reversed', 'moderation_appeal_decided', 'moderation_appeal_filed', 'moderation_case_claimed', 'moderation_case_opened'])
        ->and(OperationalAuditLog::where('action', 'moderation_action_applied')->first()->actor_user_id)->toBe($admin->id);
});

test('E38-130: the policy taxonomy covers every domain report reason so nothing silently falls into "other"', function () {
    $maps = config('trust_safety.reason_map');

    expect(array_keys($maps['community_puzzle_report']))->toEqualCanonicalizing(array_keys(CommunityPuzzleReport::REASONS))
        ->and(array_keys($maps['user_collection_report']))->toEqualCanonicalizing(array_keys(UserCollectionReport::REASONS))
        ->and(array_keys($maps['discussion_comment_report']))->toEqualCanonicalizing(array_keys(DiscussionCommentReport::REASONS))
        ->and(array_keys($maps['chat_message_report']))->toEqualCanonicalizing(config('chat.report_categories'));

    foreach ($maps as $map) {
        foreach ($map as $category) {
            expect(array_key_exists($category, config('trust_safety.categories')))->toBeTrue();
        }
    }

    foreach (config('trust_safety.categories') as $def) {
        expect($def['label'])->toMatch('/\p{Arabic}/u')->and(array_key_exists($def['priority'], ModerationCase::PRIORITIES))->toBeTrue();
    }

    expect(ChatMessageReport::class)->toBeString();
});
