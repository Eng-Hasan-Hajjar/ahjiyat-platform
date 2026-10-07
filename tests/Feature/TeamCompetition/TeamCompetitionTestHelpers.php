<?php

require_once __DIR__.'/../Teams/TeamTestHelpers.php';

use App\Models\Puzzle;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\User;
use App\Services\Teams\TeamChallengeFinalizer;
use App\Services\Teams\TeamChallengePlayService;
use App\Services\Teams\TeamChallengeService;

/** مساعدات E20: كلها عبر الخدمات الحقيقية. اللعب = بدء ثم مرور زمن ثم إرسال (المدة تقيسها الخدمة من ساعة الخادم). */
if (! function_exists('e20Challenges')) {
    function e20Challenges(): TeamChallengeService
    {
        return app(TeamChallengeService::class);
    }

    function e20Play(): TeamChallengePlayService
    {
        return app(TeamChallengePlayService::class);
    }

    function e20Finalizer(): TeamChallengeFinalizer
    {
        return app(TeamChallengeFinalizer::class);
    }

    /** @param  list<User>  $users */
    function e20Ids(array $users): array
    {
        return array_map(fn (User $u) => $u->public_id, $users);
    }

    /** فريقان بأعضاء (المالك + أعضاء). @return array{0: Team, 1: Team, 2: list<User>, 3: list<User>} */
    function e20Pair(int $aMembers = 3, int $bMembers = 3): array
    {
        $a = e19Team(null, ['name' => 'فريق أ '.Str::random(5)]);
        $b = e19Team(null, ['name' => 'فريق ب '.Str::random(5)]);
        $am = [$a->owner];
        $bm = [$b->owner];

        for ($i = 1; $i < $aMembers; $i++) {
            $am[] = e19Member($a);
        }

        for ($i = 1; $i < $bMembers; $i++) {
            $bm[] = e19Member($b);
        }

        return [$a->refresh(), $b->refresh(), $am, $bm];
    }

    /** تحدٍّ معلّق من أ إلى ب بروستر أ. */
    function e20Create(Team $a, Team $b, array $aRoster, ?Puzzle $puzzle = null): TeamChallenge
    {
        return e20Challenges()->create($a->owner, $b, $puzzle ?? e17Puzzle(), e20Ids($aRoster));
    }

    /** تحدٍّ مقبول ومقفل الروستر (ب تقبل بروسترها). */
    function e20Accepted(Team $a, Team $b, array $aRoster, array $bRoster, ?Puzzle $puzzle = null): TeamChallenge
    {
        $challenge = e20Create($a, $b, $aRoster, $puzzle);

        return e20Challenges()->accept($b->owner, $challenge, e20Ids($bRoster))->refresh();
    }

    /** لاعب يلعب: بدء، مرور $ms، إرسال إجابة. */
    function e20Submit(User $user, TeamChallenge $challenge, string $answer, int $ms): \App\Services\Competitive\CompetitiveOutcome
    {
        e20Play()->start($user, $challenge);
        e17Forward($ms);

        return e20Play()->submit($user, $challenge->refresh(), ['answer' => $answer]);
    }
}

if (! function_exists('e20Admin')) {
    /** مستخدم بصلاحيات محددة أو بدور administrator (يحتاج Seeder الأدوار). */
    function e20Admin(array $permissions = [], ?string $role = null): User
    {
        $user = e16User();
        $permissions && $user->givePermissionTo($permissions);
        $role && $user->assignRole($role);

        return $user;
    }

    /** حدث معتمَد بترتيب فرق مخزَّن: [[Team, درجة]...] (الدرجات الخام تحدد المراكز بنفس صيغة E19). */
    function e20Event(array $teamScores, array $attrs = []): \App\Models\CompetitiveEvent
    {
        $event = e19Complete(e17Event($attrs + ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5)]));

        foreach ($teamScores as [$team, $score]) {
            e19Result($event, e16User(), $team, $score, 9000);
        }

        e19Ranking()->finalize($event);

        return $event->refresh();
    }

    /** بطولة مسودة (بالخدمة، بصلاحية الإدارة). */
    function e20Championship(?User $admin = null, array $data = []): \App\Models\TeamChampionship
    {
        return app(\App\Services\Teams\TeamChampionshipService::class)->create($admin ?? e20Admin([], 'administrator'), $data + [
            'title' => 'بطولة '.Str::random(5), 'slug' => 'champ-'.Str::lower(Str::random(6)), 'starts_at' => now()->subDays(12), 'ends_at' => now()->addDays(5),
        ]);
    }

    function e20Standings(): \App\Services\Teams\TeamChampionshipStandingsService
    {
        return app(\App\Services\Teams\TeamChampionshipStandingsService::class);
    }

    function e20Champs(): \App\Services\Teams\TeamChampionshipService
    {
        return app(\App\Services\Teams\TeamChampionshipService::class);
    }
}

if (! function_exists('e20Match')) {
    /** مباراة كاملة بين ناديي أ وب (روستر المالك فقط): كلٌّ يلعب بزمن معلوم ← تُعتمد تلقائيًا. */
    function e20Match(Team $a, Team $b, int $aMs, int $bMs, ?string $bAnswer = null): TeamChallenge
    {
        $c = e20Accepted($a, $b, [$a->owner], [$b->owner], e17Puzzle());
        e20Submit($a->owner, $c, E17_ANSWER, $aMs);
        e20Submit($b->owner, $c, $bAnswer ?? E17_ANSWER, $bMs);

        return $c->refresh();
    }

    /** بطولة معتمَدة بحدث واحد: [[Team, درجة]...] ← يرجع البطولة المعتمَدة. */
    function e20Completed(array $teamScores, string $title = 'بطولة معتمَدة'): \App\Models\TeamChampionship
    {
        $base = now();
        $admin = e20Admin([], 'administrator');
        $event = e20Event($teamScores);
        $champ = e20Championship($admin, ['title' => $title, 'ends_at' => $base->copy()->addDay()]);
        e20Champs()->linkEvent($admin, $champ, $event);
        e20Champs()->publish($admin, $champ);
        Carbon\Carbon::setTestNow($base->copy()->addDays(2));
        e20Champs()->finalize($admin, $champ);
        Carbon\Carbon::setTestNow($base);

        return $champ->refresh();
    }

    function e20Glory(): \App\Services\Teams\TeamGloryService
    {
        return app(\App\Services\Teams\TeamGloryService::class);
    }
}
