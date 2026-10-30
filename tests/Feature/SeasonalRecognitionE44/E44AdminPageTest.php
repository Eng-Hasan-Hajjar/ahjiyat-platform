<?php

use App\Filament\Pages\SeasonalRecognitionPage;
use App\Filament\Resources\SeasonResource;
use App\Models\Season;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonWriterAward;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E44 / صفحة Filament الواحدة (8 تبويبات): الوصول والتسجيل، رسم كل تبويب، إعدادات الدوري، القائمة والسلاسل، الصلاحيات (الأزرار مخفية ولو استُدعيت الدالة قسرًا ترفضها الخدمة)،
| خصوصية الناخبين، الإنهاء بتأكيد صريح، الجوائز (التسوية اليدوية بمرجع، الإلغاء بملاحظة)، باقات الداعمين (السعر لا يُقبل من الواجهة)، الترتيب والإنهاء، طابور التنويه،
| المطابقة وإعادة البناء، منح شراء فائت، تبديل الموسم، الهروب من XSS. لا قاعدة هنا: كل قرار داخل الخدمات (الصفحة طبقة استدعاء).
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

const E44AP_URL = '/admin/seasonal-recognition';

/** دوري مُغلق جاهز للإنهاء: $stars لكل كاتب بالترتيب، مع خطة جوائز اختيارية. @return array{season: Season, admin: User, writers: list<array<string, mixed>>} */
function e44_ap_closed(array $stars, array $plan = []): array
{
    $league = e44_league(count($stars), $plan === [] ? [] : ['reward_plan' => $plan]);
    e44_cast($league['writers'], $stars);
    e44_after_close();

    return $league;
}

/** خطة جوائز كاملة: تجميلية للأول، ومالية للثاني والثالث. */
function e44_ap_plan(): array
{
    $item = e44_cosmetic();

    return [
        ['placement' => 'first_place', 'kind' => 'cosmetic', 'label' => 'شارة المركز الأول', 'store_item_id' => $item->id],
        ['placement' => 'second_place', 'kind' => 'monetary', 'label' => 'مكافأة المركز الثاني', 'amount_minor' => 2500, 'currency' => 'USD'],
        ['placement' => 'third_place', 'kind' => 'monetary', 'label' => 'مكافأة المركز الثالث', 'amount_minor' => 1000, 'currency' => 'USD'],
    ];
}

/** موسم بدوري كتّاب مُفعَّل وتقدير داعمين مُفعَّل وباقات جاهزة. @return array{season: Season, admin: User, program: \App\Models\SponsorshipProgram, packages: array<string, SeasonSupporterPackage>} */
function e44_ap_supporters(): array
{
    return e44_supporters_world();
}

// ───────────────────────────── الوصول والتسجيل ─────────────────────────────

test('E44-AP-01: only staff with seasonal-recognition.view reach the page — guests are redirected, players and view-less staff get 403', function () {
    e44_season();

    $this->get(E44AP_URL)->assertRedirect();
    $this->actingAs(e16User())->get(E44AP_URL)->assertForbidden(); // لا admin.access ⇒ يمنعه الـpanel قبل الصفحة
    $this->actingAs(e44_staff(['admin.access', 'seasonal-recognition.view']))->get(E44AP_URL)->assertOk()->assertSee('التقدير الموسمي');
    $this->flushSession(); // Filament AuthenticateSession يقارن بصمة كلمة المرور المخزَّنة في الجلسة بين الطلبات؛ تبديل المستخدم داخل جلسة اختبار واحدة يُسقطه (302) بسبب الاختبار لا الصفحة.
    $this->actingAs(e44_admin())->get(E44AP_URL)->assertOk();

    expect(SeasonalRecognitionPage::canAccess())->toBeTrue();

    // آخر طلب عمدًا: رفض mount() (403) يترك مُعيد توجيه Livewire مربوطًا في حاوية الاختبار الواحدة (في الإنتاج لكل طلب عملية جديدة)،
    // فأي طلب لاحق ضمن الاختبار نفسه يفشل بسبب الاختبار لا بسبب الصفحة.
    $this->flushSession();
    $this->actingAs(e44_staff(['admin.access']))->get(E44AP_URL)->assertForbidden();
    expect(SeasonalRecognitionPage::canAccess())->toBeFalse();
});

test('E44-AP-02: one focused page with the eight tabs — registered in the admin panel, and SeasonResource keeps exactly its three E42 relation managers', function () {
    $this->actingAs(e44_admin());

    expect(SeasonalRecognitionPage::getSlug())->toBe('seasonal-recognition')
        ->and(array_keys(SeasonalRecognitionPage::TABS))->toBe(['writers', 'voting', 'standings', 'awards', 'packages', 'leaderboard', 'queue', 'reconcile'])
        ->and(Filament::getDefaultPanel()->getPages())->toContain(SeasonalRecognitionPage::class)
        ->and(SeasonResource::getRelations())->toBe([
            \App\Filament\Resources\SeasonResource\RelationManagers\PhasesRelationManager::class,
            \App\Filament\Resources\SeasonResource\RelationManagers\ContentItemsRelationManager::class,
            \App\Filament\Resources\SeasonResource\RelationManagers\AnnouncementsRelationManager::class,
        ]);
});

test('E44-AP-03: every tab renders for an administrator on a season with a league, votes, packages and a supporter — and an unknown tab falls back to the writers tab', function () {
    $league = e44_league(3);
    $season = $league['season'];
    e44_cast($league['writers'], [5, 3, 1]);
    e44_settings()->setSupportersEnabled($season, true, $league['admin']);
    $world = e44_packages($season, $league['admin']);
    e44_buy_card(e16User(['name' => 'داعم-الصفحة']), $world['packages']['gold']);

    $markers = ['writers' => 'data-writers-settings', 'voting' => 'data-voting-board', 'standings' => 'data-standings', 'awards' => 'data-awards', 'packages' => 'data-packages',
        'leaderboard' => 'data-supporter-board', 'queue' => 'data-social-queue', 'reconcile' => 'data-reconcile'];

    $page = e44_ap_page($league['admin'], $season);

    foreach ($markers as $tab => $marker) {
        $page->call('setTab', $tab)->assertSet('tab', $tab)->assertSuccessful()->assertSeeHtml($marker);
    }

    $page->call('setTab', 'drop-table')->assertSet('tab', 'writers')->assertSeeHtml('data-writers-settings');
    e44_ap_page($league['admin'], $season, 'leaderboard')->assertSee('داعم-الصفحة');
});

test('E44-AP-04: with no season at all the page says so, a forged season id renders the same empty state, and every action refuses politely', function () {
    Season::query()->delete();

    $page = e44_ap_page(e44_admin());
    $page->assertSee('لا مواسم بعد.');

    foreach (['saveSettings', 'finalizeWriters', 'rebuildWriters', 'toggleSupporters'] as $method) {
        $page->set('confirmWriters', true)->call($method)->assertNotified(); // «اختر موسمًا أولًا.» أو طلب التأكيد: لا استثناء ولا كتابة
    }

    $season = e44_season();
    $other = e44_ap_page(e44_admin(), $season)->set('seasonId', 987654)->assertSee('لا مواسم بعد.');
    $other->call('addWriter')->assertNotified('اختر موسمًا أولًا.');

    expect(SeasonWriterEntry::query()->count())->toBe(0);
});

// ───────────────────────────── الإعدادات والقائمة ─────────────────────────────

test('E44-AP-05: saving the league settings persists them with an audit entry; a late close, a capacity above 100 and an absurd plan are all refused with nothing changed', function () {
    $season = e44_season();
    $page = e44_ap_page(e44_admin(), $season);

    $page->set('writersEnabled', true)->set('capacity', '30')->set('opensAt', '2026-12-11T10:00')->set('closesAt', '2026-12-20T12:00')->set('eliminatedCount', '4')
        ->set('plan.second_place__monetary.label', 'مكافأة مالية')->set('plan.second_place__monetary.amount_minor', '5000')
        ->call('saveSettings')->assertNotified('حُفظت إعدادات الدوري.');

    $setting = e44_settings()->settingFor($season->fresh());
    $monetary = collect($setting->writers_reward_plan)->where('kind', 'monetary')->values();

    expect($setting->writers_enabled)->toBeTrue()->and((int) $setting->writers_capacity)->toBe(30)->and((int) $setting->writers_eliminated_count)->toBe(4)
        ->and($setting->writers_voting_closes_at->format('Y-m-d H:i'))->toBe('2026-12-20 12:00')
        ->and($monetary)->toHaveCount(1)->and((int) $monetary[0]['amount_minor'])->toBe(5000)->and($monetary[0]['currency'])->toBe('USD')
        ->and(e44_audit('seasonal_writers_settings_saved'))->toBe(1);

    // إغلاق متأخر عن (نهاية الموسم − 7 أيام = 2026-12-23): مرفوض والإعداد كما هو.
    $page->set('closesAt', '2026-12-25T00:00')->call('saveSettings')->assertNotified();
    expect(e44_settings()->settingFor($season->fresh())->writers_voting_closes_at->format('Y-m-d H:i'))->toBe('2026-12-20 12:00');

    // سعة فوق الحد الصلب (100) مرفوضة.
    $page->set('closesAt', '2026-12-20T12:00')->set('capacity', '101')->call('saveSettings')->assertNotified();
    expect((int) e44_settings()->settingFor($season->fresh())->writers_capacity)->toBe(30);

    // لا مبلغ نقدي بالسالب أو دون الحد الأدنى.
    $page->set('capacity', '30')->set('plan.third_place__monetary.label', 'جائزة')->set('plan.third_place__monetary.amount_minor', '5')->call('saveSettings')->assertNotified();
    expect(collect(e44_settings()->settingFor($season->fresh())->writers_reward_plan)->where('placement', 'third_place'))->toHaveCount(0);
});

test('E44-AP-06: add a writer by slug, link and unlink a series, remove — each through the roster service; unknown slug or series changes nothing', function () {
    $season = e44_season();
    e44_enable($season);
    $user = e28_creator();
    $profile = $user->creatorProfile()->firstOrFail();
    $series = e30s_published($user, e30s_works($user, 2));

    $page = e44_ap_page(e44_admin(), $season);

    $page->set('creatorSlug', 'no-such-creator')->call('addWriter')->assertNotified('لا يوجد مبدع بهذا المعرّف.');
    expect(SeasonWriterEntry::query()->count())->toBe(0);

    $page->set('creatorSlug', $profile->slug)->call('addWriter')->assertNotified('أُضيف الكاتب إلى قائمة الموسم.')->assertSet('creatorSlug', '')->assertSee($user->name);
    $entry = SeasonWriterEntry::query()->where('season_id', $season->id)->firstOrFail();

    $page->set("seriesInput.{$entry->public_id}", 'no-such-series')->call('linkSeries', $entry->public_id)->assertNotified('لا توجد سلسلة بهذا المعرّف.');
    expect(DB::table('season_writer_series')->count())->toBe(0);

    $page->set("seriesInput.{$entry->public_id}", $series->public_id)->call('linkSeries', $entry->public_id)->assertNotified('رُبطت السلسلة بالكاتب لهذا الموسم.');
    expect(DB::table('season_writer_series')->where('writer_entry_id', $entry->id)->count())->toBe(1);

    $page->call('unlinkSeries', $entry->public_id, $series->id)->assertNotified('فُكّ ربط السلسلة.');
    expect(DB::table('season_writer_series')->count())->toBe(0);

    // قبل فتح التصويت: الإزالة حذف فعلي؛ وإدخال معرّف كاتب ليس في هذا الموسم لا يجد شيئًا (لا تسرّب بين المواسم).
    $page->call('removeWriter', $entry->public_id)->assertNotified('حُذف الكاتب من القائمة (قبل فتح التصويت).');
    expect(SeasonWriterEntry::query()->count())->toBe(0)
        ->and(e44_audit('seasonal_writer_added'))->toBe(1)->and(e44_audit('seasonal_writer_series_linked'))->toBe(1)->and(e44_audit('seasonal_writer_removed'))->toBe(1);
});

test('E44-AP-07: view-only staff see no action buttons, and calling the methods anyway is refused by the services — nothing changes and nothing is audited', function () {
    $league = e44_league(2, ['open' => false]);
    $season = $league['season'];
    $viewer = e44_staff(['seasonal-recognition.view']);
    $extra = e28_creator();
    $before = ['entries' => SeasonWriterEntry::query()->count(), 'cap' => (int) e44_settings()->settingFor($season)->writers_capacity];

    $page = e44_ap_page($viewer, $season)->assertDontSee('حفظ الإعدادات')->assertDontSee('إضافة كاتب')->assertDontSee('إزالة');

    $page->set('creatorSlug', $extra->creatorProfile()->firstOrFail()->slug)->call('addWriter')->assertNotified('لا تملك صلاحية إدارة قائمة الكتّاب.');
    $page->set('capacity', '99')->call('saveSettings')->assertNotified();
    $page->call('removeWriter', $league['writers'][0]['entry']->public_id)->assertNotified();

    expect(SeasonWriterEntry::query()->count())->toBe($before['entries'])->and((int) e44_settings()->settingFor($season->fresh())->writers_capacity)->toBe($before['cap'])
        ->and(e44_audit('seasonal_writers_settings_saved'))->toBe(1) // حفظ e44_league نفسه فقط
        ->and(e44_audit('seasonal_writer_removed'))->toBe(0);

    foreach (['rebuildWriters', 'rebuildSupporters', 'activatePending', 'toggleSupporters', 'grantCosmetics', 'redeliverWriters'] as $method) {
        $page->call($method)->assertNotified();
    }

    expect(e44_audit('seasonal_writers_aggregates_rebuilt'))->toBe(0)->and(e44_audit('seasonal_supporters_stats_rebuilt'))->toBe(0)->and(e44_audit('seasonal_supporters_toggled'))->toBe(0);

    $page->call('setTab', 'reconcile')->assertSee('المطابقة تتطلب صلاحية التدقيق.');
});

test('E44-AP-08: XSS — a hostile display name is escaped everywhere the page prints it', function () {
    $league = e44_league(1, ['open' => false]);
    $payload = '<script>alert(1)</script>';
    $league['writers'][0]['user']->forceFill(['name' => $payload])->save();

    foreach (['writers', 'voting'] as $tab) {
        e44_ap_page($league['admin'], $league['season'], $tab)->assertSee($payload)->assertDontSeeHtml($payload);
    }
});

// ───────────────────────────── التصويت والخصوصية ─────────────────────────────

test('E44-AP-09: the voting tab shows aggregates only — no voter identity appears on any of the eight tabs', function () {
    $league = e44_league(2);
    $voter = e44_player_of($league['writers'][0]);
    $voter->forceFill(['name' => 'ناخب-سرّي-قيد-الاختبار'])->save();
    e44_voting()->allocate($voter, $league['writers'][0]['entry']->fresh(), 7);
    e44_vote($league['writers'][1], 3);

    $page = e44_ap_page($league['admin'], $league['season'], 'voting');
    $page->assertSee('مجاميع فقط')->assertSee($league['writers'][0]['user']->name)->assertSee('مجموع النجوم: 10');

    foreach (array_keys(SeasonalRecognitionPage::TABS) as $tab) {
        $page->call('setTab', $tab)->assertDontSee('ناخب-سرّي-قيد-الاختبار');
    }

    expect((int) e44_settings()->settingFor($league['season'])->writers_stars_per_voter)->toBe(10);
});

// ───────────────────────────── الإنهاء والجوائز ─────────────────────────────

test('E44-AP-10: finalizing the league needs the explicit confirmation and the finalize permission; it is irreversible and a repeat changes nothing', function () {
    $league = e44_ap_closed([5, 3, 1], e44_ap_plan());
    $season = $league['season'];

    $page = e44_ap_page($league['admin'], $season, 'standings');
    $page->assertSee('معاينة الإنهاء (قراءة فقط)')->assertSee('الجوائز التي ستُسجَّل');

    // بلا تأكيد: لا إنهاء.
    $page->call('finalizeWriters')->assertNotified('أكّد الإنهاء أولًا: لا رجعة بعده.');
    expect(e44_settings()->settingFor($season->fresh())->writers_finalized_at)->toBeNull();

    // موظف إدارة بلا صلاحية الإنهاء: زر مخفي، والاستدعاء القسري مرفوض.
    $manager = e44_staff(['seasonal-recognition.view', 'seasonal-recognition.manage']);
    $mPage = e44_ap_page($manager, $season, 'standings')->assertDontSee('إنهاء الترتيب');
    $mPage->set('confirmWriters', true)->call('finalizeWriters')->assertNotified();
    expect(e44_settings()->settingFor($season->fresh())->writers_finalized_at)->toBeNull();

    // المستخدم الحالي في اختبار Livewire هو الأخير الذي سُجّل دخوله: نعود للمدير بصفحة جديدة.
    $page = e44_ap_page($league['admin'], $season, 'standings');
    $page->set('confirmWriters', true)->call('finalizeWriters')->assertSet('confirmWriters', false)->assertSee('انتهى الترتيب');
    $settled = e44_settings()->settingFor($season->fresh());

    expect($settled->writers_finalized_at)->not->toBeNull()->and(SeasonWriterAward::query()->where('season_id', $season->id)->count())->toBe(3)
        ->and(e44_audit('seasonal_writers_finalized'))->toBe(1);

    $page->set('confirmWriters', true)->call('finalizeWriters')->assertNotified('سبق إنهاء الترتيب.');
    expect(SeasonWriterAward::query()->where('season_id', $season->id)->count())->toBe(3)->and(e44_audit('seasonal_writers_finalized'))->toBe(1);
});

test('E44-AP-11: monetary awards are settled by hand with a mandatory reference and cancelled with a mandatory note — no wallet, ledger or creator-earning row is ever written', function () {
    $league = e44_ap_closed([5, 3, 1], e44_ap_plan());
    $season = $league['season'];
    e44_finalizer()->finalize($season, $league['admin']);

    $second = SeasonWriterAward::query()->where('season_id', $season->id)->where('placement', 'second_place')->firstOrFail();
    $third = SeasonWriterAward::query()->where('season_id', $season->id)->where('placement', 'third_place')->firstOrFail();
    $winner = User::query()->findOrFail($second->user_id);
    $money = e44_money_state($winner);
    $foreign = e44_foreign_counts();

    expect($second->status)->toBe('pending_finance')->and($third->status)->toBe('pending_finance');

    // موظف بلا صلاحية الجوائز: لا أزرار، والاستدعاء القسري للتسوية والإلغاء مرفوض (الجائزتان ما زالتا بانتظار الصرف).
    $viewer = e44_staff(['seasonal-recognition.view']);
    $vPage = e44_ap_page($viewer, $season, 'awards')->assertDontSee('منح الجوائز التجميلية المعلّقة')->assertDontSeeHtml('wire:click="showAward');
    $vPage->call('showAward', $second->public_id)->set('settleReference', 'FORGED-REF')->call('settleAward')->assertNotified();
    $vPage->set('awardNote', 'محاولة إلغاء')->call('cancelAward')->assertNotified();

    expect($second->fresh()->status)->toBe('pending_finance')->and($third->fresh()->status)->toBe('pending_finance')->and(e44_audit('seasonal_writer_award_settled'))->toBe(0);

    $page = e44_ap_page($league['admin'], $season, 'awards');
    $page->assertSee('بانتظار الصرف')->assertSee('ممنوحة')->assertSee('25.00 USD');

    // تسوية بلا مرجع: مرفوضة.
    $page->call('showAward', $second->public_id)->assertSet('awardOpen', $second->public_id)->assertSeeHtml('wire:click="settleAward"');
    $page->call('settleAward')->assertNotified();
    expect($second->fresh()->status)->toBe('pending_finance');

    $page->set('settleReference', 'BANK-TRX-991')->call('settleAward')->assertNotified('سُجّلت تسوية الجائزة المالية (يدويًا خارج النظام).')->assertSet('awardOpen', null);
    expect($second->fresh()->status)->toBe('settled')->and(e44_audit('seasonal_writer_award_settled'))->toBe(1);

    // إلغاء بلا ملاحظة: مرفوض؛ بملاحظة: يُلغى.
    $page->call('showAward', $third->public_id)->call('cancelAward')->assertNotified();
    expect($third->fresh()->status)->toBe('pending_finance');

    $page->set('awardNote', 'تعذّر التواصل مع الكاتب')->call('cancelAward')->assertNotified('أُلغيت الجائزة.');
    expect($third->fresh()->status)->toBe('cancelled')->and(e44_audit('seasonal_writer_award_cancelled'))->toBe(1);

    // لا أثر مالي في أي مكان: التسوية سجلّ التزام فقط.
    expect(e44_money_state($winner))->toBe($money)->and(e44_foreign_counts())->toBe($foreign);
});

// ───────────────────────────── باقات الداعمين والترتيب والطابور ─────────────────────────────

test('E44-AP-12: package seeding, edits and the season toggle — prices are read-only (a forged price field is ignored), edits touch support points and flags only', function () {
    $admin = e44_admin();
    $season = e44_season();
    e44_settings()->setSupportersEnabled($season, true, $admin);
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id], true, $admin);

    $page = e44_ap_page($admin, $season, 'packages');
    $page->assertSee('إنشاء الباقات الافتراضية')->assertSee('مُفعَّل');

    $page->set('programId', (string) $program->id)->call('seedPackages')->assertNotified('أُنشئت 3 باقة افتراضية (فضي/ذهبي/ماسي).');
    $packages = SeasonSupporterPackage::query()->where('season_id', $season->id)->get()->keyBy('tier_key');

    expect($packages->keys()->sort()->values()->all())->toBe(['diamond', 'gold', 'silver'])
        ->and(array_map(fn ($t) => (int) $packages[$t]->price_minor, ['silver', 'gold', 'diamond']))->toBe([2500, 10000, 25000])
        ->and(array_map(fn ($t) => (int) $packages[$t]->support_points, ['silver', 'gold', 'diamond']))->toBe([250, 1000, 2500]);

    $page->assertSee('25.00 USD')->assertSee('100.00 USD')->assertSee('250.00 USD');
    $page->call('seedPackages')->assertNotified('الباقات موجودة مسبقًا.');
    expect(SeasonSupporterPackage::query()->where('season_id', $season->id)->count())->toBe(3);

    $gold = $packages['gold'];
    $page->assertDontSeeHtml("packageInput.{$gold->id}.price")->assertSeeHtml("packageInput.{$gold->id}.support_points");

    // تعديل النقاط والاسم والتفعيل؛ سعر مُزوَّر في المكوّن يُتجاهل (الخدمة تقرأ الحقول المسموحة فقط).
    $page->set("packageInput.{$gold->id}.support_points", '1200')->set("packageInput.{$gold->id}.name", 'الداعم الذهبي المميز')
        ->set("packageInput.{$gold->id}.price_minor", '1')->call('savePackage', $gold->id)->assertNotified('حُفظت الباقة (تسري على الطلبات الجديدة فقط).');

    $gold = $gold->fresh();
    expect((int) $gold->support_points)->toBe(1200)->and($gold->name)->toBe('الداعم الذهبي المميز')->and((int) $gold->price_minor)->toBe(10000)->and($gold->currency)->toBe('USD')
        ->and(e44_audit('seasonal_supporter_package_updated'))->toBe(1);

    // نقاط مرفوضة (سالبة) لا تُحفظ.
    $page->set("packageInput.{$gold->id}.support_points", '-5')->call('savePackage', $gold->id)->assertNotified();
    expect((int) $gold->fresh()->support_points)->toBe(1200);

    // تبديل الموسم: تعطيل ثم تفعيل مع سجل تدقيق.
    $page->call('toggleSupporters')->assertNotified('حُدّثت حالة تقدير الداعمين لهذا الموسم.');
    expect((bool) e44_settings()->settingFor($season->fresh())->supporters_enabled)->toBeFalse();
    $page->call('toggleSupporters');
    expect((bool) e44_settings()->settingFor($season->fresh())->supporters_enabled)->toBeTrue()->and(e44_audit('seasonal_supporters_toggled'))->toBeGreaterThanOrEqual(2);
});

test('E44-AP-13: the supporters board shows totals and rows for staff; finalizing is refused before the season ends and works (once) after it, behind explicit confirmation', function () {
    $w = e44_ap_supporters();
    $season = $w['season'];
    $a = e16User(['name' => 'داعم-الأول-صفحة']);
    $b = e16User(['name' => 'داعم-الثاني-صفحة']);
    e44_buy_card($a, $w['packages']['gold']);
    e44_buy_card($b, $w['packages']['silver']);

    $page = e44_ap_page($w['admin'], $season, 'leaderboard');
    $page->assertSee('داعمون: 2')->assertSee('داعم-الأول-صفحة')->assertSee('داعم-الثاني-صفحة')->assertSee('نقاط علنية')->assertSee('لاعبون فقط');

    // قبل نهاية الموسم: لا إنهاء حتى بالتأكيد.
    $page->set('confirmSupporters', true)->call('finalizeSupporters')->assertNotified();
    expect(e44_settings()->settingFor($season->fresh())->supporters_finalized_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));
    $page = e44_ap_page($w['admin'], $season, 'leaderboard');

    $page->call('finalizeSupporters')->assertNotified('أكّد الإنهاء أولًا: لا رجعة بعده.');
    $page->set('confirmSupporters', true)->call('finalizeSupporters')->assertSet('confirmSupporters', false)->assertSee('انتهى ترتيب الداعمين');

    expect(e44_stat($season, $a)->final_rank)->toBe(1)->and(e44_stat($season, $b)->final_rank)->toBe(2)->and(e44_audit('seasonal_supporters_finalized'))->toBe(1);

    $page->set('confirmSupporters', true)->call('finalizeSupporters')->assertNotified('سبق إنهاء ترتيب الداعمين.');
    expect(e44_audit('seasonal_supporters_finalized'))->toBe(1);
});

test('E44-AP-14: the social mention queue is a manual admin ledger — allowed transitions only, notes saved, filter works, and no network is touched', function () {
    $w = e44_ap_supporters();
    $season = $w['season'];
    $gold = e16User(['name' => 'داعم-ذهبي-الطابور']);
    e44_buy_card($gold, $w['packages']['gold']);
    $stat = e44_stat($season, $gold);

    \Illuminate\Support\Facades\Http::fake();

    $page = e44_ap_page($w['admin'], $season, 'queue')->assertSee('داعم-ذهبي-الطابور')->assertSee('مؤهَّل')->assertSee('لا نشر آلي');

    $page->call('transition', $stat->id, 'published')->assertNotified('هذا الانتقال غير مسموح للحالة الحالية.');
    expect($stat->fresh()->social_status)->toBe('eligible');

    $page->set("queueNote.{$stat->id}", 'تم التواصل عبر الدعم')->call('transition', $stat->id, 'queued')->assertNotified('حُدّثت حالة التنويه.');
    expect($stat->fresh()->social_status)->toBe('queued')->and($stat->fresh()->social_note)->toBe('تم التواصل عبر الدعم');

    $page->set('queueFilter', 'published')->assertDontSee('داعم-ذهبي-الطابور');
    $page->call('transition', $stat->id, 'published')->set('queueFilter', 'published')->assertSee('داعم-ذهبي-الطابور');
    expect($stat->fresh()->social_status)->toBe('published');

    \Illuminate\Support\Facades\Http::assertNothingSent();

    // بلا صلاحية الإدارة: لا أزرار، والاستدعاء القسري مرفوض.
    $viewer = e44_staff(['seasonal-recognition.view']);
    $vPage = e44_ap_page($viewer, $season, 'queue')->assertDontSee('إعادة');
    $vPage->call('transition', $stat->id, 'skipped')->assertNotified();
    expect($stat->fresh()->social_status)->toBe('published');
});

// ───────────────────────────── المطابقة وإعادة البناء والمنح الفائت ─────────────────────────────

test('E44-AP-15: reconciliation is read-only until an auditor presses a rebuild — drift is displayed, rebuilt from the ledger, audited, and the board is clean again', function () {
    $league = e44_league(2);
    $season = $league['season'];
    e44_cast($league['writers'], [4, 2]);
    e44_settings()->setSupportersEnabled($season, true, $league['admin']);
    $world = e44_packages($season, $league['admin']);
    $supporter = e16User();
    e44_buy_card($supporter, $world['packages']['silver']);

    $page = e44_ap_page($league['admin'], $season, 'reconcile');
    $page->assertSee('دوري الكتّاب: متسق ✔')->assertSee('نقاط الدعم والبطاقات: متسق ✔');

    // انحراف مصطنع في المجاميع (لا في الدفاتر).
    DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $supporter->id)->update(['points_total' => 999]);
    DB::table('season_writer_entries')->where('id', $league['writers'][0]['entry']->id)->update(['stars_total' => 99]);

    $page->call('setTab', 'reconcile')->assertSee('stat_points_mismatch')->assertSee('entry_aggregate_mismatch')->assertSee('1 مخالفة');
    expect(e44_user_points($season, $supporter))->toBe(999); // العرض وحده لا يصلح شيئًا

    $page->call('rebuildSupporters')->assertNotified()->call('rebuildWriters')->assertNotified('أُعيد بناء مجاميع الدوري من دفتر التوزيعات.');

    expect(e44_user_points($season, $supporter))->toBe(250)->and(e44_stars($league['writers'][0]))->toBe(4)
        ->and(e44_audit('seasonal_supporters_stats_rebuilt'))->toBe(1)->and(e44_audit('seasonal_writers_aggregates_rebuilt'))->toBe(1);

    $page->call('setTab', 'reconcile')->assertSee('دوري الكتّاب: متسق ✔')->assertSee('نقاط الدعم والبطاقات: متسق ✔');
});

test('E44-AP-16: a store purchase that missed its season is granted to the chosen season by an explicit supervisor action, once — unknown ids and repeats are harmless', function () {
    $admin = e44_admin();
    $season = e44_season(); // الداعمون غير مفعَّلين وقت الشراء ⇒ لا منح تلقائي
    $buyer = e16User();
    $purchase = e44_buy($buyer, e44_store_item(40, 10));
    $other = e44_buy(e16User(), e44_store_item(25, 10)); // شراء ثانٍ فائت أيضًا (قبل التفعيل) كي لا يمنحه التقدير تلقائيًا
    e44_settings()->setSupportersEnabled($season, true, $admin);

    expect(e44_user_points($season, $buyer))->toBe(0);

    $page = e44_ap_page($admin, $season, 'reconcile')->assertSeeHtml('data-backfill');

    $page->set('backfillPurchaseId', '99999999')->call('backfillPurchase')->assertNotified('لا يوجد شراء بهذا الرقم.');
    $page->set('backfillPurchaseId', (string) $purchase->id)->call('backfillPurchase')->assertNotified('مُنحت نقاط الشراء إلى هذا الموسم.')->assertSet('backfillPurchaseId', '');
    expect(e44_user_points($season, $buyer))->toBe(40)->and(e44_audit('seasonal_support_backfilled'))->toBe(1);

    $page->set('backfillPurchaseId', (string) $purchase->id)->call('backfillPurchase')->assertNotified('سبق منح هذا الشراء أو أُنهي ترتيب الداعمين؛ لا تغيير.');
    expect(e44_user_points($season, $buyer))->toBe(40)->and(e44_entries($season, $buyer))->toHaveCount(1);

    // لا صلاحية manage ⇒ لا حقل ولا زر، والاستدعاء القسري مرفوض.
    $auditor = e44_staff(['seasonal-recognition.view', 'seasonal-recognition.audit']);
    $aPage = e44_ap_page($auditor, $season, 'reconcile')->assertDontSeeHtml('data-backfill');
    $aPage->set('backfillPurchaseId', (string) $other->id)->call('backfillPurchase')->assertNotified();
    expect(e44_entries($season)->where('source_id', $other->id)->count())->toBe(0);
});

// ───────────────────────────── تبديل الموسم ─────────────────────────────

test('E44-AP-17: the newest season is selected on open; switching season reloads that season\'s settings and clears the confirmation and the open award', function () {
    $old = e44_season();
    $new = e44_season();
    e44_enable($old, ['capacity' => 7]);

    $page = e44_ap_page(e44_admin());
    $page->assertSet('seasonId', $new->id)->assertSet('capacity', '20')->assertSet('writersEnabled', false);

    $page->set('confirmWriters', true)->set('confirmSupporters', true)->set('awardOpen', 'x')->set('seasonId', $old->id)
        ->assertSet('capacity', '7')->assertSet('writersEnabled', true)->assertSet('confirmWriters', false)->assertSet('confirmSupporters', false)->assertSet('awardOpen', null);

    $page->call('setTab', 'voting')->set('confirmWriters', true)->call('setTab', 'awards')->assertSet('confirmWriters', false);
});
