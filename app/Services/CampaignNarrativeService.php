<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CampaignNarrativeService
{
    public function __construct(
        protected CampaignProgressService $progress,
        protected QualificationService $qualification,
    ) {}

    public function markStarted(User $user, CampaignStep $step): UserCampaignProgress
    {
        $this->assertNarrative($step);
        $this->assertUnlocked($user, $step);

        return DB::transaction(function () use ($user, $step) {
            $this->lockUserForWrite($user);

            $existing = $this->findProgress($user, $step);

            if ($existing === null) {
                return UserCampaignProgress::create([
                    'user_id' => $user->id,
                    'campaign_step_id' => $step->id,
                    'started_at' => now(),
                    'completed_at' => null,
                ]);
            }

            if ($existing->started_at === null) {
                $existing->update(['started_at' => now()]);
            }

            return $existing;
        });
    }

    public function complete(User $user, CampaignStep $step): UserCampaignProgress
    {
        $this->assertNarrative($step);
        $this->assertUnlocked($user, $step);

        $progress = DB::transaction(function () use ($user, $step) {
            $this->lockUserForWrite($user);

            $existing = $this->findProgress($user, $step);

            if ($existing === null) {
                return UserCampaignProgress::create([
                    'user_id' => $user->id,
                    'campaign_step_id' => $step->id,
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }

            $updates = [];

            if ($existing->started_at === null) {
                $updates['started_at'] = now();
            }

            if ($existing->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing->fresh();
        });

        // بعد نجاح Commit فعلياً (لا داخل القفل) - Idempotent بذاتها (C6)،
        // آمنة الاستدعاء بلا شرط حتى لو لم يتغيّر شيء بهذا الاستدعاء تحديداً.
        $this->qualification->afterStepCompletion($user, $step);

        return $progress;
    }

    protected function findProgress(User $user, CampaignStep $step): ?UserCampaignProgress
    {
        return UserCampaignProgress::where('user_id', $user->id)
            ->where('campaign_step_id', $step->id)
            ->first();
    }

    protected function assertNarrative(CampaignStep $step): void
    {
        if ($step->kind !== CampaignStep::KIND_NARRATIVE) {
            throw new \RuntimeException('هذه الخطوة ليست من نوع سردي.');
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