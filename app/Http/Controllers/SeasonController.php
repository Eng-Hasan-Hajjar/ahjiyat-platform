<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Season;
use App\Services\CampaignProgressService;
use App\Services\SeasonLifecycleService;
use App\Services\Seasons\SeasonHubService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * طبقة عرض (Presentation Layer) حول Campaign - لا منطق Progression/Reward/
 * Qualification هون إطلاقاً. اللعب الفعلي يبقى بالكامل عبر مسارات
 * campaigns.steps.* الموجودة - لا تكرار.
 *
 * E42: الفهرس يفصل (مباشر/قادم/منتهٍ) مع أرشيف مُصفَّح، وصفحة الموسم تضيف «مركز الموسم» (مراحل، إعلانات، محتوى مرتبط، مسار، جدول) من SeasonHubService
 * بقراءة فقط. حالة الموسم تأتي من SeasonLifecycleService حصرًا (لا حساب موازٍ هنا).
 */
class SeasonController extends Controller
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected SeasonLifecycleService $lifecycle,
        protected SeasonHubService $hub,
    ) {}

    public function index()
    {
        // E2: مواسم "قريباً"/"انتهى" تبقى ظاهرة بالقائمة العامة (تسويقياً) بدل الاختفاء الكامل؛ is_published + موعد الإعلان يتحكمان بالظهور.
        // E42: الجاري/القادم معًا (سقف أمان)، والمنتهي معاينة قصيرة فقط - الباقي بالأرشيف المُصفَّح.
        $now = now();
        $current = Season::query()->publiclyVisible($now)->notEnded($now)->with('campaign')
            ->orderByDesc('seasons.is_featured')->orderByDesc('seasons.id')->limit(100)->get();
        $pastQuery = fn () => Season::query()->publiclyVisible($now)->ended($now);
        $pastTotal = $pastQuery()->count();
        $past = $pastQuery()->with('campaign')->orderByRaw('COALESCE(seasons.ends_at, campaigns.ends_at) DESC')->orderByDesc('seasons.id')
            ->limit(max(1, (int) config('seasons.index.past_preview', 6)))->get();

        $items = fn ($seasons) => $seasons->map(fn (Season $season) => $this->item($season, $now))->values();
        $all = $items($current);
        $live = $all->where('state', 'live')->values();
        $upcoming = $all->where('state', '!=', 'live')->values();
        $past = $items($past);
        $featured = $live->first(fn ($i) => $i->model->is_featured);
        $seasons = $all->concat($past)->values(); // توافق: مجموعة موحَّدة للمستهلكين القدماء

        return view('seasons.index', compact('seasons', 'live', 'upcoming', 'past', 'pastTotal', 'featured'));
    }

    /** أرشيف المواسم المنتهية/المؤرشفة (عام، مُصفَّح). لكل مستخدم مسجّل ملخص تقدّمه في الحملة الأساسية لكل موسم بصفحة واحدة (استعلام واحد). */
    public function archive()
    {
        $now = now();
        $page = Season::query()->publiclyVisible($now)->ended($now)->with('campaign')
            ->orderByRaw('COALESCE(seasons.ends_at, campaigns.ends_at) DESC')->orderByDesc('seasons.id')
            ->paginate(max(1, (int) config('seasons.index.archive_per_page', 12)));

        $completed = [];

        if ($user = Auth::user()) {
            $completed = DB::table('user_campaign_completions')->where('user_id', $user->getKey())
                ->whereIn('campaign_id', $page->getCollection()->pluck('campaign_id')->all())->pluck('campaign_id')->map(fn ($v) => (int) $v)->all();
        }

        $items = $page->getCollection()->map(function (Season $season) use ($now, $completed) {
            $item = $this->item($season, $now);
            $item->user_completed = Auth::check() ? in_array($season->campaign_id, $completed, true) : null;

            return $item;
        });

        return view('seasons.archive', ['items' => $items, 'page' => $page]);
    }

    public function show(Season $season)
    {
        $user = Auth::user();
        $isAdmin = $user?->isAdmin() ?? false;

        // Admin يستطيع معاينة موسم غير منشور - الجمهور العام لا يرى موسمًا
        // غير منشور (أو لم يحن موعد إعلانه) مهما كان، Server-side دائمًا (لا اعتماد على إخفاء الرابط).
        abort_unless($this->lifecycle->isPubliclyVisible($season) || $isAdmin, 404);

        $season->load('campaign.stages.gates.steps.puzzle');
        $campaign = $season->campaign;

        // E24: صفحة قراءة. لقطة تقدّم واحدة (5 استعلامات) + ربط العلاقات العكسية بدل استعلام/إعادة تحميل لكل خطوة وبوابة ومرحلة.
        if ($user) {
            $this->progress->preloadFor($user, $campaign);
        }

        // الحملة متاحة للعب فقط ما دام الموسم نفسه مباشرًا (نافذته الصريحة إن وُجدت)، وإلا يبقى سلوك الحملة القديم حرفيًا.
        $availability = $this->lifecycle->availability($season);
        $seasonState = $this->lifecycle->state($season);
        $campaignAvailable = $this->progress->isCampaignAvailable($campaign)
            && $availability === SeasonLifecycleService::AVAILABILITY_LIVE
            && $seasonState !== SeasonLifecycleService::STATE_ARCHIVED;
        $currentStep = $user ? $this->progress->currentStepFor($user, $campaign) : null;
        $currentStepMissionNumber = $currentStep ? $this->progress->missionNumberFor($campaign, $currentStep) : null;

        $stagesView = $campaign->stages->sortBy('sort_order')->map(function ($stage) use ($user, $isAdmin) {
            return (object) [
                'model' => $stage,
                'unlocked' => $user && $this->progress->isStageUnlocked($user, $stage),
                'gates' => $stage->gates->sortBy('sort_order')->map(function ($gate) use ($user, $isAdmin) {
                    return (object) [
                        'model' => $gate,
                        'state' => $user ? $this->progress->gateState($user, $gate) : \App\Services\CampaignProgressService::STATE_LOCKED,
                        // Rank فقط إن كان المستخدم مؤهَّلاً فعلياً - null غير ذلك (E2 بند 17).
                        'qualifiedRank' => $user ? $this->progress->qualifiedRankFor($user, $gate) : null,
                        'steps' => $gate->steps->sortBy('sort_order')->map(function ($step) use ($user, $isAdmin) {
                            return (object) [
                                'model' => $step,
                                'state' => $user ? $this->progress->stepState($user, $step) : \App\Services\CampaignProgressService::STATE_LOCKED,
                                // Metadata إدارية فقط - أبداً لا تُمرَّر أو تُعرض لغير Admin
                                'contentStatus' => $isAdmin ? $step->contentStatus() : null,
                            ];
                        })->values(),
                    ];
                })->values(),
            ];
        })->values();

        $totalSteps = $campaign->stages->sum(fn ($s) => $s->gates->sum(fn ($g) => $g->steps->count()));
        $completedSteps = $user
            ? $campaign->stages->sum(fn ($s) => $s->gates->sum(
                fn ($g) => $g->steps->filter(fn ($step) => $this->progress->isStepCompleted($user, $step))->count()
            ))
            : 0;
        $percentage = $totalSteps > 0 ? (int) round($completedSteps / $totalSteps * 100) : 0;

        $availabilityLabel = $this->seasonLabel($season, $availability, $seasonState);
        $unavailableLabel = $campaignAvailable ? $availabilityLabel : (
            $this->progress->isCampaignAvailable($campaign) ? $availabilityLabel : $this->campaignLabel($campaign)
        );

        $hubData = $this->hub->build($season, $user, [
            'percentage' => $percentage,
            'available' => $campaignAvailable,
            'current_step_url' => $currentStep ? route('campaigns.steps.show', [$campaign, $currentStep]) : null,
        ]);

        $windowStart = $hubData['window_start'];
        $windowEnd = $hubData['window_end'];

        return view('seasons.show', compact(
            'season', 'campaign', 'stagesView', 'currentStep', 'currentStepMissionNumber', 'percentage',
            'campaignAvailable', 'availabilityLabel', 'unavailableLabel', 'isAdmin', 'hubData', 'windowStart', 'windowEnd'
        ));
    }

    /** عنصر عرض للفهرس/الأرشيف: النموذج + الحالة بمفردات الواجهة القديمة (live|upcoming|finished|inactive) + وسمها. */
    protected function item(Season $season, $now): object
    {
        $state = $this->lifecycle->state($season, $now);
        $availability = $this->lifecycle->availability($season, $now);
        $bucket = match (true) {
            in_array($state, [SeasonLifecycleService::STATE_ENDED, SeasonLifecycleService::STATE_ARCHIVED], true) => 'finished',
            $state === SeasonLifecycleService::STATE_LIVE => 'live',
            default => $availability === SeasonLifecycleService::AVAILABILITY_INACTIVE ? 'inactive' : 'upcoming',
        };

        return (object) [
            'model' => $season,
            'state' => $bucket,
            'label' => $state === SeasonLifecycleService::STATE_ARCHIVED ? 'في الأرشيف' : ['live' => 'مباشر الآن', 'upcoming' => 'قريباً', 'finished' => 'انتهى', 'inactive' => 'غير متاحة'][$bucket],
            'starts_at' => $this->lifecycle->windowStart($season),
            'ends_at' => $this->lifecycle->windowEnd($season),
        ];
    }

    protected function seasonLabel(Season $season, string $availability, string $state): string
    {
        if ($state === SeasonLifecycleService::STATE_ARCHIVED) {
            return 'في الأرشيف';
        }

        return match ($availability) {
            SeasonLifecycleService::AVAILABILITY_INACTIVE => 'غير متاحة حالياً',
            SeasonLifecycleService::AVAILABILITY_UPCOMING => 'قريباً',
            SeasonLifecycleService::AVAILABILITY_FINISHED => 'انتهى الموسم',
            default => 'مباشر الآن',
        };
    }

    /** وسم حالة الحملة وحدها (يُعرض حين تكون الحملة غير متاحة رغم أن الموسم نفسه جارٍ). */
    protected function campaignLabel(Campaign $campaign): string
    {
        return match (true) {
            ! $campaign->is_active => 'غير متاحة حالياً',
            $campaign->starts_at?->isFuture() => 'قريباً',
            $campaign->ends_at?->isPast() => 'انتهى الموسم',
            default => 'مباشر الآن',
        };
    }
}
