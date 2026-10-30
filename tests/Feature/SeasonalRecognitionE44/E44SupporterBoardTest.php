<?php

use App\Events\SeasonalRecognition\SeasonSupportersLeaderboardUpdated;
use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterStat;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\SeasonalRecognition\SeasonalRecognitionCache;
use App\Services\SeasonalRecognition\SeasonSupporterIdentityResolver;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonSupporterSocialQueueService;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/_support.php';

/*
| E44 / لوحة «داعمو الموسم»: ترتيب حتمي بالنقاط العلنية (لاعبون فقط)، حجب المجهول وملفات E30 غير العلنية والمؤسسات والمجمَّد، لقطة نهائية ثابتة بعد نهاية الموسم،
| عرض الهوية الموسمية (نمط الاسم والشارة) كطبقة مشتقّة، وطابور الإشارة الاجتماعية اليدوي.
*/

beforeEach(fn () => e44_seed());
afterEach(fn () => Carbon::setTestNow());

function e44_board(Season $season): array
{
    return app(SeasonSupporterLeaderboardService::class)->publicBoard($season);
}

/** يمنح نقاطًا مباشرةً عبر خدمة الدفتر (مصدر وهمي فريد) ثم يبطل الكاش كما يفعل المستدعون الرسميون. */
function e44_sb_points(Season $season, User $user, int $points, bool $public = true): void
{
    static $sequence = 700000;

    e44_points()->grant((int) $season->id, (int) $user->id, 'supporter_card', ++$sequence, $points, $public);
    SeasonalRecognitionCache::bump((int) $season->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
}

function e44_sb_forget_identity_cache(): void
{
    Cache::forget('seasonal:supporters:display-season');
}

// ───────────────────────────── الترتيب والخصوصية ─────────────────────────────

test('E44-SB-01: ranking is public points DESC, then the earliest first point, then the stat id — ranks are sequential', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $users = collect(['ألف', 'باء', 'جيم', 'دال', 'هاء'])->map(fn ($n) => e16User(['name' => 'لاعب '.$n]));
    [$u1, $u2, $u3, $u4, $u5] = $users->all();

    e44_sb_points($season, $u5, 100);
    e44_sb_points($season, $u1, 500);
    e44_tick(1);
    e44_sb_points($season, $u2, 500);
    e44_sb_points($season, $u3, 500); // الثانية نفسها مع u2 ⇒ يحسمها معرّف الإحصاء
    e44_sb_points($season, $u4, 900);

    $rows = e44_board($season)['rows'];

    expect(array_column($rows, 'name'))->toBe([$u4->name, $u1->name, $u2->name, $u3->name, $u5->name])
        ->and(array_column($rows, 'rank'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($rows, 'points'))->toBe([900, 500, 500, 500, 100]);
});

test('E44-SB-02: the public payload is names, ranks, public points and the public tier only — no ids, no emails, no payment data', function () {
    $w = e44_supporters_world();
    $user = e16User(['name' => 'داعم علني']);
    e44_buy_card($user, $w['packages']['gold']);

    $board = e44_board($w['season']);

    expect(array_keys($board))->toBe(['finalized', 'generated_at', 'supporters_count', 'rows'])
        ->and(array_keys($board['rows'][0]))->toBe(['rank', 'name', 'points', 'tier'])
        ->and($board['rows'][0])->toBe(['rank' => 1, 'name' => 'داعم علني', 'points' => 1000, 'tier' => 'gold'])
        ->and($board['finalized'])->toBeFalse()
        ->and($board['supporters_count'])->toBe(1);

    $json = json_encode($board, JSON_UNESCAPED_UNICODE);

    foreach ([$user->email, 'user_id', 'payment', 'reference', 'amount', 'price'] as $leak) {
        expect(str_contains((string) $json, (string) $leak))->toBeFalse();
    }
});

test('E44-SB-03: the board is PLAYERS only — frozen accounts, organizations, private or suspended E30 profiles and anonymous-only supporters never appear', function () {
    $w = e44_supporters_world();
    $season = $w['season'];

    $visible = [
        'none' => e16User(['name' => 'ظاهر-بلا-ملف']),
        'public' => e16User(['name' => 'ظاهر-بملف-علني']),
    ];
    e30_supporter(['user_id' => $visible['public']->id, 'is_public' => true, 'type' => 'individual']);

    $hidden = [
        'frozen' => e16User(['name' => 'محجوب-مجمّد']),
        'org' => e16User(['name' => 'محجوب-مؤسسة']),
        'private' => e16User(['name' => 'محجوب-خاص']),
        'suspended' => e16User(['name' => 'محجوب-موقوف']),
        'anonymous' => e16User(['name' => 'محجوب-مجهول']),
    ];
    $hidden['frozen']->forceFill(['is_frozen' => true])->save();
    e30_company(['user_id' => $hidden['org']->id, 'is_public' => true]);
    e30_supporter(['user_id' => $hidden['private']->id, 'is_public' => false, 'type' => 'individual']);
    e30_supporter(['user_id' => $hidden['suspended']->id, 'is_public' => true, 'status' => SupporterProfile::STATUS_SUSPENDED, 'type' => 'individual']);

    foreach (array_merge($visible, $hidden) as $key => $user) {
        e44_sb_points($season, $user, 100, $key !== 'anonymous');
    }

    $names = array_column(e44_board($season)['rows'], 'name');
    sort($names);
    $expected = ['ظاهر-بلا-ملف', 'ظاهر-بملف-علني'];
    sort($expected);

    expect($names)->toBe($expected);

    // مجاميع الإدارة ترى الجميع (الحجب عرضي فقط، لا حذف ولا تعديل نقاط).
    expect(app(SeasonSupporterLeaderboardService::class)->totals($season)['supporters'])->toBe(7)
        ->and(app(SeasonSupporterLeaderboardService::class)->totals($season)['points'])->toBe(700)
        ->and(app(SeasonSupporterLeaderboardService::class)->totals($season)['public_points'])->toBe(600);
});

test('E44-SB-04: only the top N (default 100; here 3) are listed and ranked; the rank map for the dynamic badge follows the same cut', function () {
    config(['seasonal_recognition.supporters.leaderboard_limit' => 3]);
    $w = e44_supporters_world();
    $users = [];

    foreach ([500, 400, 300, 200, 100] as $points) {
        $users[$points] = e16User();
        e44_sb_points($w['season'], $users[$points], $points);
    }

    $rows = e44_board($w['season'])['rows'];
    $map = app(SeasonSupporterLeaderboardService::class)->rankMap($w['season']);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'points'))->toBe([500, 400, 300])
        ->and($map)->toBe([$users[500]->id => 1, $users[400]->id => 2, $users[300]->id => 3])
        ->and(isset($map[$users[200]->id]))->toBeFalse();
});

test('E44-SB-05: ranking uses PUBLIC points only; the owner still sees the full total privately', function () {
    $w = e44_supporters_world();
    $mixed = e16User(['name' => 'مختلط']);
    $rival = e16User(['name' => 'منافس']);

    e44_sb_points($w['season'], $mixed, 300, true);
    e44_sb_points($w['season'], $mixed, 700, false);
    e44_sb_points($w['season'], $rival, 400, true);

    $rows = e44_board($w['season'])['rows'];

    expect(array_column($rows, 'name'))->toBe(['منافس', 'مختلط'])
        ->and(array_column($rows, 'points'))->toBe([400, 300])
        ->and(e44_user_points($w['season'], $mixed))->toBe(1000);

    $page = $this->actingAs($mixed)->get(route('seasonal.supporters.show', $w['season']))->assertOk()->getContent();

    expect(str_contains($page, '1,000 نقطة دعم'))->toBeTrue()
        ->and(str_contains($page, 'مرتبتك الحالية #2'))->toBeTrue();
});

// ───────────────────────────── الإنهاء ─────────────────────────────

test('E44-SB-06: the ranking stays open until the season ends; finalization then freezes rank, public points and the public tier — late refunds touch live totals only', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $admin = $w['admin'];

    $ua = e16User(['name' => 'داعم-ماسي']);
    $ub = e16User(['name' => 'داعم-ذهبي']);
    $uc = e16User(['name' => 'داعم-فضي']);
    $ud = e16User(['name' => 'داعم-مجهول-فقط']);
    $ue = e16User(['name' => 'داعم-مجمّد']);

    e44_buy_card($ua, $w['packages']['diamond']);
    $gold = e44_buy_card($ub, $w['packages']['gold']);
    e44_buy_card($ub, $w['packages']['diamond'], true); // مجهولة: تُحتسب خاصةً ولا تُرفع بها مرتبته العلنية ولا مستواه العلني
    e44_buy_card($uc, $w['packages']['silver']);
    e44_buy_card($ud, $w['packages']['gold'], true);
    e44_buy_card($ue, $w['packages']['silver']);
    $ue->forceFill(['is_frozen' => true])->save();

    $preview = e44_supporter_finalizer()->preview($season);

    expect($preview['can_finalize'])->toBeFalse()
        ->and($preview['blockers'])->not->toBe([])
        ->and($preview['ranked'])->toBe(3)
        ->and(array_column($preview['top'], 'name'))->toBe(['داعم-ماسي', 'داعم-ذهبي', 'داعم-فضي'])
        ->and(e44_reason(fn () => e44_supporter_finalizer()->finalize($season, $admin)))->toBe('season_not_ended')
        ->and(e44_reason(fn () => e44_supporter_finalizer()->finalize($season, e44_staff(['seasonal-recognition.manage']))))->toBe('forbidden');

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));

    $preview = e44_supporter_finalizer()->preview($season);
    expect($preview['can_finalize'])->toBeTrue()->and($preview['blockers'])->toBe([]);

    $result = e44_supporter_finalizer()->finalize($season, $admin);

    expect($result)->toBe(['finalized' => true, 'already' => false, 'ranked' => 3, 'rows' => 5]);

    $stats = SeasonSupporterStat::query()->where('season_id', $season->id)->get()->keyBy('user_id');
    $snapshot = fn (User $u) => [$stats[$u->id]->final_rank, (int) $stats[$u->id]->final_points, $stats[$u->id]->final_card_tier_key];

    expect($snapshot($ua))->toBe([1, 2500, 'diamond'])
        ->and($snapshot($ub))->toBe([2, 1000, 'gold'])
        ->and($snapshot($uc))->toBe([3, 250, 'silver'])
        ->and($snapshot($ud))->toBe([null, 0, null])
        ->and($snapshot($ue))->toBe([null, 250, 'silver'])
        ->and($stats->every(fn ($s) => $s->finalized_at !== null))->toBeTrue()
        ->and((int) e44_settings()->settingFor($season)->supporters_finalized_by)->toBe((int) $admin->id)
        ->and(e44_audit('seasonal_supporters_finalized'))->toBe(1)
        ->and(e44_supporter_finalizer()->finalize($season, $admin))->toBe(['finalized' => false, 'already' => true, 'ranked' => 0, 'rows' => 0])
        ->and(e44_audit('seasonal_supporters_finalized'))->toBe(1);

    // إشعارات المرتّبين فقط، مرة واحدة.
    expect(e44_notes('supporter_final_rank', $ua))->toHaveCount(1)
        ->and(e44_notes('supporter_final_rank', $ub))->toHaveCount(1)
        ->and(e44_notes('supporter_final_rank', $uc))->toHaveCount(1)
        ->and(e44_notes('supporter_final_rank', $ud))->toHaveCount(0)
        ->and(e44_notes('supporter_final_rank', $ue))->toHaveCount(0);

    // استرداد متأخر: المجاميع الحيّة تنقص واللقطة النهائية كما هي.
    e44_refund_card($gold);

    $board = e44_board($season);
    $after = SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $ub->id)->firstOrFail();

    expect($board['finalized'])->toBeTrue()
        ->and(array_column($board['rows'], 'name'))->toBe(['داعم-ماسي', 'داعم-ذهبي', 'داعم-فضي'])
        ->and(array_column($board['rows'], 'points'))->toBe([2500, 1000, 250])
        ->and(array_column($board['rows'], 'rank'))->toBe([1, 2, 3])
        ->and((int) $after->points_total)->toBe(2500) // 3500 − 1000
        ->and((int) $after->public_points_total)->toBe(0)
        ->and((int) $after->final_points)->toBe(1000)
        ->and((int) $after->final_rank)->toBe(2)
        ->and(e44_supporter_reconciler()->inspect($season))->toBe([]);

    // لا منح جديد بعد الإنهاء، ولا طلب بطاقة، ولا إعادة بناء.
    expect(e44_points()->grant($season->id, $ua->id, 'supporter_card', 123456, 100, true))->toBeNull()
        ->and(e44_reason(fn () => e44_request_card(e16User(), $w['packages']['silver']->fresh())))->toBe('season_not_live')
        ->and(e44_reason(fn () => e44_supporter_reconciler()->rebuildStats($season, $admin)))->toBe('finalized');

    // اللقطة ثابتة في النموذج؛ المجاميع الحيّة وحدها تتحرّك.
    $stat = SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $ua->id)->firstOrFail();

    foreach (['final_rank' => 9, 'final_points' => 1, 'final_card_tier_key' => 'silver', 'finalized_at' => now()->addDay()] as $field => $value) {
        expect(fn () => $stat->fresh()->forceFill([$field => $value])->save())->toThrow(LogicException::class);
    }

    expect($stat->fresh()->forceFill(['points_total' => 1])->save())->toBeTrue(); // الحقل الحيّ يبقى قابلًا للتحديث (أي استثناء يُفشل الاختبار)
});

test('E44-SB-07: finalization is refused while the platform key is off, and a snapshot row left without its timestamp is reported', function () {
    $w = e44_supporters_world();
    $user = e16User();
    e44_buy_card($user, $w['packages']['silver']);

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));

    config(['seasonal_recognition.supporters.enabled' => false]);
    expect(e44_reason(fn () => e44_supporter_finalizer()->finalize($w['season'], $w['admin'])))->toBe('supporters_disabled')
        ->and(e44_supporter_finalizer()->preview($w['season'])['can_finalize'])->toBeFalse();
    config(['seasonal_recognition.supporters.enabled' => true]);

    e44_supporter_finalizer()->finalize($w['season'], $w['admin']);
    DB::table('season_supporter_stats')->where('season_id', $w['season']->id)->update(['finalized_at' => null]);

    expect(array_column(e44_supporter_reconciler()->inspect($w['season']), 'code'))->toBe(['supporters_snapshot_incomplete']);
});

// ───────────────────────────── الهوية الموسمية ─────────────────────────────

test('E44-SB-08: the seasonal name style and "supporter #N" badge are derived per verified public card — never for anonymous, frozen, organization or private-profile accounts', function () {
    $w = e44_supporters_world();
    $resolver = app(SeasonSupporterIdentityResolver::class);

    $diamond = e16User(['name' => 'ماسي']);
    $gold = e16User(['name' => 'ذهبي']);
    $silver = e16User(['name' => 'فضي']);
    $plain = e16User(['name' => 'عادي']);
    $anon = e16User(['name' => 'مجهول']);
    $frozen = e16User(['name' => 'مجمّد']);
    $org = e16User(['name' => 'مؤسسة']);
    $private = e16User(['name' => 'خاص']);

    e44_buy_card($diamond, $w['packages']['diamond']);
    e44_buy_card($gold, $w['packages']['gold']);
    e44_buy_card($silver, $w['packages']['silver']);
    e44_buy_card($anon, $w['packages']['gold'], true);
    e44_buy_card($frozen, $w['packages']['gold']);
    e44_buy_card($org, $w['packages']['gold']);
    e44_buy_card($private, $w['packages']['gold']);

    // الحالات التي تُحجب من الأسطح العامة (تُهيَّأ بعد الشراء كي لا يغيّر مسار E32 ملفاتها): حساب مجمَّد، ملف مؤسسة، ملف داعم خاص.
    $frozen->forceFill(['is_frozen' => true])->save();
    DB::table('supporter_profiles')->where('user_id', $org->id)->update(['type' => 'organization', 'is_public' => true]);
    DB::table('supporter_profiles')->where('user_id', $private->id)->update(['is_public' => false]);

    $all = [$diamond, $gold, $silver, $plain, $anon, $frozen, $org, $private];
    $out = $resolver->forUsers(array_map(fn ($u) => $u->id, $all));

    expect(array_keys($out))->toEqualCanonicalizing([$diamond->id, $gold->id, $silver->id])
        ->and($out[$diamond->id])->toBe(['tier' => 'diamond', 'tier_name' => 'الداعم الماسي', 'style' => 'diamond', 'rank' => 1, 'badge' => 'داعم الموسم #1'])
        ->and($out[$gold->id])->toBe(['tier' => 'gold', 'tier_name' => 'الداعم الذهبي', 'style' => 'gold', 'rank' => 2, 'badge' => 'داعم الموسم #2'])
        ->and($out[$silver->id])->toBe(['tier' => 'silver', 'tier_name' => 'الداعم الفضي', 'style' => 'silver', 'rank' => 3, 'badge' => 'داعم الموسم #3'])
        ->and($resolver->forUser($plain->id))->toBeNull()
        ->and($resolver->forUser($anon->id))->toBeNull()
        ->and($resolver->forUser($frozen->id))->toBeNull()
        ->and($resolver->forUser($org->id))->toBeNull()
        ->and($resolver->forUser($private->id))->toBeNull();

    // الأنماط من قائمة بيضاء مغلقة: لا CSS ولا ألوان حرة في النتيجة.
    expect(collect($out)->pluck('style')->every(fn ($s) => in_array($s, ['silver', 'gold', 'diamond'], true)))->toBeTrue()
        ->and(DB::table('season_supporter_cards')->whereNotIn('name_style_key', ['silver', 'gold', 'diamond'])->count())->toBe(0);
});

test('E44-SB-09: priority is diamond > gold > silver > none, falling back as cards are refunded; ambiguity, a disabled season or key hides everything', function () {
    $w = e44_supporters_world();
    $resolver = app(SeasonSupporterIdentityResolver::class);
    $user = e16User();

    expect($resolver->forUser($user->id))->toBeNull();

    $silver = e44_buy_card($user, $w['packages']['silver']);
    expect($resolver->forUser($user->id)['style'])->toBe('silver');

    $diamond = e44_buy_card($user, $w['packages']['diamond']);
    e44_buy_card($user, $w['packages']['gold']);
    expect($resolver->forUser($user->id)['style'])->toBe('diamond');

    e44_refund_card($diamond);
    expect($resolver->forUser($user->id)['style'])->toBe('gold');

    // موسم ثانٍ مؤهَّل ⇒ غموض ⇒ لا تقدير يُعرض (والكل يعمل كما كان).
    $second = e44_season();
    e44_settings()->setSupportersEnabled($second, true, $w['admin']);
    e44_sb_forget_identity_cache();
    expect($resolver->forUser($user->id))->toBeNull();

    e44_settings()->setSupportersEnabled($second, false, $w['admin']);
    e44_sb_forget_identity_cache();
    expect($resolver->forUser($user->id)['style'])->toBe('gold');

    e44_settings()->setSupportersEnabled($w['season'], false, $w['admin']);
    e44_sb_forget_identity_cache();
    expect($resolver->forUser($user->id))->toBeNull();

    e44_settings()->setSupportersEnabled($w['season'], true, $w['admin']);
    e44_sb_forget_identity_cache();
    expect($resolver->forUser($user->id)['style'])->toBe('gold');

    config(['seasonal_recognition.supporters.enabled' => false]);
    expect($resolver->forUsers([$user->id]))->toBe([]);
});

test('E44-SB-10: resolving identities for a whole page of users costs a constant number of queries', function () {
    $w = e44_supporters_world();
    $resolver = app(SeasonSupporterIdentityResolver::class);
    $users = [];

    foreach (['silver', 'gold', 'diamond', 'silver', 'gold', 'diamond'] as $tier) {
        $users[] = $user = e16User();
        e44_buy_card($user, $w['packages'][$tier]);
    }

    $ids = array_map(fn ($u) => $u->id, $users);
    $resolver->forUsers($ids); // يدفّئ كاش الموسم المعروض وخريطة المراتب

    $few = e44_queries(fn () => $resolver->forUsers(array_slice($ids, 0, 2)));
    $many = e44_queries(fn () => $resolver->forUsers($ids));

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(3)
        ->and(count($resolver->forUsers($ids)))->toBe(6);
});

test('E44-SB-11: the player profile shows the style and badge for a verified public supporter only', function () {
    $w = e44_supporters_world();
    $viewer = e16User();
    $supporter = e16User(['name' => 'لاعب داعم']);
    $anonymous = e16User(['name' => 'لاعب مجهول الدعم']);

    e44_buy_card($supporter, $w['packages']['diamond']);
    e44_buy_card($anonymous, $w['packages']['diamond'], true);

    $html = $this->actingAs($viewer)->get(route('players.show', $supporter))->assertOk()->getContent();

    expect(str_contains($html, 'data-season-supporter'))->toBeTrue()
        ->and(str_contains($html, 'داعم الموسم #1'))->toBeTrue()
        ->and(preg_match('/<h1[^>]*data-season-style="diamond"/', $html))->toBe(1);

    $other = $this->actingAs($viewer)->get(route('players.show', $anonymous))->assertOk()->getContent();

    expect(str_contains($other, 'data-season-supporter'))->toBeFalse()
        ->and(preg_match('/<h1[^>]*data-season-style=/', $other))->toBe(0);

    // لا مخزون ولا ملكية تتغير: النمط عرض مشتقّ فقط.
    expect(DB::table('user_inventory_items')->where('user_id', $supporter->id)->count())->toBe(0);
});

// ───────────────────────────── طابور الإشارة الاجتماعية ─────────────────────────────

test('E44-SB-12: the social queue is a manual register — eligible only for non-anonymous gold/diamond; strict transitions; permissioned, audited and with a clipped plain-text note', function () {
    $w = e44_supporters_world();
    $queue = app(SeasonSupporterSocialQueueService::class);
    $gold = e16User(['name' => 'ذهبي-طابور']);
    $diamond = e16User(['name' => 'ماسي-طابور']);
    $silver = e16User(['name' => 'فضي-طابور']);
    $anonymous = e16User(['name' => 'مجهول-طابور']);

    e44_buy_card($gold, $w['packages']['gold']);
    e44_buy_card($diamond, $w['packages']['diamond']);
    e44_buy_card($silver, $w['packages']['silver']);
    e44_buy_card($anonymous, $w['packages']['diamond'], true);

    $list = $queue->list($w['season']);

    expect($list->pluck('name')->sort()->values()->all())->toBe(collect(['ذهبي-طابور', 'ماسي-طابور'])->sort()->values()->all())
        ->and($list->pluck('social_status')->unique()->values()->all())->toBe(['eligible'])
        ->and($queue->list($w['season'], 'queued')->count())->toBe(0);

    $stat = e44_stat($w['season'], $gold);

    expect(e44_reason(fn () => $queue->transition($stat, 'queued', e44_staff(['seasonal-recognition.view']))))->toBe('forbidden')
        ->and(e44_reason(fn () => $queue->transition($stat, 'archived', $w['admin'])))->toBe('invalid_status')
        ->and(e44_reason(fn () => $queue->transition($stat, 'published', $w['admin'])))->toBe('transition_not_allowed') // eligible ↛ published
        ->and(e44_reason(fn () => $queue->transition(e44_stat($w['season'], $silver), 'queued', $w['admin'])))->toBe('transition_not_allowed') // لا وسم أصلًا
        ->and(e44_reason(fn () => $queue->transition(e44_stat($w['season'], $anonymous), 'queued', $w['admin'])))->toBe('transition_not_allowed');

    $queued = $queue->transition($stat, 'queued', $w['admin'], '<b>نشر</b> '.str_repeat('ج', 400));

    expect($queued->social_status)->toBe('queued')
        ->and(str_contains((string) $queued->social_note, '<'))->toBeFalse()
        ->and(mb_strlen((string) $queued->social_note))->toBeLessThanOrEqual(300)
        ->and((int) $queued->social_updated_by)->toBe((int) $w['admin']->id)
        ->and($queue->transition($stat->fresh(), 'queued', $w['admin'])->social_status)->toBe('queued') // نفس الحالة: لا أثر
        ->and(e44_audit('seasonal_supporter_social_queued'))->toBe(1);

    $published = $queue->transition($stat->fresh(), 'published', $w['admin'], 'تم النشر');

    expect($published->social_status)->toBe('published')
        ->and(e44_reason(fn () => $queue->transition($stat->fresh(), 'skipped', $w['admin'])))->toBe('transition_not_allowed')
        ->and(e44_reason(fn () => $queue->transition($stat->fresh(), 'eligible', $w['admin'])))->toBe('transition_not_allowed');

    // تخطٍّ ثم إعادة للأهلية.
    $dStat = e44_stat($w['season'], $diamond);
    $queue->transition($dStat, 'skipped', $w['admin']);
    expect($queue->transition($dStat->fresh(), 'eligible', $w['admin'])->social_status)->toBe('eligible');

    // استرداد البطاقة يسحب «eligible» آليًا لكنه لا يمسّ قرارًا إداريًا (published).
    $goldCard = SeasonSupporterCard::query()->where('user_id', $gold->id)->firstOrFail();
    $diamondCard = SeasonSupporterCard::query()->where('user_id', $diamond->id)->firstOrFail();
    e44_refund_card($goldCard);
    e44_refund_card($diamondCard);

    expect(e44_stat($w['season'], $gold)->social_status)->toBe('published')
        ->and(e44_stat($w['season'], $diamond)->social_status)->toBeNull();
});

test('E44-SB-13: no external social-network call exists — the queue and the whole feature never use HTTP clients, mail, webhooks or external SDKs', function () {
    $needles = ['Http::', 'Illuminate\\Support\\Facades\\Http', 'GuzzleHttp', 'curl_', 'file_get_contents(\'http', 'Facebook', 'Instagram', 'Twitter', 'TikTok', 'Telegram', 'Webhook', 'Mail::'];

    foreach (glob(base_path('app/Services/SeasonalRecognition/*.php')) as $file) {
        $code = e44_code(str_replace(base_path().DIRECTORY_SEPARATOR, '', $file));

        foreach ($needles as $needle) {
            expect(str_contains($code, $needle))->toBeFalse("{$file} references {$needle}");
        }
    }
});

// ───────────────────────────── الصفحات والبثّ ─────────────────────────────

test('E44-SB-14: the public supporters page lists server-priced cards, never leaks private data and its standings fragment is a public, short-cached HTML partial', function () {
    $w = e44_supporters_world();
    $top = e16User(['name' => 'متصدر-الداعمين']);
    $hidden = e16User(['name' => 'مجهول-لن-يظهر']);

    e44_buy_card($top, $w['packages']['gold']);
    e44_buy_card($hidden, $w['packages']['diamond'], true);

    $page = $this->get(route('seasonal.supporters.show', $w['season']))->assertOk();
    $html = $page->getContent();

    foreach (['الداعم الفضي', 'الداعم الذهبي', 'الداعم الماسي', '25.00 USD', '100.00 USD', '250.00 USD', 'نقطة دعم (تقدير فقط)', 'سجّل الدخول'] as $expected) {
        expect(str_contains($html, $expected))->toBeTrue("missing: {$expected}");
    }

    expect(str_contains($html, 'متصدر-الداعمين'))->toBeTrue()
        ->and(str_contains($html, 'مجهول-لن-يظهر'))->toBeFalse()
        ->and(str_contains($html, 'name="tier"'))->toBeFalse(); // الزائر يرى الأسعار بلا نموذج شراء

    $fragment = $this->get(route('seasonal.supporters.standings', $w['season']))->assertOk();

    expect($fragment->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=5')
        ->and($fragment->headers->get('Content-Type'))->toContain('text/html')
        ->and(str_contains($fragment->getContent(), 'data-rank="1"'))->toBeTrue()
        ->and(str_contains($fragment->getContent(), 'مجهول-لن-يظهر'))->toBeFalse();

    // المسجّل يرى النموذج.
    $form = $this->actingAs(e16User())->get(route('seasonal.supporters.show', $w['season']))->assertOk()->getContent();
    expect(str_contains($form, 'name="tier"'))->toBeTrue()->and(str_contains($form, 'name="visibility"'))->toBeTrue();
});

test('E44-SB-15: visibility — a draft season or a season without the feature is a 404 (staff with the view permission excepted); history stays visible after the key is switched off', function () {
    $admin = e44_admin();
    $draft = e44_season(['published' => false]);
    e44_settings()->setSupportersEnabled($draft, true, $admin);
    $plain = e44_season();

    $this->get(route('seasonal.supporters.show', $draft))->assertNotFound();
    $this->get(route('seasonal.supporters.standings', $draft))->assertNotFound();
    $this->get(route('seasonal.supporters.show', $plain))->assertNotFound();
    $this->actingAs($admin)->get(route('seasonal.supporters.show', $draft))->assertOk();

    $w = e44_supporters_world();
    e44_buy_card(e16User(), $w['packages']['silver']);
    e44_settings()->setSupportersEnabled($w['season'], false, $w['admin']);

    $this->get(route('seasonal.supporters.show', $w['season']))->assertOk()->assertSee('البطاقات غير متاحة حاليًا');
});

test('E44-SB-16: once finalized the page says so, hides the purchase form and shows the owner their final rank', function () {
    $w = e44_supporters_world();
    $user = e16User();
    e44_buy_card($user, $w['packages']['gold']);

    Carbon::setTestNow(Carbon::parse('2026-12-31 00:00:00'));
    e44_supporter_finalizer()->finalize($w['season'], $w['admin']);

    $html = $this->actingAs($user)->get(route('seasonal.supporters.show', $w['season']))->assertOk()->getContent();

    expect(str_contains($html, 'انتهى ترتيب الداعمين لهذا الموسم'))->toBeTrue()
        ->and(str_contains($html, 'الترتيب النهائي للداعمين'))->toBeTrue()
        ->and(str_contains($html, 'مرتبتك النهائية #1'))->toBeTrue()
        ->and(str_contains($html, 'name="tier"'))->toBeFalse();
});

test('E44-SB-17: a committed grant broadcasts a data-free signal on a PUBLIC channel; a zero-point purchase and an unchanged state broadcast nothing', function () {
    $w = e44_supporters_world();
    Event::fake([SeasonSupportersLeaderboardUpdated::class]);

    e44_buy(e16User(), e44_store_item(0, 10));
    Event::assertNotDispatched(SeasonSupportersLeaderboardUpdated::class);

    $buyer = e16User();
    e44_buy($buyer, e44_store_item(25, 10));

    Event::assertDispatched(SeasonSupportersLeaderboardUpdated::class, function (SeasonSupportersLeaderboardUpdated $event) use ($w, $buyer) {
        $channels = $event->broadcastOn();

        return $channels[0] instanceof Channel
            && ! ($channels[0] instanceof PrivateChannel)
            && $channels[0]->name === 'seasonal-supporters.'.$w['season']->id
            && $event->broadcastAs() === 'leaderboard.updated'
            && $event->broadcastWith() === ['season' => $w['season']->id]
            && ! str_contains(json_encode($event->broadcastWith()), (string) $buyer->email);
    });
});

test('E44-SB-18: board reads are cached by version — a warm hit costs no queries and any grant or reversal invalidates it', function () {
    $w = e44_supporters_world();
    $user = e16User(['name' => 'مخبّأ']);
    e44_buy(e16User(), e44_store_item(10, 10));
    $service = app(SeasonSupporterLeaderboardService::class);

    $cold = $service->publicBoard($w['season']);
    $warm = e44_queries(fn () => $service->publicBoard($w['season']));

    expect($warm)->toBe(0)->and($service->publicBoard($w['season']))->toBe($cold);

    e44_buy($user, e44_store_item(500, 10));
    $fresh = $service->publicBoard($w['season']);

    expect($fresh['supporters_count'])->toBe(2)
        ->and($fresh['rows'][0]['name'])->toBe('مخبّأ')
        ->and($fresh['rows'][0]['points'])->toBe(500);
});

test('E44-SB-19: an anonymous card is shown as "anonymous supporter" in the recent list with no name and no style, and never in the ranking', function () {
    $w = e44_supporters_world();
    $service = app(SeasonSupporterLeaderboardService::class);
    $anonymous = e16User(['name' => 'اسم-سري-للغاية']);
    $open = e16User(['name' => 'اسم-علني-ظاهر']);

    e44_buy_card($anonymous, $w['packages']['diamond'], true);
    e44_buy_card($open, $w['packages']['silver']);

    // دعمٌ مجهول يُنشئ ملف E30 خاصًا فلا يظهر في أي سطح؛ نجعل ملفه علنيًا (قرار إداري) لنتحقق أن البطاقة تبقى بلا اسم.
    DB::table('supporter_profiles')->where('user_id', $anonymous->id)->update(['is_public' => true]);
    SeasonalRecognitionCache::bump((int) $w['season']->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);

    $recent = $service->recentCards($w['season']);
    $byAnonymity = collect($recent)->keyBy(fn ($r) => $r['anonymous'] ? 'anonymous' : 'public');

    expect($recent)->toHaveCount(2)
        ->and($byAnonymity['anonymous']['name'])->toBe('داعم مجهول')
        ->and($byAnonymity['anonymous']['tier'])->toBe('diamond')
        ->and($byAnonymity['public']['name'])->toBe('اسم-علني-ظاهر')
        ->and(array_column(e44_board($w['season'])['rows'], 'name'))->toBe(['اسم-علني-ظاهر']);

    $html = $this->get(route('seasonal.supporters.show', $w['season']))->assertOk()->getContent();

    expect(str_contains($html, 'داعم مجهول'))->toBeTrue()
        ->and(str_contains($html, 'اسم-سري-للغاية'))->toBeFalse()
        ->and(app(SeasonSupporterIdentityResolver::class)->forUser($anonymous->id))->toBeNull();
});

test('E44-SB-20: the reconcile command is read-only — exit 0 on consistent books, exit 1 with the finding code on drift (and nothing repaired), 1 for an unknown slug; the explicit admin rebuild then clears it', function () {
    $w = e44_supporters_world();
    $season = $w['season'];
    $buyer = e16User();
    e44_buy($buyer, e44_store_item(40, 10));

    $this->artisan('seasonal-recognition:reconcile', ['season' => $season->slug])->expectsOutputToContain('الدفاتر والمجاميع متسقة.')->assertExitCode(0);
    $this->artisan('seasonal-recognition:reconcile')->assertExitCode(0); // كل المواسم التي لها إعدادات

    // انجراف مصطنع في الإحصاء (يتجاوز النموذج عمدًا): الأمر يكشفه ولا يصلحه.
    DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->update(['points_total' => 999]);
    $audits = e44_audit('seasonal_supporters_stats_rebuilt');

    $this->artisan('seasonal-recognition:reconcile', ['season' => $season->slug, '--limit' => 5])->expectsOutputToContain('stat_points_mismatch')->expectsOutputToContain('لا إصلاح آلي')->assertExitCode(1);

    expect((int) DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->value('points_total'))->toBe(999)
        ->and(e44_audit('seasonal_supporters_stats_rebuilt'))->toBe($audits);

    $this->artisan('seasonal-recognition:reconcile', ['season' => 'no-such-season'])->expectsOutputToContain('لا موسم بهذا المعرّف.')->assertExitCode(1);

    // الإصلاح إجراء إداري صريح مسجَّل في التدقيق (لا يتم من الأمر).
    e44_supporter_reconciler()->rebuildStats($season, $w['admin']);

    expect((int) DB::table('season_supporter_stats')->where('season_id', $season->id)->where('user_id', $buyer->id)->value('points_total'))->toBe(40)
        ->and(e44_audit('seasonal_supporters_stats_rebuilt'))->toBe($audits + 1);

    $this->artisan('seasonal-recognition:reconcile', ['season' => $season->slug])->assertExitCode(0);
});
