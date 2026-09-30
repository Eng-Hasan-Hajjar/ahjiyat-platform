<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use App\Services\Progression\GameplayProgressionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CampaignReflectionService
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected QualificationService $qualification,
        protected GameplayProgressionService $progression,
    ) {}

    public function submit(User $user, CampaignStep $step, string $response): UserCampaignProgress
    {
        $this->assertReflection($step);
        $this->assertUnlocked($user, $step);

        $minChars = (int) ($step->content['min_chars'] ?? 10);
        $maxChars = (int) ($step->content['max_chars'] ?? 2000);

        $clean = trim(strip_tags($response));
        $length = mb_strlen($clean);

        if ($length < $minChars) {
            throw new \RuntimeException("الرجاء كتابة إجابة لا تقل عن {$minChars} حرفًا.");
        }

        if ($length > $maxChars) {
            throw new \RuntimeException("الإجابة تتجاوز الحد الأقصى المسموح ({$maxChars} حرفًا).");
        }

        $progress = DB::transaction(function () use ($user, $step, $clean) {
            $this->lockUserForWrite($user);

            $existing = UserCampaignProgress::where('user_id', $user->id)
                ->where('campaign_step_id', $step->id)
                ->first();

            if ($existing?->completed_at !== null) {
                throw new \RuntimeException('سبق أن أرسلت إجابتك لهذه الخطوة.');
            }

            if ($existing === null) {
                return UserCampaignProgress::create([
                    'user_id' => $user->id,
                    'campaign_step_id' => $step->id,
                    'started_at' => now(),
                    'completed_at' => now(),
                    'response_payload' => ['text' => $clean],
                ]);
            }

            $existing->update([
                'started_at' => $existing->started_at ?? now(),
                'completed_at' => now(),
                'response_payload' => ['text' => $clean],
            ]);

            return $existing->fresh();
        });

        $this->qualification->afterStepCompletion($user, $step);

        $this->progression->afterCampaignStepCompleted($user, $step, $progress);

        return $progress;
    }

    protected function assertReflection(CampaignStep $step): void
    {
        if ($step->kind !== CampaignStep::KIND_REFLECTION) {
            throw new \RuntimeException('هذه الخطوة ليست من نوع تأمّل.');
        }
    }

    protected function assertUnlocked(User $user, CampaignStep $step): void
    {
        if (! $this->progress->isStepUnlocked($user, $step)) {
            throw new AuthorizationException('هذه الخطوة مقفلة حالياً أو الحملة غير متاحة.');
        }
    }

    protected function lockUserForWrite(User $user): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();
    }
}