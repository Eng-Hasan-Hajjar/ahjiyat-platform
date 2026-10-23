<?php

use App\Filament\Pages\ModerationAppealsPage;
use App\Filament\Pages\ModerationCasePage;
use App\Filament\Pages\ModerationCasesPage;
use App\Filament\Pages\SafetyAnalyticsPage;
use App\Filament\Pages\TrustSafetyInboxPage;
use App\Models\CreatorPuzzle;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationCaseSource;
use App\Models\OperationalAuditLog;
use Database\Seeders\DemoE38Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../ReputationE37/_support.php';

/*
| E38 (BF16) - التشغيل: مصالحة moderation:reconcile، تدفقات صفحات Filament عبر Livewire، التحليلات الإدارية (بلا ترتيب أفراد)، السييدر، حدود الاستعلامات.
*/

beforeEach(fn () => e38_seed());

const E38_OPS_APPEAL = 'أرجو إعادة النظر في هذا الإجراء لأنه لا يستند إلى مخالفة واضحة من جهتي.';

function e38_reconcile(array $options = []): array
{
    $code = Artisan::call('moderation:reconcile', $options);

    return [$code, Artisan::output()];
}

/** @return array{0: ModerationCase, 1: \App\Models\User, 2: ModerationAppeal, 3: ModerationAction} */
function e38_filed_appeal(): array
{
    [$case, $admin, , $creator] = e38_puzzle_case();
    $hide = e38_apply($case, $admin, 'hide_content');

    return [$case->fresh(), $admin, e38_appeals()->file($creator, $hide, E38_OPS_APPEAL), $hide];
}

function e38_history_counts(): array
{
    return [
        'events' => DB::table('moderation_case_events')->count(), 'evidence' => DB::table('moderation_evidence')->count(),
        'actions' => DB::table('moderation_actions')->count(), 'appeals' => DB::table('moderation_appeals')->count(), 'cases' => DB::table('moderation_cases')->count(),
    ];
}

/** يعدّ الاستعلامات التي ينفّذها callback. */
function e38_queries(callable $call): int
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $call();

    return $n;
}

// ───────────────────────────── moderation:reconcile ─────────────────────────────

test('E38-140: reconcile on a consistent layer reports no drift and exits 0 - and an empty database is consistent too', function () {
    [$code, $out] = e38_reconcile();

    expect($code)->toBe(0)->and($out)->toContain('متّسقة');

    [$case, $admin, , $creator] = e38_puzzle_case();
    e38_appeals()->file($creator, e38_apply($case, $admin, 'hide_content'), E38_OPS_APPEAL);
    [$code, $out] = e38_reconcile();

    expect($code)->toBe(0)->and($out)->toContain('متّسقة');
});

test('E38-141: reconcile is read-only by default - it reports injected integrity drift with exit 1 and changes nothing', function () {
    [$case, $admin, $appeal, $hide] = e38_filed_appeal();
    DB::table('moderation_appeals')->where('id', $appeal->id)->update(['status' => 'rejected']);                 // قرار بلا تحرير القيد
    DB::table('moderation_actions')->where('id', $hide->id)->update(['reversed_at' => now()->toDateTimeString()]); // معكوس ويحتجز قيده
    $before = [e38_history_counts(), DB::table('moderation_appeals')->get()->all(), DB::table('moderation_actions')->get()->all()];

    [$code, $out] = e38_reconcile();

    expect($code)->toBe(1)->and($out)->toContain('appeal_marker_decided_with_hold')->toContain('action_marker_reversed')
        ->and([e38_history_counts(), DB::table('moderation_appeals')->get()->all(), DB::table('moderation_actions')->get()->all()])->toEqual($before)
        ->and(OperationalAuditLog::where('action', 'moderation_reconciled')->count())->toBe(0);
});

test('E38-142: reconcile --fix repairs only derived markers and case numbers - history, domain state and statuses are untouched, one audit row is written', function () {
    [$case, $admin, $appeal, $hide] = e38_filed_appeal();
    $number = $case->case_number;
    DB::table('moderation_appeals')->where('id', $appeal->id)->update(['status' => 'rejected']);
    DB::table('moderation_actions')->where('id', $hide->id)->update(['reversed_at' => now()->toDateTimeString()]);
    DB::table('moderation_cases')->where('id', $case->id)->update(['case_number' => null]);
    $work = CreatorPuzzle::query()->where('hidden_reason', CreatorPuzzle::HIDDEN_MODERATION)->first();
    $counts = e38_history_counts();
    $statusBefore = [DB::table('moderation_cases')->value('status'), DB::table('moderation_appeals')->value('status'), $work?->hidden_at?->toDateTimeString()];

    [$code, $out] = e38_reconcile(['--fix' => true]);
    [$again] = e38_reconcile();

    expect($code)->toBe(0)->and($out)->toContain('أُصلح')->and($again)->toBe(0)
        ->and(DB::table('moderation_appeals')->value('active_marker'))->toBeNull()->and(DB::table('moderation_actions')->value('active_marker'))->toBeNull()
        ->and(DB::table('moderation_cases')->value('case_number'))->toBe($number)
        ->and(e38_history_counts())->toEqual($counts)
        ->and([DB::table('moderation_cases')->value('status'), DB::table('moderation_appeals')->value('status'), CreatorPuzzle::query()->where('hidden_reason', CreatorPuzzle::HIDDEN_MODERATION)->first()?->hidden_at?->toDateTimeString()])->toEqual($statusBefore)
        ->and(OperationalAuditLog::where('action', 'moderation_reconciled')->count())->toBe(1);
});

test('E38-143: a closed case that still holds its sources is released by --fix without reopening or resolving anything', function () {
    [$case, $admin] = e38_puzzle_case();
    e38_cases()->resolve($case->fresh(), $admin, 'no_violation', 'لا مخالفة بعد المراجعة');
    DB::table('moderation_case_sources')->where('moderation_case_id', $case->id)->update(['active_marker' => 1]);

    [$check] = e38_reconcile();
    [$fix] = e38_reconcile(['--fix' => true]);

    expect($check)->toBe(1)->and($fix)->toBe(0)
        ->and(ModerationCaseSource::query()->where('active_marker', 1)->count())->toBe(0)
        ->and(ModerationCase::first()->status)->toBe('resolved');
});

test('E38-144: drift that needs human judgement is reported but never auto-fixed - domain restored outside the layer, resolved without a resolution', function () {
    [$case, $admin, $work] = e38_puzzle_case();
    e38_apply($case, $admin, 'hide_content');
    DB::table('creator_puzzles')->where('id', $work->id)->update(['hidden_at' => null, 'hidden_reason' => null]); // أعاد أحدهم الظهور خارج الطبقة

    [$driftCode, $driftOut] = e38_reconcile(['--fix' => true]);

    expect($driftCode)->toBe(0)->and($driftOut)->toContain('action_domain_drift')
        ->and(CreatorPuzzle::find($work->id)->hidden_at)->toBeNull();

    DB::table('moderation_cases')->where('id', $case->id)->update(['status' => 'resolved', 'resolution' => null]);
    [$code, $out] = e38_reconcile(['--fix' => true]);

    expect($code)->toBe(1)->and($out)->toContain('resolved_case_missing_resolution')
        ->and(DB::table('moderation_cases')->value('resolution'))->toBeNull();
});

test('E38-145: reconcile is bounded - the limit option caps the sample per check and the command never loads the table', function () {
    foreach (range(1, 4) as $i) {
        [$case, $admin] = e38_puzzle_case();
        e38_cases()->resolve($case->fresh(), $admin, 'no_violation', 'لا مخالفة');
    }

    DB::table('moderation_case_sources')->update(['active_marker' => 1]);
    $result = app(\App\Services\TrustSafety\ModerationReconciler::class)->run(false, 2);

    expect($result['checks']['inactive_case_holds_sources']['count'])->toBe(2)->and($result['integrity'])->toBe(2);
});

// ───────────────────────────── صفحات Filament ─────────────────────────────

test('E38-146: selecting reports in the inbox opens one case with all of them through the official service and redirects to it', function () {
    $mod = e38_mod();
    $work = e36_work(e28_creator());
    $a = e38_puzzle_report(null, $work, 'spam');
    $b = e38_puzzle_report(null, $work, 'inappropriate');
    $this->actingAs($mod);

    Livewire::test(TrustSafetyInboxPage::class)->assertSuccessful()
        ->set('selected', [e38_ref($a), e38_ref($b), e38_ref($a)])->call('openCase')->assertRedirect();

    $case = ModerationCase::first();

    expect(ModerationCase::count())->toBe(1)->and($case->sources()->count())->toBe(2)->and($case->case_number)->toMatch('/^SAFE-\d{4}-\d{6}$/');
});

test('E38-147: the inbox page refuses an empty or foreign selection with a notification, creating nothing', function () {
    $mod = e38_mod();
    $this->actingAs($mod);

    Livewire::test(TrustSafetyInboxPage::class)->set('selected', [])->call('openCase')->assertNoRedirect()
        ->set('selected', ['community_puzzle_report:999999', 'App\\Models\\User:1'])->call('openCase')->assertNoRedirect();

    expect(ModerationCase::count())->toBe(0);
});

test('E38-148: the cases page claims atomically for the moderator and filters by tab and priority', function () {
    $mod = e38_mod();
    $case = e38_open([e38_puzzle_report()], $mod, null, 'urgent');
    e38_open([e38_puzzle_report()], $mod, null, 'low');
    $this->actingAs($mod);

    $lw = Livewire::test(ModerationCasesPage::class)->assertSuccessful()->call('claim', $case->public_id);
    $rows = $lw->instance()->data();

    expect(ModerationCase::find($case->id)->assigned_to)->toBe($mod->id)
        ->and(DB::table('moderation_case_events')->where('moderation_case_id', $case->id)->where('type', 'claimed')->count())->toBe(1);

    $urgent = Livewire::test(ModerationCasesPage::class)->set('priority', 'urgent')->instance()->data();

    expect(collect($urgent['page']->items())->pluck('id')->all())->toBe([$case->id])->and($rows)->toBeArray();
});

test('E38-149: the case page runs the whole moderator flow through services - note, priority, action, resolve - and every step lands in the timeline', function () {
    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);

    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful()
        ->set('noteText', 'ملاحظة داخلية للمراجعة')->call('addNote')->assertSet('noteText', '')
        ->set('priorityValue', 'high')->set('statusNote', 'تكرّر البلاغ')->call('changePriority')
        ->set('actionKey', 'hide_content')->set('actionReason', 'سبب داخلي كافٍ لاتخاذ الإجراء')->set('actionPublicNote', 'مخالفة لإرشادات المجتمع.')->set('actionConfirm', true)->call('applyAction')
        ->set('resolution', 'action_taken')->call('resolve');

    $fresh = $case->fresh();

    expect($fresh->priority)->toBe('high')->and($fresh->status)->toBe('resolved')->and($fresh->resolution)->toBe('action_taken')
        ->and(ModerationAction::where('moderation_case_id', $case->id)->where('action', 'hide_content')->count())->toBe(1)
        ->and(e38_events($fresh))->toContain('note_added', 'priority_changed', 'action_applied', 'resolved');
});

test('E38-150: a service refusal on the case page becomes a Filament notification, not an exception, and changes nothing', function () {
    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);

    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])
        ->set('actionKey', 'hide_content')->set('actionReason', 'قصير')->call('applyAction')->assertSuccessful()
        ->set('resolution', 'action_taken')->call('resolve')->assertSuccessful();

    expect(ModerationAction::count())->toBe(0)->and($case->fresh()->status)->not->toBe('resolved');
});

test('E38-151: the appeals page shows, claims and decides - acceptance restores the content through the authoritative service', function () {
    [$case, $admin, $appeal] = e38_filed_appeal();
    $reviewer = e38_mod();
    $this->actingAs($reviewer);

    Livewire::test(ModerationAppealsPage::class)->assertSuccessful()
        ->call('show', $appeal->public_id)->call('claim')
        ->set('decisionMessage', 'راجعنا الحالة وتبيّن أن الإجراء غير لازم.')->call('decide', true);

    $fresh = $appeal->fresh();

    expect($fresh->status)->toBe('accepted')->and($fresh->reviewer_id)->toBe($reviewer->id)->and($fresh->active_marker)->toBeNull()
        ->and(CreatorPuzzle::query()->where('hidden_reason', CreatorPuzzle::HIDDEN_MODERATION)->count())->toBe(0)
        ->and(ModerationAction::count())->toBe(2);
});

test('E38-152: the appeals page needs a pointed-at appeal and a real decision message - a bare or too-short decision changes nothing, a valid direct decision claims implicitly', function () {
    [, , $appeal] = e38_filed_appeal();
    $reviewer = e38_mod();
    $this->actingAs($reviewer);

    Livewire::test(ModerationAppealsPage::class)->call('decide', true)->assertSuccessful()
        ->call('show', $appeal->public_id)->set('decisionMessage', 'قصير')->call('decide', true)->assertSuccessful();

    expect($appeal->fresh()->status)->toBe('pending')->and(ModerationAction::count())->toBe(1)->and($appeal->fresh()->reviewer_id)->toBeNull();

    Livewire::test(ModerationAppealsPage::class)->call('show', $appeal->public_id)->set('decisionMessage', 'راجعنا الحالة وبقي الإجراء كما هو.')->call('decide', false)->assertSuccessful();

    expect($appeal->fresh()->status)->toBe('rejected')->and($appeal->fresh()->reviewer_id)->toBe($reviewer->id)->and($appeal->fresh()->active_marker)->toBeNull()
        ->and(ModerationAction::count())->toBe(1);
});

// ───────────────────────────── التحليلات ─────────────────────────────

test('E38-153: analytics are aggregate-only - no reporter ranking, no moderator leaderboard, no individual performance anywhere in the payload or the page', function () {
    [$case, $admin] = e38_puzzle_case();
    $reporterName = DB::table('users')->where('id', DB::table('moderation_case_sources')->value('reporter_id'))->value('name');
    e38_apply($case, $admin, 'hide_content');
    e38_cases()->resolve($case->fresh(), $admin, 'action_taken', 'تم الحسم');
    $payload = app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($admin);

    $keys = [];
    array_walk_recursive($payload, function ($v, $k) use (&$keys) {
        $keys[] = (string) $k;
    });
    $flat = strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE));

    expect(array_filter($keys, fn ($k) => preg_match('/reporter(?!s_)|moderator|reviewer|assignee|performed_by|leaderboard|ranking|top_/i', $k)))->toBe([])
        ->and($flat)->not->toContain(strtolower((string) $reporterName))->and($flat)->not->toContain(strtolower($admin->email));

    $this->actingAs($admin);
    $html = Livewire::test(SafetyAnalyticsPage::class)->assertSuccessful()->html();

    expect($html)->not->toContain((string) $reporterName)->and($html)->not->toContain($admin->email);
});

test('E38-154: analytics need trust-safety.view - a user without it is forbidden from the page and the service', function () {
    $support = e16User();
    $support->assignRole('support');

    expect(fn () => app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($support))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs($support)->get('/admin/trust-safety-analytics')->assertForbidden();
});

test('E38-155: the aggregate block is cached briefly while the live block is always read fresh from the services', function () {
    $admin = e38_admin();
    Cache::flush();
    $analytics = app(\App\Services\TrustSafety\SafetyAnalytics::class);
    $first = $analytics->admin($admin);

    e38_open([e38_puzzle_report()], $admin);
    $second = $analytics->admin($admin);

    expect($first['live']['cases_active'])->toBe(0)->and($second['live']['cases_active'])->toBe(1)
        ->and($second['live']['inbox_unlinked'])->toBe(0)
        ->and($second['aggregate']['cases_opened'])->toBe($first['aggregate']['cases_opened']);
});

test('E38-156: response and appeal metrics are computed from real timestamps - averages, acceptance rate and decision time', function () {
    [$case, $admin, $appeal] = e38_filed_appeal();
    $reviewer = e38_mod();
    config(['trust_safety.analytics.cache_seconds' => 0]);
    e38_appeals()->claim($appeal->fresh(), $reviewer);
    e38_appeals()->decide($appeal->fresh(), $reviewer, false, 'الإجراء سليم ولا مبرر لإلغائه.');
    DB::table('moderation_cases')->where('id', $case->id)->update(['created_at' => now()->subHours(6)->toDateTimeString(), 'first_responded_at' => now()->subHours(4)->toDateTimeString(), 'resolved_at' => now()->subHours(2)->toDateTimeString()]);
    DB::table('moderation_appeals')->where('id', $appeal->id)->update(['created_at' => now()->subHours(3)->toDateTimeString(), 'decided_at' => now()->subHour()->toDateTimeString()]);

    $agg = app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($admin)['aggregate'];

    expect($agg['response']['first_response_hours'])->toBe(2.0)->and($agg['response']['resolution_hours'])->toBe(4.0)
        ->and($agg['appeals']['filed'])->toBe(1)->and($agg['appeals']['rejected'])->toBe(1)->and($agg['appeals']['accepted'])->toBe(0)
        ->and($agg['appeals']['acceptance_rate'])->toBe(0.0)->and($agg['appeals']['avg_decision_hours'])->toBe(2.0);
});

test('E38-157: repeat-subject analytics count reports and unique reporters only, ignore system signals, and decide nothing', function () {
    config(['trust_safety.analytics.cache_seconds' => 0]);
    $admin = e38_admin();
    $work = e36_work(e28_creator());
    e38_puzzle_report(null, $work, 'spam');
    e38_puzzle_report(null, $work, 'inappropriate');
    e38_puzzle_report(null, $work, 'other');
    e38_fraud_flag(null, 'multiple_accounts', 'high');
    e38_fraud_flag(null, 'multiple_accounts', 'high');

    $repeat = app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($admin)['aggregate']['repeat_subjects'];

    expect($repeat)->toHaveCount(1)->and($repeat[0])->toMatchArray(['subject_type' => 'community_puzzle', 'report_count' => 3, 'unique_reporters' => 3])
        ->and(array_keys($repeat[0]))->toBe(['subject_type', 'subject_id', 'report_count', 'unique_reporters'])
        ->and(ModerationCase::count())->toBe(0)->and(ModerationAction::count())->toBe(0);
});

// ───────────────────────────── السييدر ─────────────────────────────

test('E38-158: the demo seeder builds real cases, actions and appeals through the services and touches no economy table', function () {
    $this->seed(\Database\Seeders\DemoCreatorSeeder::class);
    $world = e37_world();
    $this->seed(DemoE38Seeder::class);
    $after = e37_world();

    expect(ModerationCase::count())->toBeGreaterThan(1)->and(ModerationAction::count())->toBeGreaterThan(0)->and(ModerationAppeal::count())->toBeGreaterThan(0)
        ->and(DB::table('moderation_case_events')->count())->toBeGreaterThan(ModerationCase::count())
        ->and(array_diff_key($after['counts'], ['fraud_flags' => 1]))->toEqual(array_diff_key($world['counts'], ['fraud_flags' => 1]))
        ->and($after['counts']['fraud_flags'] - $world['counts']['fraud_flags'])->toBe(1)->and($after['wallets'])->toBe($world['wallets'])->and($after['xp'])->toBe($world['xp'])
        ->and(ModerationCase::query()->where('case_number', 'not like', 'SAFE-%')->count())->toBe(0);

    [$code] = e38_reconcile();

    expect($code)->toBe(0);
});

test('E38-159: the demo seeder is idempotent and a second run adds nothing', function () {
    $this->seed(\Database\Seeders\DemoCreatorSeeder::class);
    $this->seed(DemoE38Seeder::class);
    $first = e38_history_counts() + ['users' => DB::table('users')->count(), 'reports' => DB::table('community_puzzle_reports')->count()];

    $this->seed(DemoE38Seeder::class);

    expect(e38_history_counts() + ['users' => DB::table('users')->count(), 'reports' => DB::table('community_puzzle_reports')->count()])->toEqual($first);
});

test('E38-160: the demo seeder refuses production before touching any table', function () {
    $before = e38_history_counts() + ['users' => DB::table('users')->count()];
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoE38Seeder::class)->run())->toThrow(RuntimeException::class, 'ممنوع بالإنتاج');

    app()->detectEnvironment(fn () => 'testing');

    expect(e38_history_counts() + ['users' => DB::table('users')->count()])->toEqual($before);
});

// ───────────────────────────── صلاحيات/إعداد/أداء ─────────────────────────────

test('E38-161: the trust-safety permission set is registered, granted by role as designed, and not granted to unrelated staff', function () {
    $all = ['trust-safety.view', 'trust-safety.manage-cases', 'trust-safety.moderate-content', 'trust-safety.enforce-account', 'trust-safety.review-appeals', 'trust-safety.supervise'];
    $role = fn (string $r) => \Spatie\Permission\Models\Role::findByName($r)->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, 'trust-safety.'))->sort()->values()->all();

    foreach ($all as $permission) {
        expect(\Spatie\Permission\Models\Permission::where('name', $permission)->exists())->toBeTrue();
    }

    expect($role('administrator'))->toBe(collect($all)->sort()->values()->all())
        ->and($role('moderator'))->toBe(['trust-safety.manage-cases', 'trust-safety.moderate-content', 'trust-safety.review-appeals', 'trust-safety.view'])
        ->and($role('support'))->toBe([])->and($role('content-manager'))->toBe([]);
});

test('E38-162: configuration is sane - positive SLA and window values, closed action allow-list with labels, reversals point at real actions, severity only on account actions', function () {
    $c = config('trust_safety');

    foreach ([...array_values($c['sla']['first_response_hours']), ...array_values($c['sla']['resolution_hours']), $c['sla']['due_soon_hours']] as $hours) {
        expect($hours)->toBeInt()->toBeGreaterThan(0);
    }

    $actions = $c['actions'];

    expect($c['analytics']['window_days'])->toBeGreaterThan(0)->and($c['reconcile']['limit'])->toBeGreaterThan(0)
        ->and(array_keys($actions))->toEqual(array_keys(ModerationAction::LABELS))
        ->and(collect($actions)->filter(fn ($a) => $a['severe'])->keys()->sort()->values()->all())->toBe(['freeze_user', 'suspend_creator'])
        ->and($c['reason']['severe_min'])->toBeGreaterThan($c['reason']['min']);

    foreach ($actions as $key => $def) {
        if (isset($def['reverses'])) {
            expect($actions)->toHaveKey($def['reverses'])->and($def['appealable'])->toBeFalse();
        }
    }
});

test('E38-163: query counts of the work queues do not grow with the number of rows - no N+1 in cases, appeals or the inbox', function () {
    $admin = e38_admin();
    $admin->can('trust-safety.view');
    $measure = function () use ($admin) {
        return [
            e38_queries(fn () => e38_cases()->queue($admin, ['view' => 'open'])->items()),
            e38_queries(fn () => e38_appeals()->queue($admin, ['view' => 'active'])->items()),
            e38_queries(fn () => e38_inbox()->paginate($admin, ['per_page' => 50])->items()),
        ];
    };

    foreach (range(1, 2) as $i) {
        e38_filed_appeal();
    }

    $measure(); // تسخين كاش الصلاحيات والمخطط
    $small = $measure();

    foreach (range(1, 6) as $i) {
        e38_filed_appeal();
    }

    $large = $measure();

    expect($large)->toEqual($small)->and(max($large))->toBeLessThanOrEqual(12);
});

test('E38-164: the analytics page stays within a small constant number of queries regardless of data volume', function () {
    $admin = e38_admin();
    config(['trust_safety.analytics.cache_seconds' => 0]);
    $admin->can('trust-safety.view');

    foreach (range(1, 6) as $i) {
        e38_filed_appeal();
    }

    app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($admin);
    $queries = e38_queries(fn () => app(\App\Services\TrustSafety\SafetyAnalytics::class)->admin($admin));

    expect($queries)->toBeLessThanOrEqual(25);
});
