<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use Carbon\Carbon;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});
afterEach(fn () => Carbon::setTestNow());

test('61/E1/E2: challenge wins, losses and draws are derived from completed matches only - pending and running ones never count', function () {
    [$a, $b, $c, $d] = [e19Team(), e19Team(), e19Team(), e19Team()];
    e20Match($a, $b, 5_000, 40_000);                                      // فوز لـa
    e20Match($a, $c, 40_000, 5_000);                                      // خسارة
    e20Match($a, $d, 15_000, 15_000);                                     // تعادل
    e20Create($a, e19Team(), [$a->owner]);                                // معلّق: لا يُحتسب
    $e = e19Team();
    e20Accepted($a, $e, [$a->owner], [$e->owner]);                        // جارٍ: لا يُحتسب

    expect(e20Glory()->stats($a)['challenges'])->toBe(['played' => 3, 'won' => 1, 'lost' => 1, 'drawn' => 1])
        ->and(e20Glory()->stats($b)['challenges'])->toBe(['played' => 1, 'won' => 0, 'lost' => 1, 'drawn' => 0])
        ->and(e20Glory()->stats($c)['challenges'])->toBe(['played' => 1, 'won' => 1, 'lost' => 0, 'drawn' => 0])
        ->and(e20Glory()->stats($d)['challenges'])->toBe(['played' => 1, 'won' => 0, 'lost' => 0, 'drawn' => 1])
        ->and(e20Glory()->stats($e)['challenges']['played'])->toBe(0);
});

test('62/63: championship titles, finishes in the top three and participations are derived from the stored final standings - and an unfinished championship counts for nothing', function () {
    [$x, $y, $z] = [e19Team(), e19Team(), e19Team()];
    e20Completed([[$x, 1900], [$y, 1800], [$z, 1700]], 'البطولة الأولى');     // x بطل
    e20Completed([[$y, 1900], [$z, 1800], [$x, 1700]], 'البطولة الثانية');     // x ثالث
    e20Completed([[$z, 1900], [$y, 1800]], 'البطولة الثالثة');                  // x غائب
    $admin = e20Admin([], 'administrator');
    $draft = e20Championship($admin, ['ends_at' => now()->addDay()]);
    e20Champs()->linkEvent($admin, $draft, e20Event([[$x, 1900]]));
    e20Champs()->publish($admin, $draft);                                       // منشورة غير معتمَدة

    expect(e20Glory()->stats($x)['championships'])->toBe(['participated' => 2, 'won' => 1, 'top3' => 2])
        ->and(e20Glory()->stats($y)['championships'])->toBe(['participated' => 3, 'won' => 1, 'top3' => 3])
        ->and(e20Glory()->stats($z)['championships'])->toBe(['participated' => 3, 'won' => 1, 'top3' => 3])
        ->and(e20Glory()->trophies($x)['championships_won']->pluck('title')->all())->toBe(['البطولة الأولى'])
        ->and(e20Glory()->trophies($x)['championship_top3']->pluck('rank')->all())->toBe([3]);
});

test('64/E3: the team profile shows the glory section - titles, recognition, challenge record and the latest matches - with no economic trophy', function () {
    [$a, $b] = [e19Team(null, ['name' => 'فريق المجد']), e19Team(null, ['name' => 'فريق الخصم'])];
    e20Match($a, $b, 5_000, 40_000);
    $champ = e20Completed([[$a, 1900], [$b, 1800]], 'بطولة الذهب');

    $page = $this->get(route('teams.show', $a))->assertOk();

    $page->assertSee('مجد الفريق')->assertSee('بطل «بطولة الذهب»')->assertSee('ضد فريق الخصم')->assertSee('فوز')->assertSee(route('team-championships.show', $champ), false);
    expect($page->getContent())->not->toMatch('/جوهر|عملة|XP|مكافأة اقتصادية/u');
});

test('65/D24: the hall of fame lists the team championship champions with the championship and its date', function () {
    [$a, $b] = [e19Team(null, ['name' => 'فريق الأبطال']), e19Team()];
    $champ = e20Completed([[$a, 1900], [$b, 1800]], 'كأس الفرق الكبرى');

    $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee('أبطال بطولات الفرق')->assertSee('فريق الأبطال')->assertSee('كأس الفرق الكبرى')->assertSee($champ->finalized_at->format('Y-m-d'))
        ->assertSee(route('teams.show', $a), false);
});

test('66/E4: a deactivated team keeps every historical trophy and record - the stats, the profile and the hall of fame still show them', function () {
    [$a, $b] = [e19Team(null, ['name' => 'فريق الذكرى']), e19Team()];
    e20Match($a, $b, 5_000, 40_000);
    e20Completed([[$a, 1900], [$b, 1800]], 'بطولة الذكرى');
    $before = e20Glory()->stats($a);

    e19Teams()->deactivate($a->owner, $a);

    expect(e20Glory()->stats($a->refresh()))->toBe($before)->and($before['championships']['won'])->toBe(1)->and($before['challenges']['won'])->toBe(1);
    $this->get(route('teams.show', $a))->assertOk()->assertSee('بطل «بطولة الذكرى»')->assertSee('غير مفعَّل');
    $this->get(route('competitions.hall-of-fame'))->assertOk()->assertSee('فريق الذكرى');
});

test('E3: the challenge button shows only to an owner or admin of another active team toward an active team - never to members, guests, the own team or an inactive one', function () {
    [$a, $b] = [e19Team(), e19Team()];
    $adminOfA = e19Member($a, null, 'admin');
    $memberOfA = e19Member($a);
    $url = route('teams.challenges.create', ['opponent' => $b->slug]);

    $this->actingAs($a->owner)->get(route('teams.show', $b))->assertSee($url, false);
    $this->actingAs($adminOfA)->get(route('teams.show', $b))->assertSee($url, false);
    $this->actingAs($memberOfA)->get(route('teams.show', $b))->assertDontSee($url, false);
    $this->actingAs($a->owner)->get(route('teams.show', $a))->assertDontSee('تحدَّ هذا الفريق');
    $this->actingAs(e16User())->get(route('teams.show', $b))->assertDontSee($url, false);
    auth()->forgetGuards();
    $this->get(route('teams.show', $b))->assertDontSee($url, false);

    e19Teams()->deactivate($b->owner, $b);
    $this->actingAs($a->owner)->get(route('teams.show', $b->refresh()))->assertDontSee($url, false);
});

test('the team profile glory section runs a fixed number of queries however many matches the team played (no N+1)', function () {
    [$a, $b] = [e19Team(), e19Team()];
    e20Match($a, $b, 5_000, 40_000);
    $this->get(route('teams.show', $a))->assertOk();
    $count = function () use ($a) {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get(route('teams.show', $a))->assertOk();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $n;
    };
    $few = $count();

    foreach (range(1, 6) as $i) {
        e20Match($a, e19Team(), 5_000 + $i, 40_000);
    }

    $this->get(route('teams.show', $a))->assertOk();      // إحماء بعد تقدّم الزمن (بصمة الجهاز تُحدَّث مرة عند تجاوز نافذتها: ليست من المباريات)
    expect($count())->toBe($few);
});
