<?php

namespace App\Services\Notifications;

use App\Models\Campaign;
use App\Models\Season;
use App\Models\User;
use App\Services\CampaignLifecycleService;
use App\Services\CampaignProgressPairs;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * تذكيرا "ينتهي قريبًا" (season_ending_soon / campaign_ending_soon) بمنطق واحد مشترك وقراءة فقط: لا يمسّ أي تقدّم/مهمة/سلسلة/
 * مكافأة، يكتب إشعارات فقط. يُستدعى من أمر مجدول ساعيًا.
 *
 * الخط الزمني: campaign.ends_at، وفي E42 نهاية الموسم الصريحة (seasons.ends_at) إن وُجدت — موسم بلا نهاية صريحة يتبع حملته كما كان تمامًا. تعريف "الحملة متاحة الآن" لا يُنسَخ: CampaignLifecycleService::isAvailable
 * (= CampaignProgressService::isCampaignAvailable). SQL يضيّق المرشَّحين زمنيًا فقط.
 *
 * النوع (واحد لكل حملة، لا تكرار بين النوعين): حملة مرتبطة بموسم منشور ← season_ending_soon (تفضيل المواسم، seasons.show)؛ وإلا
 * (مستقلة أو موسمها غير منشور) ← campaign_ending_soon (تفضيل الحملات، campaigns.show).
 *
 * الجمهور (لا Mass Notification): مستخدمون موثَّقون وغير مجمَّدين لديهم تقدّم فعلي في هذه الحملة (CampaignProgressPairs)، ولم
 * يكملوها بحسب user_campaign_completions، ولم يُرسَل لهم التذكير من قبل (المفتاح الدلالي {type}-ending-soon:{user}:{season|campaign}).
 *
 * مكافحة الإزعاج: النوعان "تذكير استعادي" بميزانية E15 اليومية ومعتمدة الأولوية (سلسلة 100 > ينتهي قريبًا 70 > مهام 50):
 * يُحتسَب هنا ما أولويته ≥ 70 فقط، فتذكير المهام لا يحجبه، ويحجبه تحذير السلسلة وتذكير ينتهي-قريبًا آخر. وهو يحجب بدوره
 * تذكير المهام لأن مرحلة المهام تعدّ كل التذكيرات. المفتاح الشامل وتفضيل المستخدم يُفحصان داخل NotificationDispatcher.
 */
class LifecycleReminderService
{
    public function __construct(
        protected CampaignLifecycleService $campaigns,
        protected ReEngagementPolicy $policy,
    ) {}

    public function windowHours(): int
    {
        return max(1, (int) config('player_notifications.ending_soon_window_hours', 24));
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $stats = ['campaigns' => 0, 'candidates' => 0, 'completed' => 0, 'already' => 0, 'over_budget' => 0, 'created' => 0, 'suppressed' => 0, 'failed' => 0];
        $now = now();
        $until = $now->copy()->addHours($this->windowHours());

        Campaign::query()
            ->where('is_active', true)->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)->where('ends_at', '<=', $until)
            ->with('season')
            ->chunkById(50, function ($campaigns) use (&$stats, $now, $until) {
                foreach ($campaigns as $campaign) {
                    if (! $this->campaigns->isAvailable($campaign)) {
                        continue;
                    }

                    // E42: موسم منشور بنهاية صريحة داخل النافذة يُذكَّر به في runExplicitSeasons (بنهاية الموسم لا الحملة) فلا نكرّره هنا.
                    $owner = $campaign->season;

                    if ($owner !== null && $owner->is_published && $owner->ends_at !== null && $owner->ends_at->gt($now) && $owner->ends_at->lte($until)) {
                        continue;
                    }

                    $stats['campaigns']++;
                    $this->remind($campaign, $stats);
                }
            });

        $this->runExplicitSeasons($now, $until, $stats);

        return $stats;
    }

    /**
     * E42: مواسم بنهاية صريحة تقع في النافذة. التذكير هنا بنهاية **الموسم** (نوع season_ending_soon بالمفتاح الدلالي نفسه فلا تكرار مع مسار الحملة)،
     * بشرط أن يكون الموسم ظاهرًا ومباشرًا وحملته الأساسية متاحة للعب فعلًا (لا نذكّر بما لا يمكن إكماله).
     *
     * @param  array<string, int>  $stats
     */
    protected function runExplicitSeasons($now, $until, array &$stats): void
    {
        $lifecycle = app(\App\Services\SeasonLifecycleService::class);

        Season::query()->publiclyVisible($now)
            ->whereNotNull('seasons.ends_at')->where('seasons.ends_at', '>', $now)->where('seasons.ends_at', '<=', $until)
            ->where(fn ($q) => $q->whereNull('seasons.archive_at')->orWhere('seasons.archive_at', '>', $now))
            ->with('campaign')
            ->chunkById(50, function ($seasons) use ($lifecycle, &$stats) {
                foreach ($seasons as $season) {
                    if ($season->campaign === null || ! $lifecycle->isLive($season) || ! $this->campaigns->isAvailable($season->campaign)) {
                        continue;
                    }

                    $stats['campaigns']++;
                    $this->remind($season->campaign, $stats, $season);
                }
            });
    }

    /** @param  array<string, int>  $stats */
    protected function remind(Campaign $campaign, array &$stats, ?Season $explicitSeason = null): void
    {
        $season = $explicitSeason ?? $campaign->season;
        $viaSeason = $explicitSeason !== null
            || ($season !== null && $season->is_published && $season->ends_at === null && ($season->announcement_at === null || $season->announcement_at->lte(now())));

        $type = $viaSeason ? NotificationType::SeasonEndingSoon : NotificationType::CampaignEndingSoon;
        $prefix = $viaSeason ? 'season-ending-soon' : 'campaign-ending-soon';
        $subjectId = $viaSeason ? $season->id : $campaign->id;
        $route = $viaSeason ? 'seasons.show' : 'campaigns.show';
        $actionParams = $viaSeason ? ['season' => $season->slug] : ['campaign' => $campaign->slug];
        $params = ['name' => $campaign->title, 'hours' => $this->windowHours()];
        $refs = ['campaign_id' => $campaign->id] + ($viaSeason ? ['season_id' => $season->id] : []);
        $max = $this->policy->maxPerDay();

        CampaignProgressPairs::usersForCampaignQuery($campaign->id)
            ->where('is_frozen', false)->whereNotNull('email_verified_at')
            ->chunkById(max(1, (int) config('player_notifications.chunk_size', 200)), function ($users) use (&$stats, $campaign, $type, $prefix, $subjectId, $route, $actionParams, $params, $refs, $max) {
                $ids = $users->pluck('id')->all();

                $completed = array_flip(DB::table('user_campaign_completions')->where('campaign_id', $campaign->id)->whereIn('user_id', $ids)->pluck('user_id')->all());

                $sent = array_flip(DatabaseNotification::query()
                    ->where('notifiable_type', (new User)->getMorphClass())
                    ->where('type_key', $type->value)
                    ->whereIn('idempotency_key', array_map(fn ($id) => "{$prefix}:{$id}:{$subjectId}", $ids))
                    ->pluck('notifiable_id')->all());

                $used = $this->policy->usedToday($ids, $type);

                foreach ($users as $user) {
                    $stats['candidates']++;

                    if (isset($completed[$user->id])) {
                        $stats['completed']++;
                    } elseif (isset($sent[$user->id])) {
                        $stats['already']++;
                    } elseif (($used[$user->id] ?? 0) >= $max) {
                        $stats['over_budget']++;
                    } else {
                        $this->send($user, $type, $params, "{$prefix}:{$user->id}:{$subjectId}", $refs, $actionParams, $route, $stats);
                    }
                }
            });
    }

    /** @param  array<string, int>  $stats */
    protected function send(User $user, NotificationType $type, array $params, string $key, array $refs, array $actionParams, string $route, array &$stats): void
    {
        try {
            $result = app(NotificationDispatcher::class)->dispatch($user, $type, $params, $key, $refs, $actionParams, $route);

            match ($result) {
                DispatchResult::Created => $stats['created']++,
                DispatchResult::Suppressed => $stats['suppressed']++,
                DispatchResult::Duplicate => $stats['already']++,
                DispatchResult::Failed => $stats['failed']++,
            };
        } catch (\Throwable $e) {
            report($e); // فشل الإشعار لا يمسّ أي شيء آخر (الأمر قراءة فقط) ولا يوقف بقية المستخدمين.
            $stats['failed']++;
        }
    }
}
