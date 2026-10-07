<?php

namespace Database\Seeders\Demo;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\FriendChallenge;
use App\Models\User;
use App\Services\Competitive\CompetitiveEventAdminService;
use App\Services\Competitive\CompetitiveEventFinalizer;
use App\Services\Competitive\CompetitiveEventService;
use App\Services\Competitive\FriendChallengeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * المنافسات الفردية وتحدّيات الأصدقاء والمكافآت (E): كل شيء **بخدمات E17/E18 الرسمية** وبزمن محاكى: التسجيل (لقطة الفريق وقت التسجيل)، التشغيل (المدة والصحة والدرجة من الخادم)،
 * الاعتماد (ترتيب نهائي + جوائز E18 + ترتيب الفرق E19 + إنجازات المنافسة) بطابور متزامن. لا نتيجة مكتوبة يدويًا. Idempotent: الحدث بمعرّفه والتحدّي بحالته.
 *
 * جدول الأحداث (أزمنة اللاعبين بالثواني؛ الدرجة تنقص بزيادة الزمن فالترتيب حتمي):
 *   جولة 1 (قبل 24 يومًا) | جولة 2 (قبل 17) | جولة 3 (قبل 10، بجوائز E18) | جولة 4 (قبل 4) ← منتهية ومعتمَدة. حيّة الآن | قادمة | ملغاة.
 * يوسف: المركز 5 بالجولة 1، 7 بالجولة 2 (منتصف)، **2** بالجولة 3، **1** بالجولة 4. الفرق: ترتيب فرق الجولات يغذّي بطولات DemoTeamCompetitionSeeder.
 */
class DemoCompetitionSeeder extends Seeder
{
    use DemoSupport;

    /** حدث => [العنوان، أحجية، قبل كم يومًا بدأ، اللاعبون [مفتاح => ثواني | null (خاطئة)]]. */
    public const FINALIZED = [
        'demo-cup-1' => ['كأس الألغاز - الجولة 1', 'C01', 25, ['reem' => 7, 'shatha' => 9, 'sara' => 10, 'anas' => 11, 'yousef' => 12, 'malak' => 14, 'wisam' => 15, 'omar' => 16, 'layan' => 22, 'noureddine' => 30, 'eman' => 35, 'jana' => null]],
        'demo-cup-2' => ['كأس الألغاز - الجولة 2', 'C02', 18, ['sara' => 6, 'malak' => 8, 'wisam' => 9, 'omar' => 14, 'layan' => 15, 'reem' => 16, 'yousef' => 19, 'shatha' => 20, 'anas' => 22, 'noureddine' => 26, 'eman' => 30, 'yaser' => 33, 'jana' => null]],
        'demo-cup-3' => ['كأس الألغاز - الجولة 3', 'C03', 11, ['reem' => 8, 'yousef' => 9, 'omar' => 10, 'sara' => 11, 'malak' => 12, 'layan' => 13, 'noureddine' => 14, 'shatha' => 15, 'wisam' => 18, 'anas' => 20, 'eman' => 24, 'yaser' => 27, 'basel' => 30, 'raghad' => 33]],
        'demo-cup-4' => ['كأس الألغاز - الجولة 4', 'C04', 5, ['yousef' => 7, 'sara' => 8, 'omar' => 9, 'malak' => 10, 'layan' => 11, 'wisam' => 12, 'reem' => 13, 'jana' => 14, 'shatha' => 15, 'anas' => 18, 'noureddine' => 20, 'eman' => 22]],
    ];

    /** [الحدث => مفتاح] ← الأحداث التي تحمل قواعد جوائز E18 (تُنشأ قبل بدايتها فتقبلها القاعدة). */
    public const REWARD_EVENT = 'demo-cup-3';

    public function run(): void
    {
        DemoPuzzleCatalog::ensure();

        $this->quiet(fn () => $this->syncQueue(function () {
            foreach (self::FINALIZED as $slug => [$title, $puzzleKey, $startedDaysAgo, $players]) {
                $this->finalizedEvent($slug, $title, $puzzleKey, $startedDaysAgo, $players);
            }

            $this->liveEvent();
            $this->upcomingEvent();
            $this->cancelledEvent();
            $this->friendChallenges();
        }));

        $this->say('منافسات: 4 جولات معتمَدة (ترتيب+جوائز+فرق) + حيّة + قادمة + ملغاة + تحدّيات أصدقاء (فوز/خسارة/تعادل/جارٍ/وارد).');
    }

    // ---------------------------------------------------------------- الأحداث

    protected function event(string $slug, string $title, string $puzzleKey, CarbonInterface $startsAt, CarbonInterface $endsAt, bool $featured = false): CompetitiveEvent
    {
        return CompetitiveEvent::query()->where('slug', $slug)->first() ?? $this->at($startsAt->copy()->subDays(3), function () use ($slug, $title, $puzzleKey, $startsAt, $endsAt, $featured) {
            $event = new CompetitiveEvent([
                'title' => $title, 'slug' => $slug, 'description' => 'منافسة تجريبية: حل الأحجية الأسرع والأدق. محاولة واحدة، والترتيب بالنقاط ثم الزمن.',
                'puzzle_id' => DemoPuzzleCatalog::puzzle($puzzleKey)->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_featured' => $featured,
            ]);
            $event->forceFill(['status' => CompetitiveEvent::STATUS_PUBLISHED, 'published_at' => now()])->save();

            return $event->refresh();
        });
    }

    protected function finalizedEvent(string $slug, string $title, string $puzzleKey, int $startedDaysAgo, array $players): void
    {
        $startsAt = now()->subDays($startedDaysAgo)->setTime(18, 0);
        $event = $this->event($slug, $title, $puzzleKey, $startsAt, $startsAt->copy()->addDay(), $slug === 'demo-cup-4');

        if ($event->status === CompetitiveEvent::STATUS_COMPLETED) {
            return;                                                                    // Idempotent
        }

        $slug === self::REWARD_EVENT && $this->rewardRules($event);

        $i = 0;

        foreach ($players as $key => $seconds) {
            $this->play($event, $this->user($key), $startsAt->copy()->addMinutes(10 + 9 * $i++), $seconds, $puzzleKey);
        }

        $this->at($startsAt->copy()->addDay()->addHour(), fn () => app(CompetitiveEventFinalizer::class)->finalize($event->refresh()));
    }

    /** تسجيل ← بدء ← إرسال: المدة = الفرق بين زمنَي الخادم المحاكيين (لا رقم مكتوب بالنتيجة). $seconds null = إجابة خاطئة. */
    protected function play(CompetitiveEvent $event, User $user, CarbonInterface $startAt, ?int $seconds, string $puzzleKey): void
    {
        $service = app(CompetitiveEventService::class);

        if ($service->participant($user, $event) !== null) {
            return;
        }

        $this->at($startAt->copy()->subMinutes(2), fn () => $service->register($user, $event));

        if ($seconds === false) {
            return;                                                                    // مسجَّل ولم يلعب بعد
        }

        $this->at($startAt, fn () => $service->start($user, $event));
        $this->at($startAt->copy()->addSeconds($seconds ?? 20), fn () => $service->submit($user, $event, ['answer' => $seconds === null ? 'إجابة خاطئة' : DemoPuzzleCatalog::answer($puzzleKey)]));
    }

    protected function rewardRules(CompetitiveEvent $event): void
    {
        if (CompetitiveRewardRule::query()->where('competitive_event_id', $event->id)->exists()) {
            return;
        }

        $event = $event->refresh();
        $rmd = Currency::query()->where('internal_key', 'ramadan-2027-demo')->firstOrFail();

        $this->at($event->starts_at->copy()->subDays(2), function () use ($event, $rmd) {
            foreach ([
                ['kind' => 'rank', 'min_rank' => 1, 'max_rank' => 1, 'reward_type' => 'currency', 'currency_id' => $rmd->id, 'amount' => 150, 'sort_order' => 1],
                ['kind' => 'rank', 'min_rank' => 2, 'max_rank' => 3, 'reward_type' => 'currency', 'currency_id' => $rmd->id, 'amount' => 60, 'sort_order' => 2],
                ['kind' => 'participation', 'reward_type' => 'xp', 'amount' => 15, 'sort_order' => 3],
            ] as $rule) {
                CompetitiveRewardRule::create($rule + ['competitive_event_id' => $event->id, 'is_active' => true]);
            }
        });
    }

    protected function liveEvent(): void
    {
        $startsAt = now()->subHours(3);
        $event = $this->event('demo-live', 'كأس الألغاز - الجولة الحيّة', 'C05', $startsAt, now()->addHours(21), true);

        // سارة وريم لعبتا؛ يوسف وعمر وليان مسجَّلون ولم يلعبوا (يوسف يجرّب اللعب الآن).
        $this->play($event, $this->user('sara'), $startsAt->copy()->addMinutes(20), 9, 'C05');
        $this->play($event, $this->user('reem'), $startsAt->copy()->addMinutes(55), 11, 'C05');

        foreach (['yousef', 'omar', 'layan'] as $key) {
            $service = app(CompetitiveEventService::class);
            $user = $this->user($key);
            $service->participant($user, $event) === null && $this->at($startsAt->copy()->addMinutes(90), fn () => $service->register($user, $event));
        }
    }

    protected function upcomingEvent(): void
    {
        $event = $this->event('demo-upcoming', 'كأس الألغاز - الجولة القادمة', 'C11', now()->addDays(2)->setTime(18, 0), now()->addDays(3)->setTime(18, 0));

        foreach (['omar', 'noureddine'] as $key) {
            $service = app(CompetitiveEventService::class);
            $user = $this->user($key);
            $service->participant($user, $event) === null && $service->register($user, $event);
        }
    }

    protected function cancelledEvent(): void
    {
        $event = $this->event('demo-cancelled', 'كأس الألغاز - جولة ملغاة', 'C12', now()->addDays(9)->setTime(18, 0), now()->addDays(10)->setTime(18, 0));

        if ($event->status !== CompetitiveEvent::STATUS_CANCELLED) {
            app(CompetitiveEventAdminService::class)->cancel($event, User::query()->where('email', 'admin@ahjiyat.app')->firstOrFail());
        }
    }

    // ---------------------------------------------------------------- تحدّيات الأصدقاء (ليوسف)

    protected function friendChallenges(): void
    {
        $service = app(FriendChallengeService::class);
        $yousef = $this->user('yousef');

        // [الخصم، أحجية، قبل كم يومًا، زمن يوسف، زمن الخصم] ← فوز، خسارة، تعادل (زمنان متساويان).
        foreach ([['layan', 'C06', 6, 8, 12], ['yaser', 'C07', 4, 20, 9], ['sara', 'C08', 3, 15, 15]] as [$opponent, $puzzleKey, $days, $mine, $theirs]) {
            $this->completedChallenge($service, $yousef, $this->user($opponent), $puzzleKey, now()->subDays($days)->setTime(20, 0), $mine, $theirs);
        }

        // جارٍ: يوسف ونور الدين، لعب نور الدين فقط (ليوسف دور اللعب).
        $other = $this->user('noureddine');

        if (! $this->exists($yousef, $other, 'C09')) {
            $start = now()->subHours(20);
            $challenge = $this->at($start, fn () => $service->create($yousef, $other, DemoPuzzleCatalog::puzzle('C09')));
            $this->at($start->copy()->addHour(), fn () => $service->accept($other, $challenge));
            $this->at($start->copy()->addHours(2), fn () => $service->start($other, $challenge));
            $this->at($start->copy()->addHours(2)->addSeconds(11), fn () => $service->submit($other, $challenge->refresh(), ['answer' => DemoPuzzleCatalog::answer('C09')]));
        }

        // وارد معلّق: سارة تتحدّى يوسف.
        $sara = $this->user('sara');

        if (! $this->exists($sara, $yousef, 'C10')) {
            $this->at(now()->subHours(3), fn () => $service->create($sara, $yousef, DemoPuzzleCatalog::puzzle('C10')));
        }
    }

    protected function completedChallenge(FriendChallengeService $service, User $a, User $b, string $puzzleKey, CarbonInterface $start, int $aSeconds, int $bSeconds): void
    {
        if ($this->exists($a, $b, $puzzleKey)) {
            return;
        }

        $challenge = $this->at($start, fn () => $service->create($a, $b, DemoPuzzleCatalog::puzzle($puzzleKey)));
        $this->at($start->copy()->addMinutes(30), fn () => $service->accept($b, $challenge));

        foreach ([[$a, $aSeconds, 60], [$b, $bSeconds, 120]] as [$user, $seconds, $offset]) {
            $this->at($start->copy()->addMinutes($offset), fn () => $service->start($user, $challenge->refresh()));
            $this->at($start->copy()->addMinutes($offset)->addSeconds($seconds), fn () => $service->submit($user, $challenge->refresh(), ['answer' => DemoPuzzleCatalog::answer($puzzleKey)]));
        }
    }

    protected function exists(User $a, User $b, string $puzzleKey): bool
    {
        return FriendChallenge::query()->where('puzzle_id', DemoPuzzleCatalog::puzzle($puzzleKey)->id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('challenger_id', $a->id)->where('opponent_id', $b->id))->orWhere(fn ($w) => $w->where('challenger_id', $b->id)->where('opponent_id', $a->id)))->exists();
    }
}
