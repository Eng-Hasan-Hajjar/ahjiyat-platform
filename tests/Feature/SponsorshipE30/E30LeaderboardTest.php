<?php

use App\Services\Sponsorship\SponsorshipProgramService;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e30_seed());

function e30_board(string $scope = 'all', $program = null): array
{
    Cache::flush();

    return app(SupporterLeaderboardService::class)->top($scope, $program);
}

function e30_names(array $board): array
{
    return array_map(fn ($e) => $e['anonymous'] ? '(مجهول)' : $e['supporter']->display_name, $board['entries']);
}

test('AM5-38: confirmed amounts rank supporters (highest first)', function () {
    $program = e30_program();
    foreach (['ج' => '10', 'أ' => '30', 'ب' => '20'] as $name => $amount) {
        e30_confirmed(e30_supporter(['display_name' => "داعم $name"]), $program, $amount);
    }

    expect(e30_names(e30_board()))->toBe(['داعم أ', 'داعم ب', 'داعم ج']);
    $this->get(route('supporters.index'))->assertOk()->assertSeeInOrder(['داعم أ', 'داعم ب', 'داعم ج']);
});

test('AM5-39: pending and cancelled contributions are excluded from the board', function () {
    $program = e30_program();
    e30_pending(e30_supporter(['display_name' => 'داعم معلّق']), $program, '999');
    app(\App\Services\Sponsorship\ContributionService::class)->cancel(e30_admin(), e30_pending(e30_supporter(['display_name' => 'داعم ملغى']), $program, '999'), 'لم يصل الدفع');
    e30_confirmed(e30_supporter(['display_name' => 'داعم مؤكد']), $program, '5');

    expect(e30_names(e30_board()))->toBe(['داعم مؤكد']);
});

test('AM5-40: refunded amounts reduce the net ranking', function () {
    $program = e30_program();
    $a = e30_supporter(['display_name' => 'داعم الاسترداد']);
    $b = e30_supporter(['display_name' => 'داعم ثابت']);
    e30_confirmed($b, $program, '50');
    e30_confirmed($a, $program, '20');
    e30_refund(e30_confirmed($a, $program, '80'));
    e30_confirmed($a, $program, '5');

    expect(e30_names(e30_board()))->toBe(['داعم ثابت', 'داعم الاسترداد']);

    e30_confirmed($a, $program, '100');
    expect(e30_names(e30_board()))->toBe(['داعم الاسترداد', 'داعم ثابت']);
});

test('AM5-41: anonymous support is a separate nameless row and never exposes the identity', function () {
    $program = e30_program();
    $s = e30_supporter(['display_name' => 'صاحب الدعم الخفي']);
    e30_confirmed($s, $program, '200', ['is_anonymous' => true]);
    e30_confirmed($s, $program, '10');

    $board = e30_board();
    expect(e30_names($board))->toBe(['(مجهول)', 'صاحب الدعم الخفي']);
    expect($board['entries'][0]['supporter'])->toBeNull()->and($board['entries'][0]['tier'])->toBeNull();
});

test('AM5-42: a private or suspended supporter is excluded from every list', function () {
    $program = e30_program();
    e30_confirmed(e30_supporter(['display_name' => 'داعم غير علني', 'is_public' => false]), $program, '900');
    e30_confirmed(e30_company(['display_name' => 'مؤسسة غير علنية', 'is_public' => false]), $program, '900');
    e30_confirmed(e30_supporter(['display_name' => 'داعم علني']), $program, '1');

    expect(e30_names(e30_board()))->toBe(['داعم علني']);
    expect(app(SupporterLeaderboardService::class)->organizations())->toBe([]);
    expect(array_map(fn ($r) => $r['supporter']?->display_name, app(SupporterLeaderboardService::class)->recent()))->toBe(['داعم علني']);
});

test('AM5-43: ties are deterministic (earlier first confirmation, then id) and stable across rebuilds', function () {
    $program = e30_program();
    $first = e30_supporter(['display_name' => 'الأقدم']);
    $second = e30_supporter(['display_name' => 'الأحدث']);
    $c2 = e30_confirmed($second, $program, '40');
    $c1 = e30_confirmed($first, $program, '40');
    DB::table('sponsorship_contributions')->where('id', $c1->id)->update(['confirmed_at' => now()->subDays(3)]);
    DB::table('sponsorship_contributions')->where('id', $c2->id)->update(['confirmed_at' => now()->subDay()]);

    foreach (range(1, 3) as $_) {
        expect(e30_names(e30_board()))->toBe(['الأقدم', 'الأحدث']);
    }
});

test('AM5-44: the all-time board works and the year board only counts this year', function () {
    $program = e30_program();
    $old = e30_supporter(['display_name' => 'داعم قديم']);
    $new = e30_supporter(['display_name' => 'داعم هذا العام']);
    $c = e30_confirmed($old, $program, '500');
    DB::table('sponsorship_contributions')->where('id', $c->id)->update(['confirmed_at' => now()->subYears(2)]);
    e30_confirmed($new, $program, '10');

    expect(e30_names(e30_board('all')))->toBe(['داعم قديم', 'داعم هذا العام'])->and(e30_names(e30_board('year')))->toBe(['داعم هذا العام']);
});

test('AM5-45: the program board ranks only that program and shows amounts only when enabled', function () {
    $one = e30_program(['title' => 'برنامج واحد']);
    $two = e30_program(['title' => 'برنامج اثنان']);
    $a = e30_supporter(['display_name' => 'داعم أ']);
    $b = e30_supporter(['display_name' => 'داعم ب']);
    e30_confirmed($a, $one, '10');
    e30_confirmed($b, $one, '30');
    e30_confirmed($a, $two, '900');

    $board = e30_board('program', $one);
    expect(e30_names($board))->toBe(['داعم ب', 'داعم أ'])->and($board['show_amounts'])->toBeFalse()->and($board['entries'][0]['total_minor'])->toBeNull();

    app(SponsorshipProgramService::class)->update(e30_admin(), $one->fresh(), ['show_public_amounts' => true]);
    $board = e30_board('program', $one->fresh());
    expect($board['show_amounts'])->toBeTrue()->and($board['entries'][0]['total_minor'])->toBe(3000);
});

test('AM5-46: the season board ranks supporters of programs targeting the current season', function () {
    $season = e30_season();
    $program = e30_program(['target_type' => 'season', 'target_id' => $season->id]);
    $platform = e30_program();
    e30_confirmed(e30_supporter(['display_name' => 'داعم الموسم']), $program, '15');
    e30_confirmed(e30_supporter(['display_name' => 'داعم المنصة فقط']), $platform, '900');

    expect(e30_names(e30_board('season')))->toBe(['داعم الموسم']);
});

test('AM5-47: lists are bounded (no unbounded pagination) and the page size is configurable', function () {
    $program = e30_program();
    foreach (range(1, 25) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (100 - $i));
    }

    expect(count(e30_board()['entries']))->toBe(20);
    config(['sponsorship.public.top_limit' => 7]);
    expect(count(e30_board()['entries']))->toBe(7);
    $this->get(route('supporters.index', ['scope' => 'all', 'page' => 99, 'limit' => 5000]))->assertOk();
});

test('AM5-48: the board costs the same number of queries for 5 and for 20 supporters (no N+1)', function () {
    $program = e30_program();
    foreach (range(1, 5) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (10 + $i));
    }
    Cache::flush();
    $few = e27_queries(fn () => app(SupporterLeaderboardService::class)->top());

    foreach (range(1, 15) as $i) {
        e30_confirmed(e30_supporter(), $program, (string) (100 + $i));
    }
    Cache::flush();
    $many = e27_queries(fn () => app(SupporterLeaderboardService::class)->top());

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(6);
});

test('AM5-49: ranking happens in the database (GROUP BY + ORDER BY + LIMIT), never by loading every row into PHP', function () {
    $program = e30_program();
    e30_confirmed(e30_supporter(), $program, '10');
    Cache::flush();

    $sql = collect(e27_sql(fn () => app(SupporterLeaderboardService::class)->top()))->first(fn ($q) => str_contains($q, 'sponsorship_contributions'));

    expect(strtolower($sql))->toContain('group by')->toContain('order by')->toContain('limit')->toContain('sum(');
});
