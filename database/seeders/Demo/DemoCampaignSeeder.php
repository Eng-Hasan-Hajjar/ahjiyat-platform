<?php

namespace Database\Seeders\Demo;

use App\GameEngine\Support\AttemptContext;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\CampaignNarrativeService;
use App\Services\CampaignProgressService;
use App\Services\CampaignPuzzleService;
use App\Services\CampaignReflectionService;
use App\Services\Engagement\StreakService;
use App\Services\GameSessionService;
use Illuminate\Database\Seeder;

/**
 * تقدّم الحملة (C): كل خطوة تُنجَز **بالخدمة الرسمية لنوعها** (سرد، تأمل، أحجية نصية، جلسة فروقات، ذاكرة) بزمن محاكى؛ فالإكمال والفتح والقفل والبوابات والمراحل والإكمال النهائي
 * كلها **مشتقة** من بيانات حقيقية ولا علم مكتوب يدويًا. إجابات الأحجيات النصية تُقرأ من بذرة الموسم نفسها (الإجابات تُخزَّن مُجزَّأة بقاعدة البيانات).
 *  سارة: الحملة كاملة (22 خطوة) | يوسف: قيد التنفيذ (الخطوات 1-6: المرحلة 1 بوابة 1 مكتملة وبوابة 2 جارية) | ريم: بداية (الخطوات 1-3 = بوابة 1) | غيرهم: بلا تقدّم.
 */
class DemoCampaignSeeder extends Seeder
{
    use DemoSupport;

    public const CAMPAIGN_SLUG = 'aseel-season-01';

    /** عدد الخطوات المنجزة + بداية الإنجاز (قبل كم يومًا). */
    public const PLAN = ['sara' => [22, 12], 'yousef' => [6, 5], 'reem' => [3, 3]];

    public function run(): void
    {
        $campaign = Campaign::query()->where('slug', self::CAMPAIGN_SLUG)->first();

        if ($campaign === null) {
            $this->say('حملة أصيل غير موجودة: تخطّي تقدّم الحملات (شغّل AseelSeasonSeeder).');

            return;
        }

        $this->repairMemoryPuzzle();
        $steps = $this->orderedSteps($campaign);
        $answers = $this->answerMap();

        $this->quiet(function () use ($steps, $answers) {
            foreach (self::PLAN as $key => [$count, $startDaysAgo]) {
                $user = $this->user($key);
                $progress = app(CampaignProgressService::class);
                $minute = 0;

                foreach ($steps->take($count) as $step) {
                    if ($progress->isStepCompleted($user, $step)) {
                        continue;                                                    // Idempotent
                    }

                    try {
                        $this->at(now()->subDays($startDaysAgo)->setTime(10, 0)->addMinutes($minute += 7), fn () => $this->complete($user, $step, $answers));
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("تعذّر إنجاز خطوة الحملة #{$step->id} ({$step->kind}) للمستخدم {$key}: ".$e->getMessage(), 0, $e);
                    }
                }
            }
        });

        // خطوات الحملة تُسجَّل بتواريخ أقدم من سجل الأحجيات المبذور قبلها، فتُربك عدّاد السلسلة التراكمي (يقبل نشاطًا مرتّبًا زمنيًا فقط).
        // الحل الرسمي: إعادة حسابه من سجل المحاولات الصحيحة كله (StreakService::recalculateFromHistory) فتتسق السلسلة مهما كان ترتيب البذر.
        foreach (array_keys(self::PLAN) as $key) {
            app(StreakService::class)->recalculateFromHistory($this->user($key));
        }

        $this->say('حملات: سارة مكتملة، يوسف قيد التنفيذ، ريم بداية: كلها بالخدمات الرسمية (التقدّم مشتق).');
    }

    /** @return \Illuminate\Support\Collection<int, CampaignStep> بترتيب المرحلة ثم البوابة ثم الخطوة. */
    protected function orderedSteps(Campaign $campaign)
    {
        return CampaignStep::query()->join('campaign_gates as g', 'g.id', '=', 'campaign_steps.campaign_gate_id')->join('campaign_stages as s', 's.id', '=', 'g.campaign_stage_id')
            ->where('s.campaign_id', $campaign->id)->orderBy('s.sort_order')->orderBy('g.sort_order')->orderBy('campaign_steps.sort_order')
            ->select('campaign_steps.*')->with(['puzzle', 'gate'])->get();
    }

    protected function complete(User $user, CampaignStep $step, array $answers): void
    {
        match ($step->kind) {
            CampaignStep::KIND_NARRATIVE => $this->narrative($user, $step),
            CampaignStep::KIND_REFLECTION => app(CampaignReflectionService::class)->submit($user, $step, 'تأمل تجريبي: ما فهمته من هذه المرحلة أن التفاصيل الصغيرة تغيّر الحكاية كلها.'),
            CampaignStep::KIND_PUZZLE => $this->puzzle($user, $step, $answers),
        };
    }

    protected function narrative(User $user, CampaignStep $step): void
    {
        $service = app(CampaignNarrativeService::class);
        $service->markStarted($user, $step);
        $this->at(now()->addMinutes(2), fn () => $service->complete($user, $step));
    }

    protected function puzzle(User $user, CampaignStep $step, array $answers): void
    {
        $puzzle = $step->puzzle;

        if ($puzzle->game_type === 'spot_difference') {
            $sessions = app(GameSessionService::class);
            $session = $sessions->start($user, $puzzle, AttemptContext::campaignStep($step->id));

            foreach ((array) ($puzzle->solution_data['hotspots'] ?? []) as $spot) {
                $sessions->reveal($session, (float) $spot['x'], (float) $spot['y']);
            }

            return;
        }

        if ($puzzle->game_type === 'memory') {
            $cards = collect($puzzle->game_config['cards'] ?? [])->groupBy('face');
            $matches = $cards->map(fn ($pair) => $pair->pluck('id')->take(2)->values()->all())->values()->all();
            app(CampaignPuzzleService::class)->attempt($user, $step, '', false, ['matches' => $matches]);

            return;
        }

        app(CampaignPuzzleService::class)->attempt($user, $step, $answers[$puzzle->title] ?? throw new \RuntimeException("لا إجابة معروفة لأحجية الحملة: {$puzzle->title}"), false, []);
    }

    /**
     * اكتشاف: AseelSeasonSeeder يعرّف لعبة الذاكرة بمفتاح 'pairs' بينما محرّك اللعبة يقرأ 'faces' ويولّد منه 'cards'، فتُخزَّن الأحجية بلا بطاقات (cards فارغة) وتصير
     * خطوة "اختبار الذاكرة" غير قابلة للحل (لا حملة تكتمل). هنا نُصلح **صف الديمو فقط** بنفس الإيموجي من مصدر البذرة (عبر نموذج الأحجية ليولّد المحرّك البطاقات). Idempotent.
     * (الإصلاح الإنتاجي المقترح: تغيير 'pairs' إلى 'faces' بمصدر AseelSeasonSeeder.)
     */
    protected function repairMemoryPuzzle(): void
    {
        $puzzle = Puzzle::query()->where('game_type', 'memory')->where('title', 'ذاكرة أصيل')->first();

        if ($puzzle === null || ! empty($puzzle->game_config['cards'] ?? [])) {
            return;
        }

        $source = file_get_contents(database_path('seeders/Seasons/AseelSeasonSeeder.php'));
        preg_match("/'pairs'\s*=>\s*\[(.*?)\]/s", $source, $block);
        preg_match_all("/'([^']+)'/u", $block[1] ?? '', $faces);

        if ($faces[1] !== []) {
            $puzzle->game_config = ['faces' => $faces[1]];
            $puzzle->save();
        }
    }

    /** عنوان الأحجية => الإجابة، من مصدر بذرة الموسم نفسه (لا نخترع إجابات). */
    protected function answerMap(): array
    {
        $source = file_get_contents(database_path('seeders/Seasons/AseelSeasonSeeder.php'));
        $map = [];

        foreach (array_slice(explode('$this->puzzle(', $source), 1) as $chunk) {
            $call = explode(']);', $chunk)[0];

            if (preg_match("/^\s*'((?:[^'\\\\]|\\\\.)*)'/s", $call, $title) && preg_match("/'answer_raw'\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/s", $call, $answer)) {
                $map[stripcslashes($title[1])] = stripcslashes($answer[1]);
            }
        }

        return $map;
    }
}
