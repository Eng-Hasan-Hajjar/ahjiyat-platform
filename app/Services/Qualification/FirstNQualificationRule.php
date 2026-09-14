<?php

namespace App\Services\Qualification;

use App\Models\CampaignGate;
use App\Models\CampaignGateQualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * أول Rule فعلية - "أول N شخص يكملون هذه البوابة يتأهلون". Race-safe عبر
 * قفل صف الـGate نفسه (موجود دائماً، بعكس صف Qualification الذي قد لا
 * يكون موجوداً بعد للمستخدم الأول) كنقطة تسلسل وحيدة، ثم count()+create()
 * داخل نفس القفل. القيود الفريدة unique(gate_id, user_id) وunique(gate_id,
 * rank) بقاعدة البيانات (من C1) تبقى شبكة أمان ثانية مستقلة عن القفل.
 */
class FirstNQualificationRule implements QualificationRule
{
    public function qualify(User $user, CampaignGate $gate): void
    {
        DB::transaction(function () use ($user, $gate) {
            $lockedGate = CampaignGate::whereKey($gate->id)->lockForUpdate()->first();

            if ($this->isQualified($user, $lockedGate)) {
                return; // مؤهَّل مسبقاً - Idempotent: لا صف ثانٍ، لا rank جديد
            }

            $limit = (int) ($lockedGate->qualification_config['limit'] ?? 0);
            $currentCount = CampaignGateQualification::where('campaign_gate_id', $lockedGate->id)->count();

            if ($currentCount >= $limit) {
                return; // انتهت المقاعد - لا خطأ للمستخدم، الـGate تبقى Completed فقط بلا تأهّل
            }

            CampaignGateQualification::create([
                'campaign_gate_id' => $lockedGate->id,
                'user_id' => $user->id,
                'rank' => $currentCount + 1,
                'qualified_at' => now(),
            ]);
        });
    }

    public function isQualified(User $user, CampaignGate $gate): bool
    {
        return CampaignGateQualification::where('campaign_gate_id', $gate->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}