<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\User;
use App\Models\UserCampaignProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * أول Write Flow فعلي بمحرك الحملات - مخصَّصة حصراً لخطوات narrative.
 * Puzzle Step لها Workflow مختلف كلياً قادم بـC4 (عبر PuzzleAttemptService/
 * GameSessionService الموجودتين فعلاً) - لا تُدمَج هون أبداً، ولهذا
 * لا توجد Method عامة completeStep() تتعامل مع كل الأنواع.
 *
 * المصدر الوحيد للحقيقة يبقى UserCampaignProgress - لا PuzzleAttempt ولا
 * أي جدول آخر لهذا النوع. CampaignProgressService (C2) هي المرجع الوحيد
 * لمنطق الوصول (Unlock) - لا يُعاد كتابته هون بأي شكل.
 */
class CampaignNarrativeService
{
    public function __construct(protected CampaignProgressService $progress) {}

    /**
     * Idempotent بالكامل: استدعاؤها 10 مرات على نفس (user, step) ينتج صفاً
     * واحداً فقط. لا تُرجع completed_at إلى null أبداً إن كانت موجودة أصلاً.
     */
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

    /**
     * Idempotent بالكامل ومحمية من التزامن (راجع lockUserForWrite). طلبان
     * complete متزامنان لنفس (user, step) ينتجان صفاً واحداً وcompleted_at
     * واحداً منطقياً - لا استثناء يصل للمستخدم الطبيعي.
     */
    public function complete(User $user, CampaignStep $step): UserCampaignProgress
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

    /**
     * المصدر الوحيد لقرار الوصول هو CampaignProgressService::isStepUnlocked()
     * (يشمل بداخله فحص توفر الحملة أصلاً - لا حاجة لتكراره هون). وجود صف
     * UserCampaignProgress قديم/غير منطقي لا يعني أبداً أن الخطوة مفتوحة -
     * هذا الفحص لا يستثني حالة كهذه إطلاقاً.
     */
    protected function assertUnlocked(User $user, CampaignStep $step): void
    {
        if (! $this->progress->isStepUnlocked($user, $step)) {
            throw new AuthorizationException('هذه الخطوة مقفلة حالياً أو الحملة غير متاحة.');
        }
    }

    /**
     * قفل محدود النطاق (صف هذا المستخدم فقط - ليس Global، وليس قفل جدول)
     * يُسلسِل طلبات هذا المستخدم المتزامنة بالذات على أي خطوة، دون التأثير
     * إطلاقاً على مستخدمين آخرين. يُعمل صفّه أصلاً (بعكس صف Progress الذي
     * قد لا يكون موجوداً بعد)، فـlockForUpdate يقفل شيئاً فعلياً دائماً.
     */
    protected function lockUserForWrite(User $user): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();
    }
}