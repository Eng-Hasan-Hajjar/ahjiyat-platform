<?php

namespace App\Console\Commands;

use App\GameEngine\Support\AttemptContext;
use App\Models\User;
use App\Services\StageUnlockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تعبئة رجعية صامتة لـuser_stage_unlocks: يمرّ بدفعات على المستخدمين الذين لديهم تقدّم حملة فعلي، ويحسب المراحل المفتوحة
 * الآن بمصدر الحقيقة الحالي (CampaignProgressService عبر StageUnlockService)، ويُدخلها بلا أي حدث ولا إشعار. Idempotent.
 * يُشغَّل مرة واحدة مباشرة بعد migrate عند نشر الميزة لأول مرة، قبل الاعتماد على إشعارات stage_unlocked.
 */
class BackfillStageUnlocks extends Command
{
    protected $signature = 'campaigns:backfill-stage-unlocks {--chunk=200 : عدد المستخدمين في كل دفعة}';

    protected $description = 'تعبئة رجعية صامتة لسجل فتح المراحل (بلا أحداث ولا إشعارات). يُشغَّل مرة واحدة بعد migrate عند أول نشر. Idempotent.';

    public function handle(StageUnlockService $service): int
    {
        $users = 0;
        $pairs = 0;
        $recorded = 0;
        $campaigns = [];

        User::query()
            ->where(function ($q) {
                $q->whereIn('id', fn ($s) => $s->select('user_id')->from('user_campaign_progress')->whereNotNull('completed_at'))
                    ->orWhereIn('id', fn ($s) => $s->select('user_id')->from('puzzle_attempts')
                        ->where('is_correct', true)->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP));
            })
            ->chunkById(max(1, (int) $this->option('chunk')), function ($batch) use ($service, &$users, &$pairs, &$recorded, &$campaigns) {
                $ids = $batch->pluck('id')->all();

                // أزواج (مستخدم، حملة) لها تقدّم فعلي - بدفعة استعلامات لكل دفعة مستخدمين لا استعلام لكل مستخدم.
                $narrative = DB::table('user_campaign_progress as p')
                    ->join('campaign_steps as st', 'st.id', '=', 'p.campaign_step_id')
                    ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')
                    ->join('campaign_stages as s', 's.id', '=', 'g.campaign_stage_id')
                    ->whereIn('p.user_id', $ids)->whereNotNull('p.completed_at')
                    ->select('p.user_id', 's.campaign_id');

                $byUser = DB::table('puzzle_attempts as pa')
                    ->join('campaign_steps as st', 'st.id', '=', 'pa.context_id')
                    ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')
                    ->join('campaign_stages as s', 's.id', '=', 'g.campaign_stage_id')
                    ->whereIn('pa.user_id', $ids)->where('pa.is_correct', true)->where('pa.context_type', AttemptContext::TYPE_CAMPAIGN_STEP)
                    ->select('pa.user_id', 's.campaign_id')
                    ->union($narrative)
                    ->get()
                    ->groupBy('user_id');

                foreach ($batch as $user) {
                    $users++;

                    foreach ($byUser->get($user->id, collect())->pluck('campaign_id')->unique() as $campaignId) {
                        $campaign = $campaigns[$campaignId] ??= $service->loadCampaign((int) $campaignId);

                        if ($campaign === null) {
                            continue;
                        }

                        $pairs++;
                        $recorded += $service->backfill($user, $campaign);
                    }
                }

                $campaigns = array_slice($campaigns, -50, null, true); // لا نراكم كل الحملات بالذاكرة
            });

        $this->info("مستخدمون: {$users} | أزواج (مستخدم، حملة): {$pairs} | سجلات فتح جديدة: {$recorded} (بصمت: بلا أحداث ولا إشعارات).");

        return self::SUCCESS;
    }
}
