<?php

use App\Exceptions\StoreItemInvariantViolation;
use App\Models\Achievement;
use App\Models\CommunityFeaturedCreator;
use App\Models\User;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Progression\AchievementService;
use App\Services\Reputation\FeaturedCreatorService;
use App\Services\Reputation\ReputationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

/*
| E37 AV7 (48-55) الاعتراف عبر نظام الإنجازات + AV8 (56-61) الملفات + AV9 (62-66) المبدع المختار.
| الاعتراف يعيد استخدام الإنجازات القائمة بلا مكافآت أبدًا؛ المبدع المختار قرار تحريري مُدقَّق لا يُشترى ولا يُحسب.
*/

beforeEach(function () {
    e37_seed();
    e37_recognition_seed();
});

/* ===================== AV7. RECOGNITION ===================== */

test('E37-048: a creator milestone unlocks recognition (first publication; established tier at quality >= 4500)', function () {
    config(['reputation.creator.rating.prior_weight' => 0]); // مبدع بكل التقييمات 5 ← مكوّن الجودة كاملًا
    [$creator] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    expect(e37_unlocked($creator))->toBe([]);

    e37_refresh($creator);

    expect((int) e37_snap($creator)->creator_score)->toBeGreaterThanOrEqual(4500)
        ->and(e37_unlocked($creator))->toContain('community_creator_first_publication')->toContain('community_creator_established')
        ->and(e37_unlocked($creator))->not->toContain('community_creator_distinguished');
});

test('E37-049: curator milestones unlock (first public collection; saved by 10 distinct players)', function () {
    [$owner] = e37_curator(10, 2);
    e37_refresh($owner);

    expect(e37_unlocked($owner))->toContain('community_curator_first_collection')->toContain('community_curator_saved')->not->toContain('community_curator_distinguished');
});

test('E37-050: a helpful-voice milestone unlocks at 10 distinct likers on live comments', function () {
    [$author] = e37_helper(5, 10);
    e37_refresh($author);

    expect(e37_unlocked($author))->toBe(['community_helpful_voice']);

    [$almost] = e37_helper(5, 9);
    e37_refresh($almost);

    expect(e37_unlocked($almost))->toBe([]);
});

test('E37-051: recognition is idempotent - recalculating never re-unlocks, re-notifies or moves the unlock time', function () {
    [$author] = e37_helper(5, 10);
    e37_refresh($author);
    $unlockedAt = DB::table('user_achievement_progress')->where('user_id', $author->id)->whereNotNull('unlocked_at')->value('unlocked_at');
    $notes = e37_notes($author, 'achievement_unlocked');
    $rows = DB::table('user_achievement_progress')->where('user_id', $author->id)->count();

    expect($notes)->toBe(1);

    e37_refresh($author);
    e37_refresh($author);
    $this->artisan('reputation:recalculate', ['--user' => $author->public_id])->assertExitCode(0);

    expect(e37_notes($author, 'achievement_unlocked'))->toBe(1)->and(DB::table('user_achievement_progress')->where('user_id', $author->id)->count())->toBe($rows)
        ->and(DB::table('user_achievement_progress')->where('user_id', $author->id)->whereNotNull('unlocked_at')->value('unlocked_at'))->toBe($unlockedAt);
});

test('E37-052: recognition grants zero XP, currency, items or wallet value - and the guard forbids any reward on these types', function () {
    [$author, , $fans] = e37_helper(5, 10);
    [$owner] = e37_curator(10, 2);
    $before = e37_world($author, $owner);
    $xpBefore = DB::table('xp_transactions')->count();

    e37_refresh($author, $owner);
    $after = e37_world($author, $owner);

    expect(e37_unlocked($author))->not->toBe([])->and(e37_unlocked($owner))->not->toBe([])
        ->and($after['counts'])->toEqual($before['counts'])->and($after['wallets'])->toBe($before['wallets'])->and($after['xp'])->toBe($before['xp'])->and($after['per_user'])->toEqual($before['per_user'])
        ->and(DB::table('xp_transactions')->count())->toBe($xpBefore);

    expect(Achievement::query()->whereIn('internal_key', array_keys(config('reputation.recognition')))->get()->every(fn ($a) => (int) $a->xp_reward === 0 && $a->reward_currency_id === null && $a->reward_store_item_id === null))->toBeTrue();

    $attempt = fn (array $reward) => (new Achievement)->forceFill([
        'internal_key' => 'e37_bad_'.random_int(1, 999999), 'name' => 'x', 'description' => 'x', 'category' => 'community',
        'condition_type' => 'public_collections_total', 'target_value' => 1, 'is_active' => true,
    ] + $reward)->save();

    expect(fn () => $attempt(['xp_reward' => 50]))->toThrow(StoreItemInvariantViolation::class)
        ->and(fn () => $attempt(['reward_currency_amount' => 5]))->toThrow(StoreItemInvariantViolation::class);
});

test('E37-053: a missed recognition event self-heals on the next recalculation', function () {
    [$author] = e37_helper(5, 10);
    app()->bind(AchievementService::class, fn () => throw new RuntimeException('achievements down'));
    e37_refresh($author);

    expect((bool) e37_snap($author)->helpful_eligible)->toBeTrue()->and(e37_unlocked($author))->toBe([]); // اللقطة كُتبت، والاعتراف فاته الحدث

    app()->offsetUnset(AchievementService::class);
    app()->forgetInstance(AchievementService::class);
    e37_refresh($author);

    expect(e37_unlocked($author))->toBe(['community_helpful_voice']);

    // حتى لو حُذف صف التقدّم لسبب ما فالحساب التالي يعيد بناءه من اللقطة الحيّة.
    DB::table('user_achievement_progress')->where('user_id', $author->id)->delete();
    e37_refresh($author);

    expect(e37_unlocked($author))->toBe(['community_helpful_voice']);
});

test('E37-054: recognition is monotonic - a later drop in score or likes never revokes what was earned', function () {
    config(['reputation.creator.rating.prior_weight' => 0]);
    [$creator, $works] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    e37_refresh($creator);
    expect(e37_unlocked($creator))->toContain('community_creator_established');

    e29_hide($works[0]);
    e29_hide($works[1]);
    e37_refresh($creator);

    expect((int) e37_snap($creator)->creator_score)->toBe(0)->and(e37_unlocked($creator))->toContain('community_creator_established')->toContain('community_creator_first_publication');
});

test('E37-055: only achievement-unlocked notifications are sent - no rank-change spam from rank movement', function () {
    [$a, $worksA] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    [$b] = e37_eligible_creator([4, 4, 4, 4, 4, 4]);
    e37_refresh($a, $b);
    $before = e37_names(e37_rankings()->creators());
    $baseline = DB::table('notifications')->pluck('id')->all();

    expect($before)->toBe([$a->name, $b->name]);

    DB::table('community_puzzle_ratings')->whereIn('creator_puzzle_id', collect($worksA)->pluck('id'))->update(['rating' => 2]); // الترتيب ينقلب
    e37_refresh($a, $b);
    e37_refresh($a, $b);

    $types = DB::table('notifications')->whereNotIn('id', $baseline)->pluck('type_key')->unique()->values()->all();

    expect(e37_names(e37_rankings()->creators()))->toBe([$b->name, $a->name])
        ->and(array_diff($types, ['achievement_unlocked']))->toBe([]) // كل ما استُحدث بعد حركة الترتيب هو إعلان إنجاز فقط
        ->and(collect($types)->contains(fn ($t) => str_contains((string) $t, 'rank')))->toBeFalse();
});

/* ===================== AV8. PROFILES ===================== */

test('E37-056: the public creator page shows earned recognition (and nothing raw: no scores, ranks, counters or snapshot fields)', function () {
    config(['reputation.creator.rating.prior_weight' => 0]);
    [$creator] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);
    e37_refresh($creator);

    $html = $this->get(route('creators.show', e37_profile($creator)->slug))->assertOk()->getContent();

    expect($html)->toContain('data-community-recognition')->toContain('أول منشور')->toContain('مبدع راسخ')
        ->and($html)->not->toContain('creator_score')->not->toContain('formula_version')->not->toContain('raters_count')->not->toContain('calculated_at')
        ->and($html)->not->toContain('data-featured-creator');
});

test('E37-057: a creator with no earned recognition shows no empty recognition section', function () {
    [$creator] = e37_creator(1);

    $html = $this->get(route('creators.show', e37_profile($creator)->slug))->assertOk()->getContent();

    expect($html)->not->toContain('data-community-recognition')->not->toContain('data-featured-creator');
});

test('E37-058: the player profile shows earned recognition for a public profile', function () {
    [$author] = e37_helper(5, 10);
    e37_refresh($author);

    $html = $this->get(route('players.show', $author->public_id))->assertOk()->getContent();

    expect($html)->toContain('data-community-recognition-list')->toContain('صوت مفيد');
});

test('E37-059: a suspended creator hides creator recognition on the player profile but keeps curator/helpful; restore brings it back', function () {
    config(['reputation.creator.rating.prior_weight' => 0]);
    [$creator] = e37_eligible_creator([5, 5, 5, 5, 5, 5]);

    foreach (e37_users(10) as $u) { // نفس المستخدم منسّق أيضًا
        $col = $col ?? e35_collection($creator, e35_works(3), 'public', 'مجموعتي');
        DB::table('user_collection_saves')->insert(['user_id' => $u->id, 'user_collection_id' => $col->id, 'created_at' => now()]);
    }

    e37_refresh($creator);
    expect(e37_unlocked($creator))->toContain('community_creator_established')->toContain('community_curator_saved');
    $page = fn () => $this->get(route('players.show', $creator->public_id))->assertOk()->getContent();

    expect($page())->toContain('مبدع راسخ')->toContain('مجموعات يحفظها الناس');

    $admin = e37_admin();
    app(CreatorApplicationService::class)->suspend(e37_profile($creator), $admin, 'سبب إداري للاختبار');
    $suspended = $page();

    expect($suspended)->not->toContain('مبدع راسخ')->not->toContain('أول منشور')->toContain('مجموعات يحفظها الناس');

    app(CreatorApplicationService::class)->restore(e37_profile($creator), $admin);

    expect($page())->toContain('مبدع راسخ');
});

test('E37-060: a private profile never advertises recognition to strangers', function () {
    [$author] = e37_helper(5, 10);
    e37_refresh($author);
    expect(e37_unlocked($author))->toBe(['community_helpful_voice']);

    $author->forceFill(['profile_visibility' => User::VISIBILITY_PRIVATE])->save();
    $response = $this->get(route('players.show', $author->public_id));

    expect(str_contains($response->getContent(), 'data-community-recognition-list'))->toBeFalse()->and(str_contains($response->getContent(), 'صوت مفيد'))->toBeFalse()
        ->and(e37_names(e37_rankings()->helpful()))->toBe([]);
});

test('E37-061: an unverified or frozen account is never ranked nor shown recognition on the public boards', function () {
    [$author] = e37_helper(5, 10);
    e37_refresh($author);
    expect(e37_names(e37_rankings()->helpful()))->toBe([$author->name]);

    $author->forceFill(['is_frozen' => true])->save();
    expect(e37_names(e37_rankings()->helpful()))->toBe([]);

    $author->forceFill(['is_frozen' => false, 'email_verified_at' => null])->save();
    expect(e37_names(e37_rankings()->helpful()))->toBe([]);

    $html = $this->get(route('community.rankings'))->assertOk()->getContent();

    expect($html)->not->toContain($author->name);
});

/* ===================== AV9. FEATURED CREATOR ===================== */

test('E37-062: an authorized editor can feature a creator; the public page shows the badge and the featured list includes them', function () {
    [$creator] = e37_creator(2);
    $profile = e37_profile($creator);
    $row = app(FeaturedCreatorService::class)->feature($profile, e37_manager(), 'اختيار فريق المحتوى');

    expect($row->featured_by)->not->toBeNull()->and(e37_rankings()->isFeatured($profile->id))->toBeTrue()->and(array_column(e37_rankings()->featured(), 'slug'))->toBe([$profile->slug]);

    $html = $this->get(route('creators.show', $profile->slug))->assertOk()->getContent();

    expect($html)->toContain('data-featured-creator')->toContain('مبدع مختار')->not->toContain('اختيار فريق المحتوى'); // الملاحظة داخلية
});

test('E37-063: an ordinary user, player or moderator cannot feature a creator (403) and nothing is written', function () {
    [$creator] = e37_creator(2);
    $profile = e37_profile($creator);
    $svc = app(FeaturedCreatorService::class);

    foreach ([e16User(), e28_reviewer('moderator'), $creator] as $actor) {
        expect(fn () => $svc->feature($profile, $actor))->toThrow(HttpException::class);
    }

    expect(CommunityFeaturedCreator::query()->count())->toBe(0)->and(e36_audit('creator_featured'))->toBeNull();

    $featured = $svc->feature($profile, e37_admin());

    expect(fn () => $svc->unfeature($profile, e16User()))->toThrow(HttpException::class)->and(e37_rankings()->isFeatured($profile->id))->toBeTrue();
});

test('E37-064: featuring is audited (feature / update / unfeature) and notifies the creator once per selection', function () {
    [$creator] = e37_creator(2);
    $profile = e37_profile($creator);
    $admin = e37_admin();
    $svc = app(FeaturedCreatorService::class);

    $svc->feature($profile, $admin, 'ملاحظة أولى');
    $svc->feature($profile, $admin, 'ملاحظة معدّلة'); // تحديث لاختيار فعّال: لا إشعار جديد

    expect(e36_audit('creator_featured')->actor_user_id)->toBe($admin->id)->and(e36_audit('creator_feature_updated'))->not->toBeNull()
        ->and(e37_notes($creator, 'featured_creator_selected'))->toBe(1);

    $svc->unfeature($profile, $admin);

    expect(e36_audit('creator_unfeatured'))->not->toBeNull()->and(e37_rankings()->isFeatured($profile->id))->toBeFalse();

    \Carbon\Carbon::setTestNow(now()->addDays(2));
    $svc->feature($profile, $admin); // اختيار جديد بعد الإنهاء (في وقت لاحق) ← إشعار جديد
    \Carbon\Carbon::setTestNow();

    expect(e37_notes($creator, 'featured_creator_selected'))->toBe(2);
});

test('E37-065: sponsorship, payments, gems, referrals and ratings never auto-feature - the featured table is only written by the editorial service', function () {
    [$creator, $works] = e37_eligible_creator([5, 5, 5, 5, 5, 5, 5, 5]);

    foreach (e37_users(6) as $u) {
        e37_follow($creator, $u);
        e37_fav($works[0], $u);
    }

    e37_refresh($creator);
    $this->artisan('reputation:recalculate', ['--all' => true])->assertExitCode(0);
    $this->artisan('reputation:reconcile', ['--fix' => true])->assertExitCode(0);

    expect(CommunityFeaturedCreator::query()->count())->toBe(0)->and(array_column(e37_rankings()->featured(), 'slug'))->toBe([]);

    // مصدرًا: لا خدمة سمعة تقرأ مالًا/رعاية/دفعًا/محفظة/متجرًا/إحالة (لا يمكن شراء الاختيار أو الدرجة).
    $source = collect(glob(base_path('app/Services/Reputation/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");
    $words = ['sponsor_', 'payment_', 'wallet', 'gem_transactions', 'currency_transactions', 'store_items', 'user_referrals', 'referral_codes', 'redemption', 'supporter'];

    foreach ($words as $word) {
        expect(str_contains(strtolower($source), $word))->toBeFalse("services read forbidden source: {$word}");
    }
});

test('E37-066: featuring guards - eligibility, window sanity, note limits, active cap, and live removal on suspension', function () {
    $admin = e37_admin();
    $svc = app(FeaturedCreatorService::class);
    [$noWorks] = e37_creator(0);
    [$ok] = e37_creator(1);
    $okProfile = e37_profile($ok);

    expect(fn () => $svc->feature(e37_profile($noWorks), $admin))->toThrow(ReputationException::class) // لا أعمال ظاهرة
        ->and(fn () => $svc->feature($okProfile, $admin, str_repeat('ك', 161)))->toThrow(ReputationException::class)
        ->and(fn () => $svc->feature($okProfile, $admin, null, now()->addDay(), now()))->toThrow(ReputationException::class);

    $row = $svc->feature($okProfile, $admin, '<b>ملاحظة</b> <script>x</script>');

    expect($row->note)->not->toContain('<');

    // سقف الفعّالين.
    config(['reputation.featured.max_active' => 1]);
    [$other] = e37_creator(1);

    expect(fn () => $svc->feature(e37_profile($other), $admin))->toThrow(ReputationException::class);

    // تعليق المبدع يزيله من العرض فورًا دون إلغاء الاختيار، والاستعادة تعيده.
    app(CreatorApplicationService::class)->suspend($okProfile, $admin, 'سبب إداري للاختبار');

    expect(e37_rankings()->isFeatured($okProfile->id))->toBeTrue()->and(array_column(e37_rankings()->featured(), 'slug'))->toBe([]);

    app(CreatorApplicationService::class)->restore($okProfile->fresh(), $admin);

    expect(array_column(e37_rankings()->featured(), 'slug'))->toBe([$okProfile->slug]);
});
