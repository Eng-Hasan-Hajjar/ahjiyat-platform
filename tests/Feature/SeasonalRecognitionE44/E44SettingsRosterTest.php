<?php

use App\Models\CreatorProfile;
use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterSeries;
use App\Services\Creators\CreatorApplicationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E44 / الإعدادات والقائمة: صف إعدادات كسول، المفتاحان العامان المستقلان، نافذة التصويت وقاعدة الأسبوع، سعة 20 افتراضيًا و100 حدًّا مطلقًا،
| القائمة (مبدع معتمد فقط)، ربط السلاسل الصريح، قفل القائمة عند فتح التصويت، خطة الجوائز، والحدود الأمنية للصلاحيات. كل كتابة بالخدمات الرسمية.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── الإعدادات ─────────────────────────────

test('E44-RS-01: the settings row is created lazily with the product defaults and ensure() is idempotent', function () {
    $season = e42_live();
    expect(e44_settings()->settingFor($season))->toBeNull();

    $setting = e44_settings()->ensure($season);

    expect($setting->writers_enabled)->toBeFalse()
        ->and($setting->supporters_enabled)->toBeFalse()
        ->and((int) $setting->writers_capacity)->toBe(20)
        ->and((int) $setting->writers_stars_per_voter)->toBe(10)
        ->and((int) $setting->writers_eliminated_count)->toBe(5)
        ->and($setting->writers_voting_opens_at)->toBeNull()
        ->and(e44_settings()->ensure($season)->id)->toBe($setting->id)
        ->and(SeasonRecognitionSetting::query()->count())->toBe(1);
});

test('E44-RS-02: saving writer settings needs seasonal-recognition.manage (view/vote/audit are not enough)', function () {
    $season = e44_season();

    foreach ([e16User(), e44_staff(['seasonal-recognition.view']), e44_staff(['seasonal-recognition.vote', 'seasonal-recognition.audit'])] as $actor) {
        expect(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['enabled' => true], $actor)))->toBe('forbidden');
    }

    expect(e44_settings()->settingFor($season)->writers_enabled)->toBeFalse();

    e44_settings()->saveWriterSettings($season, ['enabled' => true], e44_staff(['seasonal-recognition.manage']));

    expect(e44_settings()->settingFor($season)->writers_enabled)->toBeTrue()
        ->and(e44_audit('seasonal_writers_settings_saved'))->toBe(1);
});

test('E44-RS-03: a season without a usable end can neither be enabled nor given a closing time', function () {
    $noEnd = e42_season(); // منشور بلا نافذة صريحة وحملة بلا تواريخ
    e44_settings()->ensure($noEnd);

    expect(e44_reason(fn () => e44_settings()->saveWriterSettings($noEnd, ['enabled' => true], e44_admin())))->toBe('no_season_end')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($noEnd, ['opens_at' => '2026-12-11 00:00:00', 'closes_at' => '2026-12-20 00:00:00'], e44_admin())))->toBe('no_season_end')
        ->and(e44_settings()->settingFor($noEnd)->writers_enabled)->toBeFalse()
        ->and(e44_settings()->settingFor($noEnd)->writers_voting_closes_at)->toBeNull();

    $readiness = e44_settings()->readiness($noEnd);

    expect($readiness['ok'])->toBeFalse()
        ->and(collect($readiness['checks'])->firstWhere('key', 'season_end')['ok'])->toBeFalse();
});

test('E44-RS-04: voting must close at least seven days before the effective season end (boundary inclusive, one second later rejected)', function () {
    $season = e44_season(); // ينتهي 2026-12-30 00:00 ⇒ آخر إغلاق 2026-12-23 00:00
    $admin = e44_admin();

    expect(e44_settings()->latestAllowedClose($season)->toDateTimeString())->toBe('2026-12-23 00:00:00')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-11 00:00:00', 'closes_at' => '2026-12-23 00:00:01'], $admin)))->toBe('week_rule')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-11 00:00:00', 'closes_at' => '2026-12-29 00:00:00'], $admin)))->toBe('week_rule')
        ->and(e44_settings()->settingFor($season)->writers_voting_closes_at)->toBeNull();

    $saved = e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-11 00:00:00', 'closes_at' => '2026-12-23 00:00:00'], $admin);

    expect($saved->writers_voting_closes_at->toDateTimeString())->toBe('2026-12-23 00:00:00');
});

test('E44-RS-05: closing before opening, equal times, stars outside 1..10 and capacity outside 1..100 are rejected without a partial write', function () {
    $season = e44_season();
    $admin = e44_admin();

    expect(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-15 00:00:00', 'closes_at' => '2026-12-14 00:00:00'], $admin)))->toBe('window_order')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-15 00:00:00', 'closes_at' => '2026-12-15 00:00:00'], $admin)))->toBe('window_order')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['stars_per_voter' => 11], $admin)))->toBe('invalid_stars_per_voter')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['stars_per_voter' => 0], $admin)))->toBe('invalid_stars_per_voter')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['capacity' => 101], $admin)))->toBe('invalid_capacity')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['capacity' => 0], $admin)))->toBe('invalid_capacity')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['capacity' => 'many'], $admin)))->toBe('invalid_capacity')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['eliminated_count' => 21], $admin)))->toBe('invalid_eliminated_count');

    $setting = e44_settings()->settingFor($season);

    expect($setting->writers_voting_opens_at)->toBeNull()
        ->and((int) $setting->writers_capacity)->toBe(20)
        ->and((int) $setting->writers_stars_per_voter)->toBe(10);

    // الحد الصلب 100 مقبول، والنموذج نفسه يرفض ما فوقه حتى بالكتابة المباشرة.
    expect((int) e44_settings()->saveWriterSettings($season, ['capacity' => 100], $admin)->writers_capacity)->toBe(100)
        ->and(SeasonRecognitionSetting::HARD_MAX_CAPACITY)->toBe(100);

    expect(fn () => $setting->forceFill(['writers_capacity' => 101])->save())->toThrow(InvalidArgumentException::class);
});

test('E44-RS-06: readiness reports each required check and passes only with an enabled, windowed, populated league', function () {
    $season = e44_season();
    $admin = e44_admin();

    $keys = fn (array $r) => collect($r['checks'])->where('required', true)->where('ok', false)->pluck('key')->sort()->values()->all();

    $fresh = e44_settings()->readiness($season);
    expect($fresh['ok'])->toBeFalse()
        ->and($keys($fresh))->toBe(['roster_present', 'season_flag', 'week_rule', 'window_order', 'window_set']);

    e44_enable($season, [], $admin);
    e44_writer($season, $admin);
    e44_open($season, [], $admin);

    $ready = e44_settings()->readiness($season->fresh());

    expect($ready['ok'])->toBeTrue()
        ->and($keys($ready))->toBe([])
        ->and(collect($ready['checks'])->firstWhere('key', 'series_linked')['ok'])->toBeTrue()
        ->and(collect($ready['checks'])->firstWhere('key', 'reward_plan')['ok'])->toBeFalse(); // تنبيه فقط
});

test('E44-RS-07: the effective close is clamped to end minus seven days if the season end moves earlier afterwards', function () {
    $league = e44_league(1);
    $season = $league['season'];
    $setting = e44_settings()->settingFor($season);

    expect(e44_settings()->effectiveClose($season, $setting)->toDateTimeString())->toBe('2026-12-20 12:00:00');

    Season::query()->whereKey($season->id)->update(['ends_at' => '2026-12-25 00:00:00']); // آخر إغلاق جديد = 2026-12-18 00:00
    $moved = Season::query()->findOrFail($season->id);

    expect(e44_settings()->effectiveClose($moved, $setting)->toDateTimeString())->toBe('2026-12-18 00:00:00');

    Carbon::setTestNow(Carbon::parse('2026-12-18 00:00:00'));
    expect(e44_settings()->votingState($moved, $setting))->toBe('closed');

    Carbon::setTestNow(Carbon::parse('2026-12-17 23:59:59'));
    expect(e44_settings()->votingState($moved, $setting))->toBe('open');
});

test('E44-RS-08: the star budget size cannot change once a budget exists or voting has started', function () {
    $league = e44_league(1, ['open' => false]);
    $season = $league['season'];
    $admin = $league['admin'];

    // قبل الفتح: يُسمح.
    expect((int) e44_settings()->saveWriterSettings($season, ['stars_per_voter' => 6], $admin)->writers_stars_per_voter)->toBe(6);

    e44_open($season, [], $admin);
    e44_tick(1);
    [, $result] = e44_vote($league['writers'][0], 4);

    expect($result['total'])->toBe(6)
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['stars_per_voter' => 10], $admin)))->toBe('budget_locked')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['opens_at' => '2026-12-12 00:00:00'], $admin)))->toBe('window_locked');
});

test('E44-RS-09: the two global flags are independent and a season-level switch is also required', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, [], $admin);
    e44_settings()->setSupportersEnabled($season, true, $admin);
    $setting = e44_settings()->settingFor($season);

    expect(e44_settings()->writersActive($season, $setting))->toBeTrue()
        ->and(e44_settings()->supportersActive($season, $setting))->toBeTrue();

    config(['seasonal_recognition.writers.enabled' => false]);
    expect(e44_settings()->writersActive($season, $setting))->toBeFalse()
        ->and(e44_settings()->supportersActive($season, $setting))->toBeTrue();

    config(['seasonal_recognition.writers.enabled' => true, 'seasonal_recognition.supporters.enabled' => false]);
    expect(e44_settings()->writersActive($season, $setting))->toBeTrue()
        ->and(e44_settings()->supportersActive($season, $setting))->toBeFalse();

    config(['seasonal_recognition.supporters.enabled' => true]);
    e44_settings()->setSupportersEnabled($season, false, $admin);
    expect(e44_settings()->supportersActive($season, e44_settings()->settingFor($season)))->toBeFalse();
});

test('E44-RS-10: the feature flags default to off and no .env variable is required to keep the platform unchanged', function () {
    $config = require base_path('config/seasonal_recognition.php');

    // القيمة الافتراضية للبيئة الفارغة: معطَّل (الإطلاق قرار تشغيلي).
    expect(str_contains(e44_code('config/seasonal_recognition.php'), "env('SEASON_WRITERS_ENABLED', false)"))->toBeTrue()
        ->and(str_contains(e44_code('config/seasonal_recognition.php'), "env('SEASON_SUPPORTERS_ENABLED', false)"))->toBeTrue()
        ->and($config['writers']['default_capacity'])->toBe(20)
        ->and($config['writers']['max_capacity'])->toBe(100)
        ->and($config['writers']['stars_per_voter'])->toBe(10)
        ->and($config['writers']['min_close_before_end_days'])->toBe(7)
        ->and($config['writers']['eliminated_count'])->toBe(5);
});

// ───────────────────────────── القائمة ─────────────────────────────

test('E44-RS-11: adding a writer needs an approved creator profile, the manage permission and an enabled league; duplicates are refused', function () {
    $season = e44_season();
    $admin = e44_admin();
    $creator = e28_creator();
    $profile = $creator->creatorProfile()->firstOrFail();

    // الدوري غير مُفعَّل لهذا الموسم بعد.
    expect(e44_reason(fn () => e44_roster()->addWriter($season, $profile, $admin)))->toBe('writers_disabled');

    e44_enable($season, [], $admin);

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $profile, e16User())))->toBe('forbidden')
        ->and(e44_reason(fn () => e44_roster()->addWriter($season, $profile, e44_staff(['seasonal-recognition.view']))))->toBe('forbidden');

    $entry = e44_roster()->addWriter($season, $profile, $admin);

    expect($entry->status)->toBe(SeasonWriterEntry::STATUS_ACTIVE)
        ->and((int) $entry->user_id)->toBe((int) $creator->id)
        ->and((int) $entry->stars_total)->toBe(0)
        ->and(e44_reason(fn () => e44_roster()->addWriter($season, $profile, $admin)))->toBe('duplicate')
        ->and(SeasonWriterEntry::query()->where('season_id', $season->id)->count())->toBe(1)
        ->and(e44_audit('seasonal_writer_added'))->toBe(1);
});

test('E44-RS-12: pending, rejected and frozen creators are not eligible and no new role is introduced', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, [], $admin);

    $applicant = e16User();
    $pending = app(CreatorApplicationService::class)->apply($applicant, 'أحب صناعة الألغاز الذهنية للمجتمع العربي.');

    expect($pending->status)->not->toBe(CreatorProfile::STATUS_APPROVED)
        ->and(e44_reason(fn () => e44_roster()->addWriter($season, $pending, $admin)))->toBe('creator_not_eligible');

    $frozenCreator = e28_creator();
    $frozenCreator->forceFill(['is_frozen' => true])->save();

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $frozenCreator->creatorProfile()->firstOrFail(), $admin)))->toBe('creator_not_eligible')
        ->and(SeasonWriterEntry::query()->count())->toBe(0);

    // «كاتب» ليس دورًا جديدًا: لا دور باسمه، والصلاحيات الست فقط هي الإضافة.
    expect(\Spatie\Permission\Models\Role::query()->whereIn('name', ['writer', 'writers', 'season-writer', 'supporter'])->count())->toBe(0);
});

test('E44-RS-13: the roster capacity is enforced and cannot drop below the current roster size', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, ['capacity' => 2], $admin);
    e44_writer($season, $admin);
    e44_writer($season, $admin);

    $third = e28_creator();

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $third->creatorProfile()->firstOrFail(), $admin)))->toBe('capacity_full')
        ->and(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['capacity' => 1], $admin)))->toBe('capacity_below_roster');

    e44_settings()->saveWriterSettings($season, ['capacity' => 3], $admin);
    $entry = e44_roster()->addWriter($season, $third->creatorProfile()->firstOrFail(), $admin);

    expect($entry->exists)->toBeTrue()
        ->and(SeasonWriterEntry::query()->where('season_id', $season->id)->count())->toBe(3);
});

test('E44-RS-14: the absolute cap of 100 writers holds even if the stored capacity were corrupted upwards', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, ['capacity' => 100], $admin);

    // 100 عضوية سريعة بكتابة مباشرة (الاختبار هنا للحد لا لتدفق الإضافة)، ثم المئة وواحد عبر الخدمة الرسمية.
    for ($i = 0; $i < 100; $i++) {
        $creator = e28_creator();
        $entry = new SeasonWriterEntry;
        $entry->forceFill([
            'season_id' => $season->id,
            'creator_profile_id' => $creator->creatorProfile()->value('id'),
            'user_id' => $creator->id,
            'status' => SeasonWriterEntry::STATUS_ACTIVE,
            'joined_at' => now(),
        ])->save();
    }

    $extra = e28_creator();
    $profile = $extra->creatorProfile()->firstOrFail();

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $profile, $admin)))->toBe('capacity_full');

    // تلف مفترض في القيمة المخزَّنة (يتجاوز نموذج الإعدادات): الخدمة تلتزم 100 صلبة.
    DB::table('season_recognition_settings')->where('season_id', $season->id)->update(['writers_capacity' => 500]);

    $error = null;

    try {
        e44_roster()->addWriter($season, $profile, $admin);
    } catch (\App\Services\SeasonalRecognition\SeasonalRecognitionException $e) {
        $error = $e;
    }

    expect($error?->reason)->toBe('capacity_full')
        ->and($error?->getMessage())->toContain('100')
        ->and(SeasonWriterEntry::query()->where('season_id', $season->id)->count())->toBe(100);
});

test('E44-RS-15: removing a writer deletes before voting opens and only withdraws afterwards (history kept)', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, [], $admin);
    $a = e44_writer($season, $admin);
    $b = e44_writer($season, $admin);

    expect(e44_roster()->removeWriter($a['entry'], $admin))->toBe('removed')
        ->and(SeasonWriterEntry::query()->whereKey($a['entry']->id)->exists())->toBeFalse()
        ->and(SeasonWriterSeries::query()->where('writer_entry_id', $a['entry']->id)->exists())->toBeFalse();

    e44_open($season, [], $admin);
    e44_tick(1);
    e44_vote($b, 3);

    expect(e44_roster()->removeWriter($b['entry']->fresh(), $admin))->toBe('withdrawn');

    $kept = SeasonWriterEntry::query()->findOrFail($b['entry']->id);

    expect($kept->status)->toBe(SeasonWriterEntry::STATUS_WITHDRAWN)
        ->and((int) $kept->stars_total)->toBe(3)
        ->and(e44_roster()->removeWriter($kept, $admin))->toBe('withdrawn')
        ->and(e44_audit('seasonal_writer_withdrawn'))->toBe(1);
});

test('E44-RS-16: the roster is locked once voting opens, and also after it closes', function () {
    $league = e44_league(1);
    $season = $league['season'];
    $admin = $league['admin'];
    $newcomer = e28_creator();
    $profile = $newcomer->creatorProfile()->firstOrFail();

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $profile, $admin)))->toBe('roster_locked');

    e44_after_close();

    expect(e44_reason(fn () => e44_roster()->addWriter($season, $profile, $admin)))->toBe('roster_locked')
        ->and(SeasonWriterEntry::query()->where('season_id', $season->id)->count())->toBe(1);
});

test('E44-RS-17: only the writer’s own, published, visible series can be linked; links are unique and capped', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, [], $admin);
    $writer = e44_writer($season, $admin, ['link' => false]);
    $stranger = e28_creator();
    $foreign = e30s_published($stranger, e30s_works($stranger, 2));
    $draft = e30s_draft($writer['user'])->series()->firstOrFail();

    expect(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $foreign, $admin)))->toBe('series_not_owned')
        ->and(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $draft, $admin)))->toBe('series_not_public')
        ->and(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $writer['series'], e16User())))->toBe('forbidden');

    $link = e44_roster()->linkSeries($writer['entry'], $writer['series'], $admin);

    expect($link->exists)->toBeTrue()
        ->and(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $writer['series'], $admin)))->toBe('duplicate_link');

    config(['seasonal_recognition.writers.max_linked_series' => 1]);
    $second = e30s_published($writer['user'], e30s_works($writer['user'], 2));

    expect(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $second, $admin)))->toBe('too_many_series');
});

test('E44-RS-18: unlinking is allowed before voting opens but locked afterwards to protect voter eligibility', function () {
    $season = e44_season();
    $admin = e44_admin();
    e44_enable($season, [], $admin);
    $writer = e44_writer($season, $admin);

    e44_roster()->unlinkSeries($writer['entry'], $writer['series'], $admin);
    expect(SeasonWriterSeries::query()->where('writer_entry_id', $writer['entry']->id)->count())->toBe(0);

    e44_roster()->linkSeries($writer['entry'], $writer['series'], $admin);
    e44_open($season, [], $admin);

    expect(e44_reason(fn () => e44_roster()->unlinkSeries($writer['entry'], $writer['series'], $admin)))->toBe('unlink_locked')
        ->and(SeasonWriterSeries::query()->where('writer_entry_id', $writer['entry']->id)->count())->toBe(1);
});

test('E44-RS-19: a series may be linked during the open window but not after it closes', function () {
    $league = e44_league(1);
    $writer = $league['writers'][0];
    $extra = e30s_published($writer['user'], e30s_works($writer['user'], 2));

    expect(e44_roster()->linkSeries($writer['entry'], $extra, $league['admin'])->exists())->toBeTrue();

    e44_after_close();
    $another = e30s_published($writer['user'], e30s_works($writer['user'], 2));

    expect(e44_reason(fn () => e44_roster()->linkSeries($writer['entry'], $another, $league['admin'])))->toBe('voting_closed');
});

test('E44-RS-20: disabling a flag stops new roster actions only and never erases the roster, links or votes', function () {
    $league = e44_league(2);
    [$a, $b] = $league['writers'];
    e44_vote($a, 4);

    config(['seasonal_recognition.writers.enabled' => false]);

    expect(e44_reason(fn () => e44_voting()->allocate(e44_player_of($b), $b['entry']->fresh(), 2)))->toBe('writers_disabled')
        ->and(SeasonWriterEntry::query()->where('season_id', $league['season']->id)->count())->toBe(2)
        ->and(e44_stars($a))->toBe(4)
        ->and(SeasonWriterSeries::query()->count())->toBe(2)
        ->and(DB::table('season_writer_star_allocations')->count())->toBe(1);
});

// ───────────────────────────── خطة الجوائز ─────────────────────────────

test('E44-RS-21: the reward plan accepts a closed whitelist of cosmetic and monetary items and rejects everything else', function () {
    $season = e44_season();
    $admin = e44_admin();
    $item = e44_cosmetic();

    $plan = e44_settings()->saveWriterSettings($season, ['reward_plan' => [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة <b>المركز</b> الأول', 'store_item_id' => $item->id],
        ['placement' => 'first_place', 'kind' => 'monetary', 'label' => 'جائزة مالية', 'amount_minor' => 5000, 'currency' => 'usd'],
    ]], $admin)->writers_reward_plan;

    expect($plan)->toHaveCount(2)
        ->and($plan[0]['label'])->toBe('شارة المركز الأول')
        ->and($plan[0]['store_item_id'])->toBe($item->id)
        ->and($plan[1]['amount_minor'])->toBe(5000)
        ->and($plan[1]['currency'])->toBe('USD');

    $bad = [
        'unknown placement' => [['placement' => 'fourth_place', 'kind' => 'cosmetic', 'label' => 'x', 'store_item_id' => $item->id], 'reward_plan_invalid'],
        'unknown kind' => [['placement' => 'first_place', 'kind' => 'gems', 'label' => 'x'], 'reward_plan_invalid'],
        'empty label' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => '   ', 'store_item_id' => $item->id], 'reward_plan_invalid'],
        'cosmetic without item' => [['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'x'], 'reward_item_invalid'],
        'monetary below minimum' => [['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'x', 'amount_minor' => 5, 'currency' => 'USD'], 'reward_amount_invalid'],
        'monetary float amount' => [['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'x', 'amount_minor' => 12.5, 'currency' => 'USD'], 'reward_amount_invalid'],
        'monetary foreign currency' => [['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'x', 'amount_minor' => 500, 'currency' => 'EUR'], 'reward_amount_invalid'],
    ];

    foreach ($bad as $label => [$row, $reason]) {
        expect(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['reward_plan' => [$row]], $admin)))->toBe($reason, $label);
    }

    $tooMany = array_fill(0, 13, ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'x', 'store_item_id' => $item->id]);
    expect(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['reward_plan' => $tooMany], $admin)))->toBe('reward_plan_too_long');

    // عنصر متجر غير تجميلي/غير مخزون لا يصلح جائزة.
    $manual = \App\Models\StoreItem::factory()->manual()->create();
    expect(e44_reason(fn () => e44_settings()->saveWriterSettings($season, ['reward_plan' => [['placement' => 'third_place', 'kind' => 'cosmetic', 'label' => 'x', 'store_item_id' => $manual->id]]], $admin)))->toBe('reward_item_invalid');
});

test('E44-RS-22: supporters can be toggled per season by manage only, and a finalized season cannot be re-enabled', function () {
    $season = e44_season();

    expect(e44_reason(fn () => e44_settings()->setSupportersEnabled($season, true, e16User())))->toBe('forbidden');

    $on = e44_settings()->setSupportersEnabled($season, true, e44_admin());
    expect($on->supporters_enabled)->toBeTrue()
        ->and(e44_audit('seasonal_supporters_toggled'))->toBe(1);

    // نفس القيمة: لا أثر تدقيق إضافي.
    e44_settings()->setSupportersEnabled($season, true, e44_admin());
    expect(e44_audit('seasonal_supporters_toggled'))->toBe(1);

    DB::table('season_recognition_settings')->where('season_id', $season->id)->update(['supporters_finalized_at' => now()]);
    expect(e44_reason(fn () => e44_settings()->setSupportersEnabled($season, true, e44_admin())))->toBe('finalized');
});

test('E44-RS-23: settings of a finalized league are frozen', function () {
    $league = e44_league(1);
    e44_cast($league['writers'], [3]);
    e44_after_close();
    e44_finalizer()->finalize($league['season'], $league['admin']);

    foreach ([['capacity' => 30], ['stars_per_voter' => 5], ['eliminated_count' => 1], ['enabled' => false]] as $change) {
        expect(e44_reason(fn () => e44_settings()->saveWriterSettings($league['season'], $change, $league['admin'])))->toBe('finalized');
    }
});
