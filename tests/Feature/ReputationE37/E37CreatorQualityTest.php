<?php

use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorPuzzleService;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\ReputationMath;
use App\Services\Reputation\ReputationMetricsCollector;

require_once __DIR__.'/_support.php';

/*
| E37 AV1 (1-14) - جودة المبدع. أهلية المبدع (معتمَد/غير مجمَّد/أعمال ظاهرة)، تجميع التقييمات، الانكماش البايزي، حدّ العيّنة، وسقوف الإشارات الشعبية.
| الاختبارات الرياضية نقيّة (بلا قاعدة بيانات) وتستدعي الخدمة نفسها فتثبت الصيغة الفعلية لا نسخة منها.
*/

beforeEach(fn () => e37_seed());

test('E37-001: an approved creator with enough visible works and ratings is eligible and scored', function () {
    [$creator] = e37_eligible_creator();
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect($s)->not->toBeNull()
        ->and((bool) $s->creator_eligible)->toBeTrue()
        ->and((int) $s->works_count)->toBe(2)->and((int) $s->ratings_count)->toBe(12)->and((int) $s->raters_count)->toBe(6)
        ->and((int) $s->creator_score)->toBeGreaterThan(0)->and((int) $s->creator_score)->toBeLessThanOrEqual(10000);
});

test('E37-002: a suspended creator is excluded (no visible works, zero score, not eligible); restore brings them back', function () {
    [$creator] = e37_eligible_creator();
    $svc = app(CreatorApplicationService::class);
    $admin = e37_admin();
    $svc->suspend(e37_profile($creator), $admin, 'سبب إداري للاختبار');
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((bool) $s->creator_eligible)->toBeFalse()->and((int) $s->creator_score)->toBe(0)->and((int) $s->works_count)->toBe(0)->and((int) $s->ratings_count)->toBe(0);

    $svc->restore(e37_profile($creator), $admin);
    e37_refresh($creator);

    expect((bool) e37_snap($creator)->creator_eligible)->toBeTrue()->and((int) e37_snap($creator)->ratings_count)->toBe(12);
});

test('E37-003: a frozen account is excluded from creator reputation', function () {
    [$creator] = e37_eligible_creator();
    $creator->forceFill(['is_frozen' => true])->save();
    e29_reconcile_visibility($creator);
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((bool) $s->creator_eligible)->toBeFalse()->and((int) $s->creator_score)->toBe(0)->and((int) $s->creator_reach_score)->toBe(0);
});

test('E37-004: an approved creator without a visible work is not eligible and scores zero', function () {
    $creator = e28_creator();
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((bool) $s->creator_eligible)->toBeFalse()->and((int) $s->works_count)->toBe(0)->and((int) $s->creator_score)->toBe(0);
});

test('E37-005: a moderation-hidden work contributes nothing (its ratings drop out) and restoring it returns them', function () {
    [$creator, $works] = e37_eligible_creator();
    e29_hide($works[0]);
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((int) $s->works_count)->toBe(1)->and((int) $s->ratings_count)->toBe(6)->and((bool) $s->creator_eligible)->toBeFalse();

    e29_unhide($works[0]);
    e37_refresh($creator);

    expect((int) e37_snap($creator)->works_count)->toBe(2)->and((int) e37_snap($creator)->ratings_count)->toBe(12)->and((bool) e37_snap($creator)->creator_eligible)->toBeTrue();
});

test('E37-006: an archived work contributes nothing', function () {
    [$creator, $works] = e37_eligible_creator();
    app(CreatorPuzzleService::class)->archive($creator, (string) $works[1]->public_id);
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((int) $s->works_count)->toBe(1)->and((int) $s->ratings_count)->toBe(6);
});

test('E37-007: ratings aggregate exactly from the rows - counts, sums, distinct raters; the creator\'s own rating never counts', function () {
    [$creator, $works] = e37_creator(2);
    [$a, $b, $c] = e37_users(3);
    e37_rate($works[0], $a, 5);
    e37_rate($works[0], $b, 4);
    e37_rate($works[1], $a, 3);
    e37_rate($works[1], $c, 2);
    e37_rate($works[0], $creator, 5); // ذاتي: يُستبعد
    e37_refresh($creator);
    $s = e37_snap($creator);

    expect((int) $s->ratings_count)->toBe(4)->and((int) $s->ratings_sum)->toBe(14)->and((int) $s->raters_count)->toBe(3)->and($s->averageRating())->toBe(3.5);
});

test('E37-008: the Bayesian prior is applied - fewer ratings pull the score toward the neutral mean', function () {
    $few = e37_scores(['ratings_count' => 3, 'ratings_sum' => 15]);
    $many = e37_scores(['ratings_count' => 12, 'ratings_sum' => 60]);
    $manyScore = ReputationMath::toScale(ReputationMath::ratingComponent(12, 60, 3.5, 10.0, 3.5));
    $fewScore = ReputationMath::toScale(ReputationMath::ratingComponent(3, 15, 3.5, 10.0, 3.5));

    expect(ReputationMath::shrunk(2, 10, 3.5, 10.0))->toEqualWithDelta(3.75, 0.0001)
        ->and($many['creator_rating_score'])->toBe($manyScore)->and($few['creator_rating_score'])->toBe($fewScore)
        ->and($many['creator_rating_score'])->toBeGreaterThan($few['creator_rating_score']);

    // لا تقييمات = لا رصيد جودة (لا رصيد ابتدائي لمن لا دليل عنده).
    expect(e37_scores()['creator_rating_score'])->toBe(0);
});

test('E37-009: one 5-star rating cannot dominate a long record of good ratings', function () {
    $single = e37_scores(['ratings_count' => 1, 'ratings_sum' => 5, 'raters' => 1]);
    $record = e37_scores(['ratings_count' => 50, 'ratings_sum' => 210, 'raters' => 30]);

    expect($single['creator_rating_score'])->toBeLessThan(1000)->and($record['creator_rating_score'])->toBeGreaterThan($single['creator_rating_score'] * 3);
});

test('E37-010: minimum sample enforced - too few ratings or too few distinct raters are not eligible however high the average', function () {
    expect(e37_scores(['ratings_count' => 7, 'ratings_sum' => 35, 'raters' => 6])['creator_eligible'])->toBeFalse()
        ->and(e37_scores(['ratings_count' => 10, 'ratings_sum' => 50, 'raters' => 4])['creator_eligible'])->toBeFalse()
        ->and(e37_scores(['ratings_count' => 8, 'ratings_sum' => 40, 'raters' => 5, 'works' => 1])['creator_eligible'])->toBeFalse()
        ->and(e37_scores(['ratings_count' => 8, 'ratings_sum' => 40, 'raters' => 5, 'works' => 2])['creator_eligible'])->toBeTrue();

    // بالقاعدة: مقيِّم واحد يقيّم 12 عملًا = مقيِّم مميَّز واحد (لا أهلية).
    [$creator, $works] = e37_creator(2);
    $rater = e37_users(1)[0];
    $extra = e37_users(5);

    foreach ($works as $w) {
        e37_rate($w, $rater, 5);
    }

    foreach (array_slice($extra, 0, 2) as $u) {
        foreach ($works as $w) {
            e37_rate($w, $u, 5);
        }
    }

    e37_refresh($creator);

    expect((int) e37_snap($creator)->ratings_count)->toBe(6)->and((int) e37_snap($creator)->raters_count)->toBe(3)->and((bool) e37_snap($creator)->creator_eligible)->toBeFalse();
});

test('E37-011: the follower signal is capped - 500 and 500000 followers give the same reach, and followers alone never make a creator eligible', function () {
    $a = e37_scores(['followers' => 500]);
    $b = e37_scores(['followers' => 500000]);

    expect($a['creator_reach_score'])->toBe(10000)->and($b['creator_reach_score'])->toBe(10000)->and($a['creator_score'])->toBe($b['creator_score'])
        ->and(e37_scores(['followers' => 500000])['creator_eligible'])->toBeFalse();
});

test('E37-012: raw plays do not dominate - a huge audience with poor ratings loses to a small audience with strong ratings', function () {
    $crowd = e37_scores(['players' => 1000000, 'ratings_count' => 12, 'ratings_sum' => 36, 'raters' => 8]);       // متوسط 3.0
    $quality = e37_scores(['players' => 20, 'ratings_count' => 12, 'ratings_sum' => 58, 'raters' => 8]);          // متوسط 4.8
    $capA = e37_scores(['players' => 2000, 'ratings_count' => 12, 'ratings_sum' => 58, 'raters' => 8]);
    $capB = e37_scores(['players' => 5000000, 'ratings_count' => 12, 'ratings_sum' => 58, 'raters' => 8]);

    expect($crowd['creator_score'])->toBeLessThan($quality['creator_score'])->and($crowd['creator_rating_score'])->toBe(0)
        ->and($capA['creator_engagement_score'])->toBe($capB['creator_engagement_score']);
});

test('E37-013: favorites are a bounded signal - the cap stops growth', function () {
    $a = e37_scores(['favorites' => 200, 'ratings_count' => 10, 'ratings_sum' => 45, 'raters' => 6]);
    $b = e37_scores(['favorites' => 9000, 'ratings_count' => 10, 'ratings_sum' => 45, 'raters' => 6]);
    $none = e37_scores(['favorites' => 0, 'ratings_count' => 10, 'ratings_sum' => 45, 'raters' => 6]);

    expect($a['creator_engagement_score'])->toBe($b['creator_engagement_score'])->and($a['creator_engagement_score'])->toBeGreaterThan($none['creator_engagement_score']);
});

test('E37-014: quantity spam is capped - publishing 8 or 800 rated works, or 3 or 30 series, scores the same consistency', function () {
    $base = ['ratings_count' => 24, 'ratings_sum' => 110, 'raters' => 9, 'first_published_ts' => now()->subDays(400)->getTimestamp(), 'last_published_ts' => now()->getTimestamp()];
    $eight = e37_scores($base + ['works' => 8, 'rated_works' => 8, 'series' => 3]);
    $many = e37_scores($base + ['works' => 800, 'rated_works' => 800, 'series' => 30]);

    expect($eight['creator_consistency_score'])->toBe($many['creator_consistency_score'])->and($eight['creator_score'])->toBe($many['creator_score']);
});
