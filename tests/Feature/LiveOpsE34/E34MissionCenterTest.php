<?php

use App\Models\MissionPool;
use App\Models\PlayerStreak;
use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\UserMissionAssignment;
use App\Models\UserQuestProgress;
use App\Services\Engagement\QuestPeriodService;
use App\Services\Engagement\QuestService;
use App\Services\LiveOps\MissionCenterService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e34_seed());
afterEach(fn () => Carbon::setTestNow());

const E34_PRESSURE = ['اشترِ', 'Claim Reward', 'طالب بالمكافأة', 'ستفقد', 'باقي دقائق', 'ادفع للحفاظ', 'آخر فرصة', 'سارع'];

test('Z1-01: /missions requires login, /quests stays reachable (200, no redirect) and both serve the mission center', function () {
    $this->get('/missions')->assertRedirect(route('login'));
    $this->get('/quests')->assertRedirect(route('login'));

    $u = e34_user();
    e34_quest(['name' => 'مهمة المركز']);

    $this->actingAs($u)->get('/missions')->assertOk()->assertSee('مهمة المركز');
    $this->actingAs($u)->get('/quests')->assertOk()->assertSee('مهمة المركز');

    expect(route('missions.index', [], false))->toBe('/missions')->and(route('quests.show', [], false))->toBe('/quests');
});

test('Z1-02: the page has sections for daily and weekly missions and exactly one h1', function () {
    $u = e34_user();
    e34_quest(['name' => 'يومية أ']);
    e34_weekly(['name' => 'أسبوعية أ']);

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect($html)->toContain('يومية أ')->toContain('أسبوعية أ')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('Z1-03: official solves advance a global mission and the card shows percent and in-progress state with a real remaining time', function () {
    $u = e34_user();
    e34_quest(['name' => 'ثلاث أحجيات', 'target_value' => 4]);

    e34_solve($u);
    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    expect($html)->toContain('ثلاث أحجيات')->toContain('قيد التقدّم')->toContain('25%')->toContain('الوقت المتبقي');
});

test('Z1-04: a granted reward is labelled as granted and a completed-but-unpaid mission keeps the existing processing label', function () {
    $u = e34_user();
    $done = e34_quest(['name' => 'منجزة ومُمنوحة', 'xp_reward' => 5]);
    $pending = e34_quest(['name' => 'منجزة قيد المعالجة', 'xp_reward' => 5]);
    $quests = app(QuestService::class);
    $period = app(QuestPeriodService::class)->dailyContext();

    $a = $quests->progressFor($u, $done, $period);
    $b = $quests->progressFor($u, $pending, $period);
    UserQuestProgress::query()->whereKey($a->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => now()]);
    UserQuestProgress::query()->whereKey($b->id)->update(['current_value' => 1, 'completed_at' => now(), 'reward_granted_at' => null]);

    // قراءة بلا مزامنة: الحالة الوسيطة تُعرض بنص E13 الحالي (فتح الصفحة الفعلي يشفي المكافأة المعلّقة ذاتيًا كما كان).
    $rows = app(MissionCenterService::class)->rowsFor($u, 'daily', false)->keyBy(fn ($r) => $r['quest']->id);
    expect($rows[$done->id]['status'])->toBe(MissionCenterService::STATUS_REWARD_GRANTED)
        ->and($rows[$pending->id]['status'])->toBe(MissionCenterService::STATUS_COMPLETED);

    $card = fn ($row) => view('quests._quest-card', ['quest' => $row['quest'], 'progress' => $row['progress'], 'percent' => $row['percent'], 'remaining' => $row['remaining']])->render();
    expect($card($rows[$done->id]))->toContain('تم منح المكافأة')
        ->and($card($rows[$pending->id]))->toContain('جارٍ معالجة المكافأة');

    // الصفحة الفعلية: تُظهر "تم منح المكافأة" وتشفي المعلّقة (لا تبقى معلّقة بعد الفتح).
    $this->actingAs($u)->get('/missions')->assertOk()->assertSee('تم منح المكافأة');
    expect(UserQuestProgress::query()->whereKey($b->id)->value('reward_granted_at'))->not->toBeNull();
});

test('Z1-05: opening the page lazily creates progress and assignments for this user only, and only for visible missions', function () {
    $a = e34_user();
    $b = e34_user();
    e34_quest(['name' => 'عامة']);
    e34_pool(e34_quests(4), ['selection_count' => 2]);

    $this->actingAs($a)->get('/missions')->assertOk();

    expect(UserQuestProgress::query()->where('user_id', $a->id)->count())->toBe(3)   // عامة + مهمتان مُسندتان
        ->and(UserMissionAssignment::query()->where('user_id', $a->id)->count())->toBe(2)
        ->and(UserQuestProgress::query()->where('user_id', $b->id)->count())->toBe(0)
        ->and(UserMissionAssignment::query()->where('user_id', $b->id)->count())->toBe(0);
});

test('Z1-06: no pressure wording, no emoji in the heading and no inline hex colours on the page', function () {
    $u = e34_user();
    e34_quest();
    e34_streak($u, 3, 8, now()->subDays(12)->toDateString()); // يعرض أيضًا بطاقة العودة

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();

    foreach (E34_PRESSURE as $phrase) {
        expect($html)->not->toContain($phrase);
    }

    preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $html, $m);
    expect(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $m[1] ?? ''))->toBe(0)
        ->and(preg_match('/#[0-9a-fA-F]{6}\b/', strip_tags($m[0] ?? '')))->toBe(0);
});

test('Z1-07: remaining time is formatted from real dates (days/hours/minutes) and is null once ended', function () {
    $now = Carbon::parse('2026-10-10 12:00:00', 'UTC');

    expect(MissionCenterService::formatRemaining($now, $now->copy()->addDays(2)->addHours(3)))->toBe('2 يوم و3 ساعة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->addHours(5)->addMinutes(20)))->toBe('5 ساعة و20 دقيقة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->addSeconds(30)))->toBe('1 دقيقة')
        ->and(MissionCenterService::formatRemaining($now, $now->copy()->subSecond()))->toBeNull()
        ->and(MissionCenterService::formatRemaining($now, $now))->toBeNull();
});

test('Z1-08: the streak panel shows the effective streak (a broken streak shows 0) while the longest streak is kept, with no punishment wording', function () {
    $u = e34_user();
    e34_quest();
    e34_streak($u, 5, 9, now()->subDays(4)->toDateString());

    $data = app(MissionCenterService::class)->forUser($u, false);

    expect($data['milestones']['current'])->toBe(0)->and($data['milestones']['longest'])->toBe(9)
        ->and(PlayerStreak::query()->where('user_id', $u->id)->value('current_streak'))->toBe(5); // لا كتابة ولا تصفير مخزَّن

    $html = $this->actingAs($u)->get('/missions')->assertOk()->getContent();
    foreach (['ستفقد', 'خسرت سلسلتك', 'انقطعت'] as $bad) {
        expect($html)->not->toContain($bad);
    }
});

test('Z1-09: the home widget shows at most three missions, reads only, and writes no progress rows', function () {
    $u = e34_user();
    e34_quests(5);
    expect(UserQuestProgress::query()->count())->toBe(0);

    $html = $this->actingAs($u)->get('/')->assertOk()->getContent();

    expect(UserQuestProgress::query()->count())->toBe(0);

    $widget = app(MissionCenterService::class)->widgetFor($u);
    expect($widget['missions']->count())->toBeLessThanOrEqual(3)->and($widget['total'])->toBe(5)->and($widget['completed'])->toBe(0);
    expect($html)->toContain('data-missions-widget');
});
