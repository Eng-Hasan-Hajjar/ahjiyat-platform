<?php

require_once __DIR__.'/TeamCompetitionTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\OperationalAuditLog;
use App\Models\TeamChampionship;
use App\Models\TeamChampionshipResult;
use App\Services\Teams\TeamException;
use Carbon\Carbon;

beforeEach(function () {
    e17Freeze();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});
afterEach(fn () => Carbon::setTestNow());

/** بطولة منشورة بحدثين معتمَدين وفرق ثلاثة. @return array{0: TeamChampionship, 1: array, 2: array<int, \App\Models\Team>} */
function e20Published(int $teams = 3): array
{
    $t = collect(range(1, $teams))->map(fn () => e19Team())->all();
    $events = [
        e20Event(array_map(fn ($team, $i) => [$team, 1900 - $i * 100], $t, array_keys($t))),
        e20Event(array_map(fn ($team, $i) => [$team, 1900 - $i * 100], $t, array_keys($t))),
    ];
    $champ = e20Championship(null, ['ends_at' => now()->addDays(2)]);
    $admin = e20Admin([], 'administrator');

    foreach ($events as $event) {
        e20Champs()->linkEvent($admin, $champ, $event);
    }

    e20Champs()->publish($admin, $champ);

    return [$champ->refresh(), $events, $t];
}

test('44/45: an authorized admin creates a draft championship from the domain service - and anyone without the permission is refused with nothing created', function () {
    $champ = e20Championship();

    expect($champ->status)->toBe('draft')->and($champ->isDraft())->toBeTrue()->and($champ->champion_team_id)->toBeNull()->and($champ->points_snapshot)->toBeNull()->and($champ->finalized_at)->toBeNull();

    $deny = \Symfony\Component\HttpKernel\Exception\HttpException::class;

    // الإنشاء بـmanage فقط: العادي وصاحب view وصاحب publish كلهم مرفوضون.
    foreach ([e16User(), e20Admin(['team_championships.view']), e20Admin(['team_championships.publish'])] as $nobody) {
        expect(fn () => e20Champs()->create($nobody, ['title' => 'x', 'slug' => 'x-'.$nobody->id, 'starts_at' => now(), 'ends_at' => now()->addDay()]))->toThrow($deny);
    }

    // دورة الحياة بـpublish فقط: العادي وصاحب view وصاحب manage كلهم مرفوضون.
    foreach ([e16User(), e20Admin(['team_championships.view']), e20Admin(['team_championships.manage'])] as $nobody) {
        expect(fn () => e20Champs()->publish($nobody, $champ))->toThrow($deny)->and(fn () => e20Champs()->cancel($nobody, $champ))->toThrow($deny)->and(fn () => e20Champs()->finalize($nobody, $champ))->toThrow($deny);
    }
    expect(TeamChampionship::count())->toBe(1)->and($champ->refresh()->status)->toBe('draft');
    expect((new TeamChampionship)->getFillable())->not->toContain('status')->not->toContain('champion_team_id')->not->toContain('points_snapshot')->not->toContain('finalized_at');
});

test('46/47/D4: an event links once, and an incompatible event is refused - a draft, a cancelled one, or a finished one with no team ranking', function () {
    [$x, $y] = [e19Team(), e19Team()];
    $good = e20Event([[$x, 1900], [$y, 1800]]);
    $draft = e17Event([], 'draft');
    $cancelled = e17Event([], 'cancelled');
    $noTeams = e19Complete(e17Event(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)]));       // بلا لقطات فرق
    $live = e17Event();                                                                                           // منشور جارٍ: مقبول
    $champ = e20Championship();
    $admin = e20Admin([], 'administrator');

    e20Champs()->linkEvent($admin, $champ, $good);
    e20Champs()->linkEvent($admin, $champ, $live);

    expect(fn () => e20Champs()->linkEvent($admin, $champ, $good))->toThrow(TeamException::class, 'بالفعل')
        ->and(fn () => e20Champs()->linkEvent($admin, $champ, $draft))->toThrow(TeamException::class, 'غير منشور')
        ->and(fn () => e20Champs()->linkEvent($admin, $champ, $cancelled))->toThrow(TeamException::class, 'غير منشور')
        ->and(fn () => e20Champs()->linkEvent($admin, $champ, $noTeams))->toThrow(TeamException::class, 'لا ترتيب فرق')
        ->and(fn () => \Illuminate\Support\Facades\DB::table('team_championship_events')->insert(['team_championship_id' => $champ->id, 'competitive_event_id' => $good->id, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    expect($champ->events()->count())->toBe(2);
});

test('48/49/D5/D20: only finalized events with a stored team ranking give points - a live event, an unfinalized one and a cancelled one contribute nothing', function () {
    [$x, $y] = [e19Team(), e19Team()];
    $done = e20Event([[$x, 1900], [$y, 1800]]);
    $live = e17Event();
    e19Result($live, e16User(), $x, 1999, 1000);                                                                  // نتائج حية بلا اعتماد
    $unfinalized = e19Complete(e17Event(['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)]));
    e19Result($unfinalized, e16User(), $y, 1999, 1000);                                                           // معتمَد لكن ترتيب الفرق لم يُخزَّن
    $toCancel = e20Event([[$y, 1990], [$x, 1000]]);
    $champ = e20Championship(null, ['ends_at' => now()->addDays(2)]);
    $admin = e20Admin([], 'administrator');

    foreach ([$done, $live, $toCancel] as $e) {
        e20Champs()->linkEvent($admin, $champ, $e);
    }
    DB::table('team_championship_events')->insert(['team_championship_id' => $champ->id, 'competitive_event_id' => $unfinalized->id, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]);

    $both = collect(e20Standings()->compute($champ))->keyBy('team_id');
    expect($both[$x->id]['points'])->toBe(10 + 7)->and($both[$y->id]['points'])->toBe(7 + 10)->and($both[$x->id]['events_count'])->toBe(2);                 // حدثان معتمَدان فقط

    $toCancel->forceFill(['status' => 'cancelled'])->save();                                                       // أُلغي بعد الاعتماد: لا يمنح نقاطًا
    $after = collect(e20Standings()->compute($champ))->keyBy('team_id');
    expect($after[$x->id]['points'])->toBe(10)->and($after[$y->id]['points'])->toBe(7)->and($after[$x->id]['events_count'])->toBe(1);
});

test('50/51/D6/D7/D10: points come from the PLACEMENT, never from raw puzzle scores - wildly different score scales give identical points, and the mapping is exact', function () {
    $teams = collect(range(1, 6))->map(fn () => e19Team())->all();
    $big = e20Event(array_map(fn ($t, $i) => [$t, 1990 - $i * 3], $teams, array_keys($teams)));                 // درجات قريبة جدًا ومرتفعة
    $small = e20Event(array_map(fn ($t, $i) => [$t, 120 - $i * 20], $teams, array_keys($teams)));                // سلّم درجات مختلف تمامًا
    $champ = e20Championship(null, ['ends_at' => now()->addDays(2)]);
    $admin = e20Admin([], 'administrator');
    e20Champs()->linkEvent($admin, $champ, $big);
    e20Champs()->linkEvent($admin, $champ, $small);

    $rows = collect(e20Standings()->compute($champ));

    expect($rows->pluck('points')->all())->toBe([20, 14, 10, 6, 4, 0])                                             // 1→10 2→7 3→5 4→3 5→2 والسادس بلا نقاط (×2 حدثان)
        ->and($rows->pluck('team_id')->all())->toBe(collect($teams)->pluck('id')->all())->and($rows->pluck('rank')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($rows->first()['event_wins'])->toBe(2)->and($rows->first()['best_rank'])->toBe(1);
    $code = e19Code(app_path('Services/Teams/TeamChampionshipStandingsService.php'));
    expect($code)->not->toContain('->score')->and($code)->not->toContain("'score'")->and($code)->not->toContain('sum(r.score');           // لا يلمس الدرجات الخام أبدًا
});

test('52/53/D11/D15/D16: standings are deterministic and tie-break in a fixed order - points, event wins, top-3 finishes, best rank, then team id; no reshuffle inside the championship', function () {
    config(['teams.championships.points' => [1 => 10, 2 => 6, 3 => 4, 4 => 2]]);
    [$x, $y, $u, $v, $f1, $f2] = [e19Team(), e19Team(), e19Team(), e19Team(), e19Team(), e19Team()];
    // x: مراكز [1, 4] = 12 نقطة وانتصار واحد. y: [2, 2] = 12 نقطة بلا انتصار. تعادل نقاط يحسمه الانتصارات.
    $e1 = e20Event([[$x, 1900], [$y, 1800], [$f1, 1700], [$f2, 1600]]);
    $e2 = e20Event([[$f1, 1900], [$y, 1800], [$f2, 1700], [$x, 1600]]);
    // u وv: حدثان متبادلان ← تطابق تام ببقية المعايير ← معرّف الفريق الأصغر.
    $e3 = e20Event([[$u, 1900], [$v, 1800]]);
    $e4 = e20Event([[$v, 1900], [$u, 1800]]);
    $champ = e20Championship(null, ['ends_at' => now()->addDays(2)]);
    $admin = e20Admin([], 'administrator');
    foreach ([$e1, $e2, $e3, $e4] as $e) {
        e20Champs()->linkEvent($admin, $champ, $e);
    }

    $first = e20Standings()->compute($champ);
    $second = e20Standings()->compute($champ);
    $byTeam = collect($first)->keyBy('team_id');

    expect($first)->toBe($second)->and($byTeam[$x->id]['points'])->toBe(12)->and($byTeam[$y->id]['points'])->toBe(12)->and($byTeam[$u->id]['points'])->toBe(16)->and($byTeam[$v->id]['points'])->toBe(16)
        ->and(collect($first)->pluck('team_id')->take(2)->all())->toBe([min($u->id, $v->id), max($u->id, $v->id)])                    // تعادل تام: المعرّف الأصغر
        ->and($byTeam[$x->id]['rank'])->toBeLessThan($byTeam[$y->id]['rank']);                                                         // 12 = 12: الانتصارات تحسم لـx
});

test('54/D17: a championship cannot be finalized too early - before its end, with an event still unfinalized, or with no contributing event', function () {
    [$champ, $events, $teams] = e20Published();
    $admin = e20Admin([], 'administrator');

    expect(e20Champs()->finalizeBlocker($champ))->toBe('لم تنتهِ البطولة بعد.')->and(fn () => e20Champs()->finalize($admin, $champ))->toThrow(TeamException::class, 'لم تنتهِ')
        ->and(e20Champs()->finalize(null, $champ))->toBeFalse();                                                                     // الأمر الدوري: يتجاهل بصمت

    // بعد النهاية لكن حدثًا مرتبطًا غير معتمَد بعد.
    $live = e17Event();
    $draft = e20Championship(null, ['ends_at' => now()->addDays(2)]);
    e20Champs()->linkEvent($admin, $draft, $events[0]);
    e20Champs()->linkEvent($admin, $draft, $live);
    e20Champs()->publish($admin, $draft);
    Carbon::setTestNow(now()->addDays(3));
    expect(e20Champs()->finalizeBlocker($draft->refresh()))->toBe('ما زالت أحداث مرتبطة غير معتمَدة بترتيب فرق.')->and(fn () => e20Champs()->finalize($admin, $draft))->toThrow(TeamException::class)
        ->and($draft->refresh()->status)->toBe('published');

    expect(TeamChampionshipResult::count())->toBe(0);
});

test('55/56/57/D18: it finalizes after the end once every linked event is finalized - the champion is rank one, results are stored once, and finalizing twice changes nothing', function () {
    [$champ, $events, $teams] = e20Published();
    $admin = e20Admin([], 'administrator');
    Carbon::setTestNow(now()->addDays(3));

    expect(e20Champs()->finalizeBlocker($champ))->toBeNull()->and(e20Champs()->finalize($admin, $champ))->toBeTrue();
    $champ->refresh();
    $stored = TeamChampionshipResult::orderBy('rank')->get(['team_id', 'points', 'events_count', 'event_wins', 'top3_count', 'best_rank', 'rank'])->toArray();

    expect($champ->status)->toBe('completed')->and($champ->champion_team_id)->toBe($teams[0]->id)->and($champ->finalized_at)->not->toBeNull()->and($stored)->toHaveCount(3)
        ->and($stored[0]['points'])->toBe(20)->and($stored[0]['rank'])->toBe(1)->and($stored[1]['points'])->toBe(14)->and($stored[2]['points'])->toBe(10)
        ->and(OperationalAuditLog::where('action', 'team_championship_finalized')->count())->toBe(1);

    expect(e20Champs()->finalize($admin, $champ->refresh()))->toBeFalse()->and(e20Champs()->finalize(null, $champ->refresh()))->toBeFalse()
        ->and(TeamChampionshipResult::orderBy('rank')->get(['team_id', 'points', 'events_count', 'event_wins', 'top3_count', 'best_rank', 'rank'])->toArray())->toBe($stored)
        ->and(OperationalAuditLog::where('action', 'team_championship_finalized')->count())->toBe(1)->and(TeamChampionship::find($champ->id)->champion_team_id)->toBe($teams[0]->id);
});

test('58/59/D19: after publishing the events, the dates and the points mapping are locked - and after finalization nothing can change history, not even a later config change', function () {
    [$champ, $events, $teams] = e20Published();
    $admin = e20Admin([], 'administrator');
    $extra = e20Event([[$teams[2], 1900], [$teams[0], 1800]]);

    expect(fn () => e20Champs()->linkEvent($admin, $champ, $extra))->toThrow(TeamException::class, 'مقفلة')
        ->and(fn () => e20Champs()->unlinkEvent($admin, $champ, $events[0]))->toThrow(TeamException::class, 'مقفلة')
        ->and(fn () => $champ->update(['starts_at' => now()->subDays(30)]))->toThrow(InvalidArgumentException::class, 'مقفلة')
        ->and(fn () => $champ->update(['ends_at' => now()->addDays(30)]))->toThrow(InvalidArgumentException::class, 'مقفلة')
        ->and(fn () => $champ->forceFill(['points_snapshot' => [1 => 999]])->save())->toThrow(InvalidArgumentException::class, 'مقفلة')
        ->and($champ->refresh()->events()->count())->toBe(2);
    expect(e20Champs()->update($admin, $champ, ['title' => 'عنوان معدَّل', 'starts_at' => now()->subDays(99)])->title)->toBe('عنوان معدَّل');            // العنوان فقط
    expect($champ->refresh()->starts_at->isSameDay(now()->subDays(12)))->toBeTrue();

    // لقطة النقاط: تغيير الإعداد لاحقًا لا يغيّر شيئًا.
    config(['teams.championships.points' => [1 => 1, 2 => 1]]);
    expect($champ->pointsMap())->toBe([1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2])->and(collect(e20Standings()->compute($champ))->first()['points'])->toBe(20);

    Carbon::setTestNow(now()->addDays(3));
    e20Champs()->finalize($admin, $champ);
    $stored = TeamChampionshipResult::orderBy('rank')->pluck('points')->all();
    config(['teams.championships.points' => [1 => 500]]);

    expect($stored)->toBe([20, 14, 10])->and(fn () => $champ->refresh()->update(['title' => 'x']))->toThrow(InvalidArgumentException::class, 'لا تتغير')
        ->and(fn () => e20Champs()->cancel($admin, $champ))->toThrow(TeamException::class)->and(TeamChampionshipResult::orderBy('rank')->pluck('points')->all())->toBe($stored)
        ->and(e20Standings()->standings($champ)['final'])->toBeTrue()->and(e20Standings()->standings($champ)['rows']->pluck('points')->all())->toBe($stored);
});

test('publishing needs at least one compatible event and a future end - it takes the points snapshot and is audited; cancelling is audited and final', function () {
    $admin = e20Admin([], 'administrator');
    $empty = e20Championship();
    $past = e20Championship(null, ['ends_at' => now()->subDay()]);
    $x = e19Team();
    $past->events();
    e20Champs()->linkEvent($admin, $past, e20Event([[$x, 1900]]));

    expect(fn () => e20Champs()->publish($admin, $empty))->toThrow(TeamException::class, 'حدثًا واحدًا')->and(fn () => e20Champs()->publish($admin, $past))->toThrow(TeamException::class, 'بالمستقبل');

    [$champ] = e20Published();
    expect($champ->status)->toBe('published')->and($champ->published_at)->not->toBeNull()->and($champ->points_snapshot)->toBe([1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2])
        ->and(OperationalAuditLog::where('action', 'team_championship_published')->count())->toBe(1)->and(fn () => e20Champs()->publish($admin, $champ))->toThrow(TeamException::class, 'المسودات');

    expect(e20Champs()->cancel($admin, $champ)->status)->toBe('cancelled')->and(OperationalAuditLog::where('action', 'team_championship_cancelled')->count())->toBe(1)
        ->and(fn () => e20Champs()->cancel($admin, $champ))->toThrow(TeamException::class)->and(e20Champs()->finalize(null, $champ->refresh()))->toBeFalse();
});

test('60/D9: there are no retroactive or automatic championships - the lifecycle and finalized events never create one, and nothing is registered separately', function () {
    [$x, $y] = [e19Team(), e19Team()];
    e20Event([[$x, 1900], [$y, 1800]]);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);
    $this->artisan('teams:process-lifecycle')->assertExitCode(0);

    expect(TeamChampionship::count())->toBe(0)->and(TeamChampionshipResult::count())->toBe(0)->and(\Illuminate\Support\Facades\Schema::getColumnListing('team_championships'))->not->toContain('registration_ends_at');
});

test('D21/D22: public pages show published and finished championships only - drafts and cancelled ones are 404 - with the standings, events, champion and points rules', function () {
    [$champ, $events, $teams] = e20Published();
    $draft = e20Championship();
    $cancelled = e20Championship();
    e20Champs()->cancel(e20Admin([], 'administrator'), $cancelled);

    $this->get(route('team-championships.index'))->assertOk()->assertSee($champ->title)->assertDontSee($draft->title)->assertDontSee($cancelled->title);
    $this->get(route('team-championships.show', $champ))->assertOk()->assertSee('الترتيب المؤقت (غير نهائي)')->assertSee($teams[0]->name)->assertSee($events[0]->title)->assertSee('المركز 1 = 10')->assertSee('لا جمع لدرجات خام');
    $this->get(route('team-championships.show', $draft))->assertNotFound();
    $this->get(route('team-championships.show', $cancelled))->assertNotFound();

    Carbon::setTestNow(now()->addDays(3));
    e20Champs()->finalize(null, $champ->refresh());
    $this->get(route('team-championships.show', $champ))->assertOk()->assertSee('الترتيب النهائي')->assertSee('البطل')->assertDontSee('غير نهائي');
    $this->get(route('team-championships.index'))->assertOk()->assertSee('🏆 '.$teams[0]->name);
});

test('the championship page runs a fixed number of queries however many teams stand (no N+1)', function () {
    [$champ] = e20Published(3);
    $this->get(route('team-championships.show', $champ))->assertOk();                      // إحماء
    $count = function () use ($champ) {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get(route('team-championships.show', $champ))->assertOk();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $n;
    };
    $few = $count();
    $many = collect(range(1, 12))->map(fn () => e19Team())->all();
    $ev = e20Event(array_map(fn ($t, $i) => [$t, 1500 - $i * 10], $many, array_keys($many)));
    \Illuminate\Support\Facades\DB::table('team_championship_events')->insert(['team_championship_id' => $champ->id, 'competitive_event_id' => $ev->id, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now()]);

    expect($count())->toBe($few);
});
