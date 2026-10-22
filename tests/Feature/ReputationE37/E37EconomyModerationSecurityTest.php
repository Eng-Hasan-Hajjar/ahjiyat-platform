<?php

use App\Filament\Pages\ReputationCenterPage;
use App\Models\Achievement;
use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Discussions\DiscussionReportService;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\FeaturedCreatorService;
use App\Services\Reputation\ReputationExplainer;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../SponsorshipE30/_support.php';

/*
| E37 AV10 (67-72) فصل الاقتصاد + AV11 (73-77) الإشراف + AV12 (78-84) الأمان.
| السمعة لا تقرأ ولا تكتب أي دفتر مالي/رعاية/دفع/متجر/إحالة/دردشة؛ البلاغات وحدها لا تعاقب؛ ولا مدخل لتعديل درجة من العميل أو الإدارة.
*/

beforeEach(fn () => e37_seed());

/** استعلامات تلمس جداول محظورة على السمعة (اقتصاد/رعاية/دفع/متجر/إحالة/دردشة/احتيال). @param list<string> $sql @return list<string> */
function e37_forbidden_hits(array $sql): array
{
    $pattern = '/\b(from|join|into|update)\s+["`\[]?(wallets|gem_\w*|currenc\w*|store_\w*|\w*referral\w*|sponsor\w*|supporter\w*|payment\w*|redemption\w*|fraud\w*|chat\w*)/';

    return array_values(array_filter($sql, fn (string $q) => preg_match($pattern, $q) === 1));
}

/** أعمدة الدرجات/الأهلية/المصادر لصف لقطة (بلا الطوابع) للمقارنة. @return array<string, mixed> */
function e37_score_columns(User $user): array
{
    $row = (array) DB::table('community_reputation_snapshots')->where('user_id', $user->id)->first();
    unset($row['id'], $row['created_at'], $row['updated_at'], $row['calculated_at']);

    return $row;
}

/* ===================== AV10. ECONOMY SEPARATION ===================== */

test('E37-067: computing reputation never reads or writes economy / sponsorship / payment / store / referral / chat / fraud tables (SQL-level proof)', function () {
    [$creator] = e37_eligible_creator();
    [$owner] = e37_curator(6, 2);
    [$author] = e37_helper(5, 10);
    $ids = [$creator->id, $owner->id, $author->id];

    $dry = e37_sql(fn () => app(CommunityReputationService::class)->refreshUsers($ids, true));
    $real = e37_sql(fn () => app(CommunityReputationService::class)->refreshUsers($ids));

    expect($dry)->not->toBeEmpty()->and(e37_forbidden_hits($dry))->toBe([])->and($real)->not->toBeEmpty()->and(e37_forbidden_hits($real))->toBe([]);
});

test('E37-068: wallet balances, supporter status and confirmed sponsorship contributions do not change any score or source column', function () {
    [$creator, $works, $raters] = e37_eligible_creator();
    e37_refresh($creator);
    $before = e37_score_columns($creator);

    foreach ([$creator, ...$raters] as $user) {
        DB::table('wallets')->updateOrInsert(['user_id' => $user->id], ['available_balance' => 999999, 'pending_balance' => 5000, 'lifetime_earned' => 999999, 'created_at' => now(), 'updated_at' => now()]);
    }

    $supporter = e30_supporter();
    $program = e30_program();
    e30_confirmed($supporter, $program, '500');
    e37_refresh($creator);

    expect(e37_score_columns($creator))->toEqual($before);
});

test('E37-069: a full recalculation across a populated world writes no XP, currency, gems, wallet, referral, redemption, payment or competition rows', function () {
    [$creator] = e37_eligible_creator();
    [$owner] = e37_curator(10, 2);
    [$author] = e37_helper(5, 10);
    $before = e37_world($creator, $owner, $author);

    $this->artisan('reputation:recalculate', ['--all' => true])->assertExitCode(0);
    $this->artisan('reputation:recalculate', ['--stale' => true])->assertExitCode(0);
    $this->artisan('reputation:reconcile', ['--fix' => true])->assertExitCode(0);
    $after = e37_world($creator, $owner, $author);

    expect(CommunityReputationSnapshot::query()->count())->toBeGreaterThanOrEqual(3)->and($after['counts'])->toEqual($before['counts'])->and($after['wallets'])->toBe($before['wallets'])
        ->and($after['xp'])->toBe($before['xp'])->and($after['per_user'])->toEqual($before['per_user']);
});

test('E37-070: reading the public boards, the creator page and Discover writes nothing (SELECT-only)', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);
    $slug = e37_profile($creator)->slug;

    $sql = e37_sql(function () use ($slug) {
        $this->get(route('community.rankings'))->assertOk();
        $this->get(route('creators.show', $slug))->assertOk();
        $this->get(route('discover.index'))->assertOk();
        e37_rankings()->creators();
        e37_rankings()->rising();
        e37_rankings()->curators();
        e37_rankings()->helpful();
        e37_rankings()->featured();
    });

    $writes = array_values(array_filter($sql, fn (string $q) => preg_match('/^\s*(insert|update|delete|replace)\b/', $q) === 1 && preg_match('/\b(sessions|cache|cache_locks|device_sightings)\b/', $q) !== 1)); // بنية الجلسة/الكاش/رصد الجهاز العامة ليست من السمعة

    expect($sql)->not->toBeEmpty()->and($writes)->toBe([]);
});

test('E37-071: no public route can mutate reputation - every rankings/reputation route is GET/HEAD only', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'rankings') || str_contains($r->uri(), 'reputation') || str_contains((string) $r->getName(), 'rankings') || str_contains((string) $r->getName(), 'reputation'))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'admin') || str_starts_with($r->uri(), 'livewire'));

    expect($routes)->not->toBeEmpty()->and($routes->every(fn ($r) => array_diff($r->methods(), ['GET', 'HEAD']) === []))->toBeTrue();
});

test('E37-072: featuring never feeds scores and a high score never auto-features (the two are independent)', function () {
    [$creator] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    e37_refresh($creator);
    $before = e37_score_columns($creator);
    $rank = e37_names(e37_rankings()->creators());

    expect(CommunityFeaturedCreator::query()->count())->toBe(0);

    app(FeaturedCreatorService::class)->feature(e37_profile($creator), e37_admin());
    e37_refresh($creator);

    expect(e37_score_columns($creator))->toEqual($before)->and(e37_names(e37_rankings()->creators()))->toBe($rank);

    app(FeaturedCreatorService::class)->unfeature(e37_profile($creator), e37_admin());
    e37_refresh($creator);

    expect(e37_score_columns($creator))->toEqual($before);
});

/* ===================== AV11. MODERATION ===================== */

test('E37-073: reports alone carry no penalty - open reports against a liked comment do not change the helpful signal', function () {
    [$author, $comments] = e37_helper(5, 10);
    e37_refresh($author);
    $before = e37_score_columns($author);

    foreach (e37_users(5) as $reporter) {
        app(DiscussionReportService::class)->report($reporter, $comments[0]->public_id, 'spam');
    }

    expect(DB::table('discussion_comment_reports')->where('discussion_comment_id', $comments[0]->id)->count())->toBeGreaterThanOrEqual(1);

    e37_refresh($author);

    expect(e37_score_columns($author))->toEqual($before)->and(e37_names(e37_rankings()->helpful()))->toBe([$author->name]);

    $source = collect(glob(base_path('app/Services/Reputation/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");

    expect(preg_match('/_reports\b|moderation_(reason|note|log)|handled_by|open_marker/', $source))->toBe(0);
});

test('E37-074: moderation-hidden works, comments and collections are excluded from every track, and return on restore', function () {
    [$creator, $works] = e37_eligible_creator();
    [$owner, $collections] = e37_curator(6, 2);
    [$author, $comments] = e37_helper(5, 10);
    e37_refresh($creator, $owner, $author);

    expect((bool) e37_snap($creator)->creator_eligible)->toBeTrue()->and((bool) e37_snap($owner)->curator_eligible)->toBeTrue()->and((bool) e37_snap($author)->helpful_eligible)->toBeTrue();

    e29_hide($works[0]);
    e35_hide_collection($collections[0]);
    e36_hide($comments[0]);
    e37_refresh($creator, $owner, $author);

    expect((int) e37_snap($creator)->works_count)->toBe(1)->and((bool) e37_snap($creator)->creator_eligible)->toBeFalse()
        ->and((int) e37_snap($owner)->curator_collections_count)->toBe(1)->and((bool) e37_snap($owner)->curator_eligible)->toBeFalse()
        ->and((int) e37_snap($author)->helpful_likers_count)->toBe(0)->and((bool) e37_snap($author)->helpful_eligible)->toBeFalse();
});

test('E37-075: a frozen account drops off every board at once and returns when unfrozen', function () {
    [$user, $works] = e37_eligible_creator();

    $col = e35_collection($user, e35_works(3), 'public', 'مجموعة المبدع');
    $col2 = e35_collection($user, e35_works(3), 'public', 'مجموعة ثانية');

    foreach (e37_users(6) as $saver) {
        foreach ([$col, $col2] as $c) {
            DB::table('user_collection_saves')->insert(['user_id' => $saver->id, 'user_collection_id' => $c->id, 'created_at' => now()]);
        }
    }

    for ($i = 0; $i < 5; $i++) {
        $made = e36_say($user, e36_subject_or_fail(e36_work()));
        $first ??= $made;
    }

    foreach (e37_users(10) as $fan) {
        DB::table('discussion_comment_likes')->insert(['user_id' => $fan->id, 'discussion_comment_id' => $first->id, 'created_at' => now()]);
    }

    e37_refresh($user);
    $boards = fn () => [e37_names(e37_rankings()->creators()), e37_names(e37_rankings()->curators()), e37_names(e37_rankings()->helpful())];

    expect($boards())->toBe([[$user->name], [$user->name], [$user->name]]);

    $user->forceFill(['is_frozen' => true])->save();

    expect($boards())->toBe([[], [], []]);

    $user->forceFill(['is_frozen' => false])->save();

    expect($boards())->toBe([[$user->name], [$user->name], [$user->name]]);
});

test('E37-076: fraud flags and moderation history are never public - not in boards, profiles, schema or the explainer', function () {
    [$creator] = e37_eligible_creator();
    DB::table('fraud_flags')->insert(['user_id' => $creator->id, 'reason' => 'abnormal_earn_rate', 'severity' => 'high', 'details' => 'تفصيل-احتيال-سري', 'resolved' => false, 'created_at' => now(), 'updated_at' => now()]);
    $comment = e36_say($creator, e36_subject_or_fail(e36_work()), 'تعليق سيُخفى');
    e36_hide($comment, null, 'spam', 'ملاحظة-إشراف-سرية');
    e37_refresh($creator);

    $pages = [
        $this->get(route('community.rankings'))->assertOk()->getContent(),
        $this->get(route('creators.show', e37_profile($creator)->slug))->assertOk()->getContent(),
        $this->get(route('players.show', $creator->public_id))->assertOk()->getContent(),
    ];

    foreach ($pages as $html) {
        expect($html)->not->toContain('تفصيل-احتيال-سري')->not->toContain('ملاحظة-إشراف-سرية')->not->toContain('abnormal_earn_rate')->not->toContain('fraud');
    }

    $columns = DB::getSchemaBuilder()->getColumnListing('community_reputation_snapshots');

    expect(collect($columns)->filter(fn ($c) => preg_match('/fraud|flag|report|moderat|penalt|suspic|ban/', $c))->all())->toBe([]);

    $explain = json_encode(app(ReputationExplainer::class)->forSnapshot(e37_snap($creator)), JSON_UNESCAPED_UNICODE);

    expect($explain)->not->toContain('fraud')->not->toContain('احتيال')->not->toContain('ملاحظة-إشراف-سرية')->not->toContain('moderat');
});

test('E37-077: a moderation restore recovers the exact earlier scores (derived and deterministic, no sticky penalty)', function () {
    [$creator, $works] = e37_eligible_creator();
    [$owner, $collections] = e37_curator(6, 2);
    [$author, $comments] = e37_helper(5, 10);
    e37_refresh($creator, $owner, $author);
    $before = [e37_score_columns($creator), e37_score_columns($owner), e37_score_columns($author)];

    e29_hide($works[0]);
    e35_hide_collection($collections[0]);
    e36_hide($comments[0]);
    e37_refresh($creator, $owner, $author);
    expect(e37_score_columns($creator))->not->toEqual($before[0]);

    e29_unhide($works[0]);
    DB::table('user_collections')->where('id', $collections[0]->id)->update(['is_moderation_hidden' => false, 'moderation_hidden_at' => null]);
    DB::table('discussion_comments')->where('id', $comments[0]->id)->update(['status' => \App\Models\DiscussionComment::STATUS_VISIBLE]);
    e37_refresh($creator, $owner, $author);

    expect([e37_score_columns($creator), e37_score_columns($owner), e37_score_columns($author)])->toEqual($before);
});

/* ===================== AV12. SECURITY ===================== */

test('E37-078: the client cannot set a score, rank or tier - mutating verbs are rejected and query/body input is ignored', function () {
    [$a] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    [$b] = e37_eligible_creator([4, 4, 3, 4, 4, 3]);
    e37_refresh($a, $b);
    $order = e37_names(e37_rankings()->creators());
    $payload = ['creator_score' => 10000, 'score' => 10000, 'tier' => 'gold', 'rank' => 1, 'user_id' => $b->id, 'featured' => 1];

    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        $this->{$verb}(route('community.rankings'), $payload)->assertStatus(405);
    }

    $html = $this->get(route('community.rankings').'?'.http_build_query($payload))->assertOk()->getContent();

    expect(e37_names(e37_rankings()->creators()))->toBe($order)->and(strpos($html, $a->name))->toBeLessThan(strpos($html, $b->name))
        ->and((int) e37_snap($b)->creator_score)->toBeLessThan(10000)->and(CommunityFeaturedCreator::query()->count())->toBe(0);
});

test('E37-079: reputation models are fully guarded - mass assignment of scores, tiers or featuring is rejected', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);

    expect(fn () => CommunityReputationSnapshot::query()->create(['user_id' => $creator->id, 'creator_score' => 10000]))->toThrow(MassAssignmentException::class)
        ->and(fn () => e37_snap($creator)->fill(['creator_score' => 10000]))->toThrow(MassAssignmentException::class)
        ->and(fn () => CommunityFeaturedCreator::query()->create(['creator_profile_id' => e37_profile($creator)->id]))->toThrow(MassAssignmentException::class)
        ->and(fn () => $creator->fill(['creator_score' => 10000, 'reputation_tier' => 'gold']))->not->toThrow(Throwable::class);

    expect(array_key_exists('creator_score', $creator->fresh()->getAttributes()))->toBeFalse();
});

test('E37-080: the admin center has no score/tier input; recalculation is gated to administrators and featuring to editors', function () {
    $own = collect((new ReflectionClass(ReputationCenterPage::class))->getProperties(ReflectionProperty::IS_PUBLIC))->filter(fn ($p) => $p->getDeclaringClass()->getName() === ReputationCenterPage::class)->map->getName()->sort()->values()->all();
    $methods = collect((new ReflectionClass(ReputationCenterPage::class))->getMethods(ReflectionMethod::IS_PUBLIC))->filter(fn ($m) => $m->getDeclaringClass()->getName() === ReputationCenterPage::class)->map->getName()->sort()->values()->all();

    expect($own)->toBe(['inspect', 'lookup', 'note'])->and(collect($methods)->filter(fn ($m) => preg_match('/score|tier|rank|set[A-Z]|update|save|edit|grant|award/i', $m))->all())->toBe([]);

    [$target] = e37_eligible_creator();
    $slug = e37_profile($target)->slug;

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ReputationCenterPage::class)->assertSuccessful()->set('lookup', $slug)->call('search')->call('recalculate')->assertForbidden();

    $this->actingAs(e37_manager());
    Livewire::test(ReputationCenterPage::class)->set('lookup', $slug)->call('search')->call('recalculate')->assertForbidden();
    Livewire::test(ReputationCenterPage::class)->set('lookup', $slug)->call('search')->call('feature');

    expect(CommunityFeaturedCreator::query()->count())->toBe(1);

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ReputationCenterPage::class)->call('unfeature', $slug);

    expect(CommunityFeaturedCreator::query()->activeNow()->count())->toBe(1); // المشرف لا يملك reputation.feature

    $this->actingAs(e37_admin());
    $page = Livewire::test(ReputationCenterPage::class)->set('lookup', $slug)->call('search')->call('recalculate');

    expect(e37_snap($target))->not->toBeNull()->and(e36_audit('reputation_recalculated'))->not->toBeNull();
});

test('E37-081: the public boards leak nothing private - no email, internal id, frozen or ineligible account, only name and slug', function () {
    [$creator] = e37_eligible_creator();
    $frozen = e37_bulk_creator(9000, 2, [], ['name' => 'مجمَّد-سري']);
    $frozen->forceFill(['is_frozen' => true])->save();
    $thin = e37_bulk_creator(9500, 2, ['creator_eligible' => false], ['name' => 'غير-مؤهل']);
    e37_refresh($creator);

    $html = $this->get(route('community.rankings'))->assertOk()->getContent();

    expect($html)->toContain($creator->name)->not->toContain($creator->email)->not->toContain('مجمَّد-سري')->not->toContain('غير-مؤهل')
        ->not->toContain('user_id')->not->toContain('is_frozen')->not->toContain('email_verified');
});

test('E37-082: user-controlled strings are escaped (creator name, achievement names) - no stored XSS', function () {
    $evil = '<script>alert(1)</script>';
    $user = e37_bulk_creator(7000, 2, [], ['name' => $evil]);

    $html = $this->get(route('community.rankings'))->assertOk()->getContent();

    expect($html)->not->toContain($evil)->toContain('&lt;script&gt;');

    e37_recognition_seed();
    Achievement::query()->where('internal_key', 'community_helpful_voice')->first()->forceFill(['name' => '<img src=x onerror=alert(1)>'])->save();
    [$author] = e37_helper(5, 10);
    e37_refresh($author);

    $page = $this->get(route('players.show', $author->public_id))->assertOk()->getContent();

    expect($page)->not->toContain('<img src=x onerror')->toContain('&lt;img');
});

test('E37-083: admin access rules (guest redirected, player forbidden, moderator allowed) and the public rankings are throttled', function () {
    $this->get('/admin/reputation-center')->assertRedirect();

    $this->flushSession();
    $this->actingAs(e16User())->get('/admin/reputation-center')->assertForbidden();

    $this->flushSession();
    $this->actingAs(e28_reviewer('moderator'))->get('/admin/reputation-center')->assertOk();

    $this->flushSession();
    auth()->logout();

    $last = null;

    for ($i = 0; $i < 95; $i++) {
        $last = $this->get(route('community.rankings'))->getStatusCode();

        if ($last === 429) {
            break;
        }
    }

    expect($last)->toBe(429);
});

test('E37-084: a disabled feature is fully off - 404 page, empty boards, no recognition, no Discover sections, no computation', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);
    e37_recognition_seed();
    e37_refresh($creator);
    expect(e37_unlocked($creator))->not->toBe([]);

    config(['reputation.enabled' => false]);

    $this->get(route('community.rankings'))->assertNotFound();
    $discover = $this->get(route('discover.index'))->assertOk()->getContent();

    expect(e37_rankings()->creators())->toBe([])->and(e37_rankings()->rising())->toBe([])->and(e37_rankings()->featured())->toBe([])
        ->and($discover)->not->toContain('مبدعون صاعدون')->not->toContain('مبدعون مختارون');

    $before = e37_score_columns($creator);
    DB::table('community_reputation_snapshots')->where('user_id', $creator->id)->update(['ratings_count' => 1]);

    $this->artisan('reputation:recalculate', ['--all' => true])->assertExitCode(0);

    expect((int) e37_snap($creator)->ratings_count)->toBe(1)->and(e37_score_columns($creator))->not->toEqual($before); // لم يُحسب شيء
    $page = $this->get(route('players.show', $creator->public_id))->assertOk()->getContent();

    expect($page)->not->toContain('data-community-recognition-list');
});
