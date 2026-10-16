<?php

use App\Models\CommunityPuzzleReport;
use App\Models\OperationalAuditLog;
use App\Services\Creators\CommunityReportService;
use App\Services\Creators\CreatorException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

test('Y7-57 (57): a verified user reports a community puzzle with a closed reason', function () {
    $work = e28_published(e28_creator());
    $reporter = e16User();

    $this->actingAs($reporter)->post(route('community.puzzles.report', $work->public_id), ['reason' => 'misleading', 'details' => 'السؤال مضلل برأيي.'])->assertSessionHas('success');

    $report = CommunityPuzzleReport::query()->firstOrFail();
    expect($report->status)->toBe('open')->and($report->reason)->toBe('misleading')->and($report->reporter_id)->toBe($reporter->id)->and($report->creator_puzzle_id)->toBe($work->id)->and($report->puzzle_id)->toBe($work->published_puzzle_id);

    // سبب خارج القائمة المغلقة مرفوض، والضيف يُحوَّل لتسجيل الدخول.
    $this->actingAs(e16User())->post(route('community.puzzles.report', $work->public_id), ['reason' => 'because'])->assertSessionHasErrors('reason');
    auth()->logout();
    $this->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertRedirect(route('login'));
    expect(CommunityPuzzleReport::query()->count())->toBe(1);
});

test('Y7-58 (58): a creator cannot report their own puzzle', function () {
    $creator = e28_creator();
    $work = e28_published($creator);

    $this->actingAs($creator)->post(route('community.puzzles.report', $work->public_id), ['reason' => 'spam'])->assertSessionHas('error');
    expect(CommunityPuzzleReport::query()->count())->toBe(0)
        ->and(fn () => app(CommunityReportService::class)->report($creator, $work, 'spam', null))->toThrow(CreatorException::class);
});

test('Y7-59 (59): one open report per user per publication - service and database constraint', function () {
    $work = e28_published(e28_creator());
    $reporter = e16User();
    $svc = app(CommunityReportService::class);

    $svc->report($reporter, $work, 'broken', null);
    expect(fn () => $svc->report($reporter, $work, 'spam', null))->toThrow(CreatorException::class)->and(CommunityPuzzleReport::query()->count())->toBe(1);

    // طبقة قاعدة البيانات تحمي حتى لو تجاوزت الخدمة (سباق متزامن).
    expect(fn () => DB::table('community_puzzle_reports')->insert(['creator_puzzle_id' => $work->id, 'reporter_id' => $reporter->id, 'reason' => 'spam', 'status' => 'open', 'open_marker' => 1, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);

    // بعد الإغلاق يجوز بلاغ جديد.
    $svc->dismiss(CommunityPuzzleReport::query()->firstOrFail(), e28_reviewer(), 'لا مخالفة');
    $svc->report($reporter, $work, 'spam', null);
    expect(CommunityPuzzleReport::query()->count())->toBe(2)->and(CommunityPuzzleReport::query()->where('status', 'open')->count())->toBe(1);
});

test('Y7-60 (60): a moderator handles a report - dismiss and action, with handler and note; unauthorized users cannot', function () {
    $work = e28_published(e28_creator());
    $svc = app(CommunityReportService::class);
    $first = $svc->report(e16User(), $work, 'spam', null);
    $second = $svc->report(e16User(), $work, 'broken', 'لا يعمل الحل');
    $moderator = e28_reviewer();

    expect(fn () => $svc->dismiss($first, e16User()))->toThrow(HttpException::class);

    $svc->dismiss($first, $moderator, 'لا مخالفة ظاهرة');
    $svc->action($second, $moderator, 'تم التواصل مع المبدع');

    expect($first->fresh()->status)->toBe('dismissed')->and($first->fresh()->handled_by)->toBe($moderator->id)->and($first->fresh()->handler_note)->toBe('لا مخالفة ظاهرة')->and($first->fresh()->open_marker)->toBeNull()
        ->and($second->fresh()->status)->toBe('actioned')
        ->and(fn () => $svc->dismiss($first->fresh(), $moderator))->toThrow(CreatorException::class);
    expect($work->fresh()->is_visible)->toBeTrue();   // المعالجة وحدها لا تخفي
});

test('Y7-61 (61): handling a report with hide removes the publication and closes every open report on it', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'لغز مُبلَّغ عنه']);
    $svc = app(CommunityReportService::class);
    $a = $svc->report(e16User(), $work, 'inappropriate', null);
    $b = $svc->report(e16User(), $work, 'copyright', null);

    $svc->actionAndHide($a, e28_reviewer(), 'محتوى مخالف تم التأكد منه.');

    $work->refresh();
    expect($work->is_visible)->toBeFalse()->and($work->isHidden())->toBeTrue()->and($work->hidden_reason)->toBe('moderation')->and($work->hidden_note)->toBe('محتوى مخالف تم التأكد منه.')
        ->and($a->fresh()->status)->toBe('actioned')->and($b->fresh()->status)->toBe('actioned')->and(CommunityPuzzleReport::query()->where('status', 'open')->count())->toBe(0);
    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();

    // المبدع يرى السبب في الاستوديو ويُشعَر.
    $this->actingAs($creator)->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->assertSee('أخفى فريق الإشراف')->assertSee('محتوى مخالف تم التأكد منه.');
    expect($creator->notifications()->count())->toBeGreaterThanOrEqual(3);   // اعتماد مبدع + اعتماد أحجية + إخفاء
});

test('Y7-62 (62): hiding and report handling are audited with actor and metadata', function () {
    $work = e28_published(e28_creator());
    $svc = app(CommunityReportService::class);
    $report = $svc->report(e16User(), $work, 'spam', null);
    $moderator = e28_reviewer();
    $svc->actionAndHide($report, $moderator, 'سبب إخفاء موثّق بالتدقيق.');

    $hidden = OperationalAuditLog::query()->where('action', 'community_puzzle_hidden')->firstOrFail();
    $actioned = OperationalAuditLog::query()->where('action', 'community_report_actioned')->firstOrFail();

    expect($hidden->actor_user_id)->toBe($moderator->id)->and($hidden->subject_id)->toBe($work->id)->and($hidden->metadata['note'])->toBe('سبب إخفاء موثّق بالتدقيق.')
        ->and($actioned->actor_user_id)->toBe($moderator->id)->and($actioned->subject_id)->toBe($report->id)->and($actioned->metadata['hide'])->toBeTrue();

    app(\App\Services\Creators\CreatorVisibilityService::class)->restore($work->fresh(), $moderator);
    expect(OperationalAuditLog::query()->where('action', 'community_puzzle_restored')->where('actor_user_id', $moderator->id)->exists())->toBeTrue();
});

test('Y7-63 (63): a hidden publication disappears from Search and Discover immediately', function () {
    $work = e28_published(e28_creator(), 'text', ['title' => 'نمط خفي قريبًا']);

    expect(e27_titles(e27_suggest($this, 'نمط')))->toContain('نمط خفي قريبًا');
    $this->get(route('discover.index'))->assertSee('نمط خفي قريبًا');

    app(\App\Services\Creators\CreatorVisibilityService::class)->hide($work, e28_reviewer(), 'إخفاء إشرافي لأغراض الاختبار.');

    expect(e27_titles(e27_suggest($this, 'نمط')))->not->toContain('نمط خفي قريبًا');
    $this->get(route('search.index', ['q' => 'نمط خفي']))->assertOk()->assertDontSee('نمط خفي قريبًا');
    $this->get(route('discover.index'))->assertOk()->assertDontSee('نمط خفي قريبًا');
});
