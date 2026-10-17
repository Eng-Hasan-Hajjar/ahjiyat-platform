<?php

use App\Models\CreatorFollow;
use App\Models\CreatorProfile;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Engagement\CreatorFollowService;
use App\Services\Engagement\EngagementException;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e29_seed());

function e29_slug(\App\Models\User $creator): string
{
    return $creator->creatorProfile()->value('slug');
}

test('AA3-33: a verified user follows an approved creator', function () {
    $creator = e28_creator();
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)))->assertRedirect()->assertSessionHas('engagement_status');

    expect(CreatorFollow::query()->where('user_id', $user->id)->where('creator_profile_id', e29_profile($creator)->id)->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
});

test('AA3-34: a creator cannot follow themselves', function () {
    $creator = e28_creator();

    $this->actingAs($creator)->post(route('creators.follow', e29_slug($creator)))->assertSessionHas('error');

    expect(CreatorFollow::query()->count())->toBe(0)->and(e29_followers($creator))->toBe(0);
});

test('AA3-35: a pending creator cannot be followed (404, nothing stored)', function () {
    $applicant = e16User();
    $profile = app(CreatorApplicationService::class)->apply($applicant, 'مبدع بانتظار الاعتماد ويحب الألغاز الذهنية كثيرًا.');
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', $profile->slug))->assertNotFound();
    expect(CreatorFollow::query()->count())->toBe(0);
    expect(fn () => app(CreatorFollowService::class)->follow($user, $profile))->toThrow(EngagementException::class);
});

test('AA3-36: a suspended creator cannot be newly followed, existing follows are kept and hidden from the feed', function () {
    $creator = e28_creator();
    e29_work($creator);
    $follower = e16User();
    e29_follow($follower, $creator);

    $admin = e28_reviewer('administrator');
    app(CreatorApplicationService::class)->suspend(e29_profile($creator), $admin, 'تعليق تجريبي للاختبار فقط.');

    $newcomer = e16User();
    $this->actingAs($newcomer)->post(route('creators.follow', e29_slug($creator)))->assertNotFound();
    expect(CreatorFollow::query()->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
    $this->actingAs($follower)->get(route('following.index'))->assertOk()->assertSee('لا جديد الآن');
});

test('AA3-37: unfollow works and is idempotent', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);

    $this->actingAs($user)->delete(route('creators.unfollow', e29_slug($creator)))->assertRedirect();
    $this->actingAs($user)->delete(route('creators.unfollow', e29_slug($creator)))->assertRedirect();

    expect(CreatorFollow::query()->count())->toBe(0)->and(e29_followers($creator))->toBe(0);
});

test('AA3-38: a duplicate follow is safe', function () {
    $creator = e28_creator();
    $user = e16User();

    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)));
    $this->actingAs($user)->post(route('creators.follow', e29_slug($creator)))->assertRedirect();

    expect(CreatorFollow::query()->count())->toBe(1)->and(e29_followers($creator))->toBe(1);
    expect(fn () => (new CreatorFollow)->forceFill(['user_id' => $user->id, 'creator_profile_id' => e29_profile($creator)->id])->save())->toThrow(\Illuminate\Database\QueryException::class);
});

test('AA3-39: followers_count equals the follow rows through follow and unfollow', function () {
    $creator = e28_creator();
    [$a, $b, $c] = [e16User(), e16User(), e16User()];

    e29_follow($a, $creator);
    e29_follow($b, $creator);
    e29_follow($c, $creator);
    expect(e29_followers($creator))->toBe(3);

    app(CreatorFollowService::class)->unfollow($b, e29_profile($creator));
    expect(e29_followers($creator))->toBe(2)->and(CreatorFollow::query()->where('creator_profile_id', e29_profile($creator)->id)->count())->toBe(2);
});

test('AA3-40: follower identities are never public (only the aggregate count)', function () {
    $creator = e28_creator();
    $work = e29_work($creator);
    $follower = e16User(['name' => 'متابع سري للغاية']);
    e29_follow($follower, $creator);

    foreach ([$this->get(route('creators.show', e29_slug($creator))), $this->actingAs($creator)->get(route('creators.show', e29_slug($creator))), $this->get(route('community.puzzles.show', $work->public_id))] as $response) {
        $html = $response->assertOk()->getContent();
        expect($html)->not->toContain('متابع سري للغاية')->and($html)->not->toContain($follower->email);
    }

    $html = $this->get(route('creators.show', e29_slug($creator)))->getContent();
    expect($html)->toContain('1 متابع');
});

test('AA3-41: the following list is private to its owner', function () {
    $creator = e28_creator();
    e29_work($creator, ['title' => 'منشور من مبدع يتابعه أ']);
    $a = e16User();
    $b = e16User();
    e29_follow($a, $creator);

    $this->get(route('following.index'))->assertRedirect(route('login'));
    $this->actingAs($a)->get(route('following.index'))->assertOk()->assertSee('منشور من مبدع يتابعه أ');
    $this->actingAs($b)->get(route('following.index', ['user_id' => $a->id]))->assertOk()->assertDontSee('منشور من مبدع يتابعه أ')->assertSee('لا تتابع أي مبدع بعد');
});

test('AA3-42: a frozen user cannot follow or unfollow', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $user->forceFill(['is_frozen' => true])->save();

    $this->actingAs($user->fresh())->delete(route('creators.unfollow', e29_slug($creator)));
    $this->actingAs($user->fresh())->post(route('creators.follow', e29_slug(e28_creator())));

    expect(CreatorFollow::query()->count())->toBe(1);
    expect(fn () => app(CreatorFollowService::class)->unfollow($user->fresh(), e29_profile($creator)))->toThrow(EngagementException::class);
    expect(fn () => e29_follow($user->fresh(), e28_creator()))->toThrow(EngagementException::class);
});

// ───────────── AA4: الخلاصة ─────────────

function e29_publish_at(\App\Models\CreatorPuzzle $work, string $when): void
{
    \Illuminate\Support\Facades\DB::table('creator_puzzles')->where('id', $work->id)->update(['published_at' => $when]);
}

test('AA4-43: a followed creator\'s publication appears in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator, ['title' => 'عمل جديد للمتابَع']);

    $this->actingAs($user)->get(route('following.index'))->assertOk()->assertSee('عمل جديد للمتابَع')->assertSee('من المجتمع');
});

test('AA4-44: an unfollowed creator\'s publication does not appear', function () {
    $followed = e28_creator();
    $other = e28_creator();
    $user = e16User();
    e29_follow($user, $followed);
    e29_work($other, ['title' => 'عمل مبدع غير متابَع']);
    e29_work($followed, ['title' => 'عمل مبدع متابَع']);

    $this->actingAs($user)->get(route('following.index'))->assertOk()->assertSee('عمل مبدع متابَع')->assertDontSee('عمل مبدع غير متابَع');
});

test('AA4-45: a hidden work does not appear in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $work = e29_work($creator, ['title' => 'عمل مخفي من الخلاصة']);
    e29_hide($work);

    $html = $this->actingAs($user)->get(route('following.index'))->assertOk()->getContent();
    expect(e29_feed_section($html))->toBe('')->and($html)->toContain('لا جديد الآن');
});

test('AA4-46: a suspended creator\'s content does not appear in the feed', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator, ['title' => 'عمل مبدع معلَّق']);
    app(CreatorApplicationService::class)->suspend(e29_profile($creator), e28_reviewer('administrator'), 'تعليق تجريبي للاختبار فقط.');

    $html = $this->actingAs($user)->get(route('following.index'))->assertOk()->getContent();
    expect(e29_feed_section($html))->toBe('')->and($html)->toContain('لا جديد الآن');
});

test('AA4-47: the feed is newest published first', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $old = e29_work($creator, ['title' => 'الأقدم نشرًا']);
    $mid = e29_work($creator, ['title' => 'المتوسط نشرًا']);
    $new = e29_work($creator, ['title' => 'الأحدث نشرًا']);
    e29_publish_at($old, now()->subDays(3)->toDateTimeString());
    e29_publish_at($mid, now()->subDays(2)->toDateTimeString());
    e29_publish_at($new, now()->subDay()->toDateTimeString());

    $section = e29_feed_section($this->actingAs($user)->get(route('following.index'))->assertOk()->getContent());
    expect(strpos($section, 'الأحدث نشرًا'))->toBeLessThan(strpos($section, 'المتوسط نشرًا'))->and(strpos($section, 'المتوسط نشرًا'))->toBeLessThan(strpos($section, 'الأقدم نشرًا'));
});

test('AA4-48: cursor pagination is stable (no repeats or gaps) even when something new is published between pages', function () {
    config(['engagement.pagination.following' => 2]);
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    $works = [];

    foreach (range(1, 5) as $i) {
        $works[$i] = e29_work($creator, ['title' => "عنصر الخلاصة رقم {$i}"]);
        e29_publish_at($works[$i], now()->subDays(10 - $i)->toDateTimeString());   // رقم أكبر = أحدث
    }

    $page1 = $this->actingAs($user)->get(route('following.index'))->assertOk();
    $section1 = e29_feed_section($page1->getContent());
    expect($section1)->toContain('عنصر الخلاصة رقم 5')->toContain('عنصر الخلاصة رقم 4')->not->toContain('عنصر الخلاصة رقم 3');

    $late = e29_work($creator, ['title' => 'عنصر نُشر أثناء التصفح']);   // أحدث من كل شيء
    preg_match('/href="([^"]*cursor=[^"]+)"/', html_entity_decode($page1->getContent()), $m);
    expect($m)->not->toBeEmpty();

    $section2 = e29_feed_section($this->actingAs($user)->get($m[1])->assertOk()->getContent());
    expect($section2)->toContain('عنصر الخلاصة رقم 3')->toContain('عنصر الخلاصة رقم 2')
        ->not->toContain('عنصر الخلاصة رقم 5')->not->toContain('عنصر الخلاصة رقم 4')->not->toContain('عنصر نُشر أثناء التصفح');
});

test('AA4-49: the feed has no N+1 (same queries for 2 and 12 works)', function () {
    $creator = e28_creator();
    $user = e16User();
    e29_follow($user, $creator);
    e29_work($creator);
    e29_work($creator);

    $this->actingAs($user)->get(route('following.index'))->assertOk();   // تسخين: صفوف كسولة/كاش لمرة واحدة لا تُحسب
    $two = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    foreach (range(1, 10) as $i) { e29_work(e28_creator()); }
    foreach (CreatorProfile::query()->where('status', 'approved')->where('id', '>', e29_profile($creator)->id)->get() as $p) {
        app(CreatorFollowService::class)->follow($user, $p);
    }

    $twelve = e27_queries(fn () => $this->get(route('following.index'))->assertOk());

    expect($twelve)->toBe($two);
});
