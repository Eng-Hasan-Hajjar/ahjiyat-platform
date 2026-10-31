<?php

namespace App\Services\AiAssistant\Tools;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E45 - لقطة تقدّم مستخدم في حملة، قراءة فقط وباستعلامين مجمَّعين: عدد الخطوات الكلي والمكتمل منها + علامة إكمال الحملة.
 * لا تُرجع هوية الخطوات ولا أحجياتها ولا ردود المستخدم (response_payload)، فقط أعدادًا.
 */
final class CampaignSnapshot
{
    /** @return array{total_steps: int, completed_steps: int, percent: int, campaign_completed: bool} */
    public static function for(User $user, Campaign $campaign): array
    {
        $total = (int) DB::table('campaign_steps as s')
            ->join('campaign_gates as g', 'g.id', '=', 's.campaign_gate_id')
            ->join('campaign_stages as st', 'st.id', '=', 'g.campaign_stage_id')
            ->where('st.campaign_id', $campaign->getKey())->count();

        $done = (int) DB::table('user_campaign_progress as p')
            ->join('campaign_steps as s', 's.id', '=', 'p.campaign_step_id')
            ->join('campaign_gates as g', 'g.id', '=', 's.campaign_gate_id')
            ->join('campaign_stages as st', 'st.id', '=', 'g.campaign_stage_id')
            ->where('st.campaign_id', $campaign->getKey())->where('p.user_id', $user->getKey())->whereNotNull('p.completed_at')->count();

        $completed = DB::table('user_campaign_completions')->where('user_id', $user->getKey())->where('campaign_id', $campaign->getKey())->exists();

        return [
            'total_steps' => $total,
            'completed_steps' => min($done, $total),
            'percent' => $total > 0 ? (int) round(min($done, $total) / $total * 100) : 0,
            'campaign_completed' => $completed,
        ];
    }
}
