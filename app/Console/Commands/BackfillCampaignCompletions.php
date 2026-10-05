<?php

namespace App\Console\Commands;

use App\Services\CampaignCompletionService;
use App\Services\CampaignProgressPairs;
use Illuminate\Console\Command;

/**
 * تعبئة رجعية صامتة لـuser_campaign_completions: يمرّ بدفعات على المستخدمين الذين لديهم تقدّم حملة فعلي، ويحسب الحملات
 * المكتملة الآن بمصدر الحقيقة الحالي (CampaignProgressService عبر CampaignCompletionService) ويُدخلها بلا أي حدث ولا
 * إشعار. Idempotent. يُشغَّل مرة واحدة مباشرة بعد migrate عند نشر الميزة لأول مرة، قبل الاعتماد على إشعارات campaign_completed.
 */
class BackfillCampaignCompletions extends Command
{
    protected $signature = 'campaigns:backfill-completions {--chunk=200 : عدد المستخدمين في كل دفعة}';

    protected $description = 'تعبئة رجعية صامتة لسجل إكمال الحملات (بلا أحداث ولا إشعارات). يُشغَّل مرة واحدة بعد migrate عند أول نشر. Idempotent.';

    public function handle(CampaignCompletionService $service): int
    {
        $users = 0;
        $pairs = 0;
        $recorded = 0;
        $campaigns = [];

        CampaignProgressPairs::usersQuery()->chunkById(max(1, (int) $this->option('chunk')), function ($batch) use ($service, &$users, &$pairs, &$recorded, &$campaigns) {
            $byUser = CampaignProgressPairs::campaignsByUser($batch->pluck('id')->all());

            foreach ($batch as $user) {
                $users++;

                foreach ($byUser->get($user->id, collect()) as $campaignId) {
                    $campaign = $campaigns[$campaignId] ??= $service->loadCampaign((int) $campaignId);

                    if ($campaign === null) {
                        continue;
                    }

                    $pairs++;
                    $recorded += $service->backfill($user, $campaign) ? 1 : 0;
                }
            }

            $campaigns = array_slice($campaigns, -50, null, true); // لا نراكم كل الحملات بالذاكرة
        });

        $this->info("مستخدمون: {$users} | أزواج (مستخدم، حملة): {$pairs} | سجلات إكمال جديدة: {$recorded} (بصمت: بلا أحداث ولا إشعارات).");

        return self::SUCCESS;
    }
}
