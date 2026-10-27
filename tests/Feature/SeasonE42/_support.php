<?php

use App\Models\Campaign;
use App\Models\CampaignGate;
use App\Models\CampaignStage;
use App\Models\CampaignStep;
use App\Models\Season;
use App\Models\SeasonPhase;
use App\Models\User;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonCache;
use App\Services\Seasons\SeasonHubService;
use App\Services\Seasons\SeasonProcessor;
use App\Services\SeasonLifecycleService;
use App\Services\SeasonReadinessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| E42 - مساعدات مشتركة لاختبارات تطوّر الموسم. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync؛ لا شبكة. الزمن المثبَّت: now = 2026-12-10 12:00.
| الموسم يُبنى بالمصانع (سلطة تقدّم الحملة تبقى للحملة) ثم المراحل/الربط/الإعلانات تمرّ بـSeasonBuilderService الرسمي.
*/

require_once __DIR__.'/../AhjiyatPassE40/_support.php';

if (! function_exists('e42_seed')) {
    function e42_seed(): void
    {
        e40_seed();
        SeasonCache::flush();
        config([
            'seasons.hub.cache_seconds' => 0,
            'ahjiyat_pass.enabled' => true,
        ]);
    }

    function e42_now(): Carbon
    {
        return Carbon::parse('2026-12-10 12:00:00');
    }

    function e42_admin(): User
    {
        return e40_admin();
    }

    /** مشرف عادي بلا أي صلاحية موسم. */
    function e42_plain_staff(): User
    {
        return e40_plain_staff();
    }

    /** موظف بصلاحيات محدّدة فقط (من seasons.*). @param list<string> $permissions */
    function e42_staff(array $permissions): User
    {
        return e40_staff_with($permissions);
    }

    function e42_builder(): SeasonBuilderService
    {
        return app(SeasonBuilderService::class);
    }

    function e42_life(): SeasonLifecycleService
    {
        return app(SeasonLifecycleService::class);
    }

    function e42_hub(): SeasonHubService
    {
        return app(SeasonHubService::class);
    }

    function e42_ready(): SeasonReadinessService
    {
        return app(SeasonReadinessService::class);
    }

    function e42_processor(): SeasonProcessor
    {
        return app(SeasonProcessor::class);
    }

    /** حملة بمرحلة ← بوابة ← خطوة سردية (جاهزة للنشر). */
    function e42_campaign(array $attrs = [], bool $withStructure = true): Campaign
    {
        $campaign = Campaign::factory()->create($attrs + ['is_active' => true, 'title' => 'حملة '.fake()->unique()->numerify('####')]);

        if ($withStructure) {
            $stage = CampaignStage::factory()->create(['campaign_id' => $campaign->id]);
            $gate = CampaignGate::factory()->create(['campaign_stage_id' => $stage->id]);
            CampaignStep::factory()->create(['campaign_gate_id' => $gate->id]);
        }

        return $campaign;
    }

    /**
     * يبني موسمًا. $o: published (true) | window: [starts, ends] صريحة للموسم | campaign: سمات الحملة | season: سمات الموسم إضافية | structure (true)
     * افتراضيًا: حملة نشطة بلا تواريخ وموسم منشور بلا نافذة صريحة (السلوك القديم حرفيًا).
     */
    function e42_season(array $o = []): Season
    {
        $campaign = e42_campaign($o['campaign'] ?? [], $o['structure'] ?? true);
        $attrs = ($o['season'] ?? []) + ['campaign_id' => $campaign->id, 'is_published' => $o['published'] ?? true];

        if (isset($o['window'])) {
            $attrs['starts_at'] = $o['window'][0];
            $attrs['ends_at'] = $o['window'][1];
        }

        return Season::factory()->create($attrs);
    }

    /** موسم مباشر بنافذة صريحة: بدأ قبل 10 أيام وينتهي بعد 20 يومًا (الزمن المثبَّت 2026-12-10). */
    function e42_live(array $o = []): Season
    {
        return e42_season($o + ['window' => ['2026-11-30 00:00:00', '2026-12-30 00:00:00']]);
    }

    /** مرحلة بالخدمة الرسمية. */
    function e42_phase(Season $season, string $name, string $start, string $end, array $extra = [], ?User $actor = null): SeasonPhase
    {
        return e42_builder()->addPhase($actor ?? e42_admin(), $season, ['name' => $name, 'starts_at' => $start, 'ends_at' => $end] + $extra);
    }

    /** يعدّ استعلامات قاعدة البيانات داخل الإغلاق. */
    function e42_queries(Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** استدعاء خدمة يُتوقَّع أن تُلقي SeasonException ويُرجع سببها. */
    function e42_reason(Closure $fn): ?string
    {
        try {
            $fn();
        } catch (\App\Services\Seasons\SeasonException $e) {
            return $e->reason;
        }

        return null;
    }

    /** نص الشيفرة بلا تعليقات (للفحوص الساكنة). */
    function e42_code_only(string $path): string
    {
        $code = file_get_contents(base_path($path));
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** يجعل مستخدمًا «مشاركًا» في الحملة الأساسية للموسم (تقدّم سردي مكتمل فعلي). */
    function e42_join(Season $season, array $userAttrs = []): User
    {
        $user = e16User($userAttrs + ['email_verified_at' => now()]);
        $step = CampaignStep::query()
            ->whereIn('campaign_gate_id', CampaignGate::query()->whereIn('campaign_stage_id', CampaignStage::query()->where('campaign_id', $season->campaign_id)->select('id'))->select('id'))
            ->orderBy('id')->firstOrFail();
        \App\Models\UserCampaignProgress::create(['user_id' => $user->id, 'campaign_step_id' => $step->id, 'started_at' => now(), 'completed_at' => now()]);

        return $user;
    }

    /** إشعارات قاعدة البيانات من نوع معيّن (لمستخدم اختياريًا). */
    function e42_notes(string $type, ?User $user = null)
    {
        return \Illuminate\Notifications\DatabaseNotification::query()->where('type_key', $type)
            ->when($user, fn ($q) => $q->where('notifiable_id', $user->id))->get();
    }
}
