<?php

namespace Database\Seeders\Demo;

use App\Models\CompetitiveEvent;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use App\Models\User;
use App\Services\Teams\TeamChallengePlayService;
use App\Services\Teams\TeamChallengeService;
use App\Services\Teams\TeamChampionshipService;
use App\Services\Teams\TeamNaming;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * منافسة الفرق (F: E19 + E20) بخدمات E20 الرسمية وبزمن محاكى: تحدّيات الفرق (معلّق/جارٍ/فوز/خسارة/تعادل) بروسترات **مقفلة عند القبول**، وبطولات الفرق (معتمَدة ×2، حيّة، قادمة)
 * مربوطة بأحداث الجولات المعتمَدة (ترتيب فرقها مخزَّن بلقطات التسجيل). النتيجة والفائز والبطل **تشتقها الخدمات** (لا تُكتب). Idempotent: التحدّي بالفريقين والأحجية، والبطولة بمعرّفها وحالتها.
 *
 *  بطولة الربيع (جولتان 1+2): البطل صقور المعرفة، الثاني عباقرة الشرق، الثالث فرسان الشام.
 *  بطولة الخريف (جولتان 3+4): البطل **فرسان الشام (فريق يوسف)**، ثم صقور المعرفة، ثم عباقرة الشرق.
 *  بطولة الشتاء (حيّة): ترتيب مؤقت من الجولة 4. بطولة الربيع القادم (قادمة): مرتبطة بالجولة القادمة.
 */
class DemoTeamCompetitionSeeder extends Seeder
{
    use DemoSupport;

    public function run(): void
    {
        $this->quiet(fn () => $this->syncQueue(function () {
            $this->challenges();
            $this->championships();
        }));

        $this->say('فرق: تحدّيات (معلّق/جارٍ/فوز/خسارة/تعادل) بروسترات مقفلة + بطولات (معتمَدة×2 / حيّة / قادمة) بأبطال محدَّدين.');
    }

    // ---------------------------------------------------------------- تحدّيات الفرق

    protected function challenges(): void
    {
        $knights = $this->team('فرسان الشام');
        $falcons = $this->team('صقور المعرفة');
        $geniuses = $this->team('عباقرة الشرق');
        $stars = $this->team('نجوم الأحجيات');

        // مكتمل: فوز لفرسان الشام (3 مقابل 2 لاعبين).
        $this->match($knights, $stars, 'C15', now()->subDays(6)->setTime(19, 0), ['yousef' => 9, 'omar' => 12, 'layan' => 15], ['noureddine' => 14, 'eman' => 25]);
        // مكتمل: خسارة لفرسان الشام أمام صقور المعرفة.
        $this->match($knights, $falcons, 'C16', now()->subDays(3)->setTime(19, 0), ['yousef' => 20, 'omar' => 25], ['sara' => 8, 'malak' => 10, 'wisam' => 12]);
        // مكتمل: تعادل حقيقي (زمنان متساويان) بين فرسان الشام وعباقرة الشرق.
        $this->match($knights, $geniuses, 'C01', now()->subDay()->setTime(19, 0), ['yousef' => 15], ['reem' => 15]);

        // جارٍ: فرسان الشام ضد عباقرة الشرق. لعب عمر وريم وشذى؛ يوسف وليان لم يلعبا بعد (مهلة اللعب مفتوحة).
        if (! $this->exists($knights, $geniuses, 'C14')) {
            $service = app(TeamChallengeService::class);
            $start = now()->subHours(6);
            $challenge = $this->at($start, fn () => $service->create($this->user('yousef'), $geniuses, DemoPuzzleCatalog::puzzle('C14'), $this->ids(['yousef', 'omar', 'layan'])));
            $this->at($start->copy()->addHour(), fn () => $service->accept($this->user('reem'), $challenge, $this->ids(['reem', 'shatha'])));
            $this->playAll($challenge, 'C14', $start->copy()->addHours(2), ['omar' => 11, 'reem' => 14, 'shatha' => 18]);
        }

        // وارد معلّق: صقور المعرفة تتحدّى فرسان الشام (يقبله يوسف باختيار روستره).
        if (! $this->exists($falcons, $knights, 'C13')) {
            $this->at(now()->subHours(5), fn () => app(TeamChallengeService::class)->create($this->user('sara'), $knights, DemoPuzzleCatalog::puzzle('C13'), $this->ids(['sara', 'malak'])));
        }
    }

    /** مباراة كاملة: إنشاء ← قبول (قفل الروستر) ← لعب الجميع (فيُعتمد مبكرًا تلقائيًا). */
    protected function match(Team $challenger, Team $opponent, string $puzzleKey, CarbonInterface $start, array $challengerPlayers, array $opponentPlayers): void
    {
        if ($this->exists($challenger, $opponent, $puzzleKey)) {
            return;
        }

        $service = app(TeamChallengeService::class);
        $challenge = $this->at($start, fn () => $service->create($challenger->owner, $opponent, DemoPuzzleCatalog::puzzle($puzzleKey), $this->ids(array_keys($challengerPlayers))));
        $this->at($start->copy()->addMinutes(40), fn () => $service->accept($opponent->owner, $challenge, $this->ids(array_keys($opponentPlayers))));
        $this->playAll($challenge, $puzzleKey, $start->copy()->addHours(2), $challengerPlayers + $opponentPlayers);
    }

    /** @param  array<string, int>  $players  مفتاح => ثواني (يلعب كلٌّ بدوره بفاصل 20 دقيقة). */
    protected function playAll(TeamChallenge $challenge, string $puzzleKey, CarbonInterface $first, array $players): void
    {
        $play = app(TeamChallengePlayService::class);
        $i = 0;

        foreach ($players as $key => $seconds) {
            $user = $this->user($key);
            $at = $first->copy()->addMinutes(20 * $i++);
            $this->at($at, fn () => $play->start($user, $challenge->refresh()));
            $this->at($at->copy()->addSeconds($seconds), fn () => $play->submit($user, $challenge->refresh(), ['answer' => DemoPuzzleCatalog::answer($puzzleKey)]));
        }
    }

    protected function exists(Team $a, Team $b, string $puzzleKey): bool
    {
        $puzzle = DemoPuzzleCatalog::puzzle($puzzleKey);

        return TeamChallenge::query()->where('puzzle_id', $puzzle->id)->where(fn ($q) => $q->where(fn ($w) => $w->where('challenger_team_id', $a->id)->where('opponent_team_id', $b->id))
            ->orWhere(fn ($w) => $w->where('challenger_team_id', $b->id)->where('opponent_team_id', $a->id)))->exists();
    }

    // ---------------------------------------------------------------- بطولات الفرق

    protected function championships(): void
    {
        $admin = User::query()->where('email', 'admin@ahjiyat.app')->firstOrFail();

        $defs = [
            ['slug' => 'demo-championship-spring', 'title' => 'بطولة الفرق: كأس الربيع', 'starts' => now()->subDays(32), 'ends' => now()->subDays(15), 'publish' => now()->subDays(31), 'finalize' => now()->subDays(14),
                'events' => ['demo-cup-1', 'demo-cup-2'], 'featured' => false, 'description' => 'جولتان بنقاط المركز لكل فريق. الأعلى نقاطًا بطل الربيع.'],
            ['slug' => 'demo-championship-autumn', 'title' => 'بطولة الفرق: كأس الخريف', 'starts' => now()->subDays(14), 'ends' => now()->subDays(3), 'publish' => now()->subDays(13), 'finalize' => now()->subDays(2),
                'events' => ['demo-cup-3', 'demo-cup-4'], 'featured' => false, 'description' => 'جولتان حاسمتان: تُحتسب نقاط كل فريق من مركزه بكل جولة معتمَدة.'],
            ['slug' => 'demo-championship-winter', 'title' => 'بطولة الفرق: كأس الشتاء (جارية)', 'starts' => now()->subDays(6), 'ends' => now()->addDays(12), 'publish' => now()->subDays(6), 'finalize' => null,
                'events' => ['demo-cup-4', 'demo-live'], 'featured' => true, 'description' => 'بطولة جارية: الترتيب مؤقت حتى تُعتمد كل جولاتها.'],
            ['slug' => 'demo-championship-spring-2', 'title' => 'بطولة الفرق: كأس الربيع القادم', 'starts' => now()->addDays(3), 'ends' => now()->addDays(20), 'publish' => now(), 'finalize' => null,
                'events' => ['demo-upcoming'], 'featured' => false, 'description' => 'بطولة قادمة: تبدأ مع الجولة القادمة.'],
        ];

        foreach ($defs as $def) {
            $this->championship($admin, $def);
        }
    }

    protected function championship(User $admin, array $def): void
    {
        $service = app(TeamChampionshipService::class);
        $championship = TeamChampionship::query()->where('slug', $def['slug'])->first()
            ?? $this->at($def['publish']->copy()->subHour(), fn () => $service->create($admin, [
                'title' => $def['title'], 'slug' => $def['slug'], 'description' => $def['description'], 'starts_at' => $def['starts'], 'ends_at' => $def['ends'], 'is_featured' => $def['featured'],
            ]));

        foreach ($def['events'] as $slug) {
            $event = CompetitiveEvent::query()->where('slug', $slug)->firstOrFail();
            $linked = DB::table('team_championship_events')->where('team_championship_id', $championship->id)->where('competitive_event_id', $event->id)->exists();
            $linked || $championship->status !== TeamChampionship::STATUS_DRAFT || $service->linkEvent($admin, $championship, $event);
        }

        $championship->refresh()->status === TeamChampionship::STATUS_DRAFT && $this->at($def['publish'], fn () => $service->publish($admin, $championship));

        if ($def['finalize'] !== null && $championship->refresh()->status === TeamChampionship::STATUS_PUBLISHED) {
            $this->at($def['finalize'], fn () => $service->finalize($admin, $championship));
        }
    }

    // ---------------------------------------------------------------- أدوات

    protected function team(string $name): Team
    {
        return Team::query()->where('name_key', TeamNaming::key(TeamNaming::normalize($name)))->firstOrFail();
    }

    /** @param  list<string>  $keys @return list<string> */
    protected function ids(array $keys): array
    {
        return array_map(fn ($k) => $this->user($k)->public_id, $keys);
    }
}
