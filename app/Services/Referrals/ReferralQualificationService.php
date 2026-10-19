<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E33 (D) - تأهيل الإحالة. **التسجيل وحده لا يؤهّل أبدًا.** شروط التأهيل مجتمعة:
 *  1) بريد المُحال موثَّق وحسابه غير مجمَّد؛
 *  2) مرّ عمر الحساب الأدنى (قابل للضبط؛ لقطة وقت الإسناد)؛
 *  3) إجراء رسمي ذو معنى: حلّ أحجيات **رسمية** صحيحة بعدد القاعدة (first_official_solve = 1 افتراضيًا) — أحجيات المجتمع لا تُحتسب لأن مسار الحل يرفضها أصلًا؛
 *  4) لا إشارات تستدعي التعليق (انظر ReferralRiskService) وإلا pending_review.
 *
 * Idempotent بالتصميم: تحت قفل صف الإحالة لا تُعالَج إلا pending، فتكرار الحدث/تزامنه لا يؤهّل مرتين ولا يضاعف شيئًا. التسليم والإشعارات بعد الـcommit.
 * الحدث: GameplayProgressionService::afterPuzzleSolved ⇒ onMeaningfulAction (معزول بـtry/catch فلا يكسر اللعب أبدًا). أمر referrals:process يستكمل المنتظرة لعمر الحساب.
 * لا تأثير على نقاط اللعب أو الترتيب: هذه الخدمة لا تكتب XP ولا درجات ولا ترتيبًا.
 */
class ReferralQualificationService
{
    public function __construct(
        protected ReferralRiskService $risk,
        protected ReferralRewardService $rewards,
        protected ReferralNotifier $notifier,
        protected ReferralCampaignService $campaigns,
    ) {}

    /** يُستدعى بعد حلّ أحجية رسمية صحيحة. لا يرمي أبدًا. */
    public function onMeaningfulAction(User $user): ?UserReferral
    {
        if (! (bool) config('referrals.enabled')) {
            return null;
        }

        try {
            $id = UserReferral::query()->where('referred_user_id', $user->id)->where('status', UserReferral::STATUS_PENDING)->value('id');

            if ($id === null) {
                return null;
            }

            UserReferral::query()->whereKey($id)->whereNull('action_at')->update(['action_at' => now()]);

            return $this->evaluate((int) $id);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * يقيّم إحالة معلّقة. يعيد الإحالة بعد التقييم (أو null إن لم تكن pending/غير موجودة).
     */
    public function evaluate(int $referralId, ?CarbonInterface $now = null): ?UserReferral
    {
        $now ??= now();
        $outcome = null;

        DB::transaction(function () use ($referralId, $now, &$outcome) {
            $r = UserReferral::query()->whereKey($referralId)->lockForUpdate()->first();

            if ($r === null || $r->status !== UserReferral::STATUS_PENDING || $r->action_at === null) {
                return;
            }

            $referred = User::query()->find($r->referred_user_id);
            $referrer = $r->referrer_user_id !== null ? User::query()->find($r->referrer_user_id) : null;

            if ($referred === null || $referrer === null || (int) $referrer->id === (int) $referred->id) {
                return; // محيل محذوف/إحالة ذاتية دفاعية: لا تأهيل (الإحالة الذاتية لا تُنشأ أصلًا)
            }

            if ($referred->email_verified_at === null || $referred->is_frozen) {
                return;
            }

            $minAge = (int) ($r->min_account_age_hours ?? config('referrals.qualification.min_account_age_hours'));

            if ($referred->created_at === null || $referred->created_at->gt($now->copy()->subHours(max(0, $minAge)))) {
                return; // ينتظر عمر الحساب
            }

            if (! $this->ruleSatisfied($r, $referred)) {
                return;
            }

            $signals = $this->risk->signalsFor($r, $referrer, $referred);

            if ($this->risk->shouldHold($signals)) {
                $r->forceFill(['status' => UserReferral::STATUS_PENDING_REVIEW, 'held_at' => $now, 'risk_signals' => $signals])->save();
                $outcome = 'held';

                return;
            }

            $this->finalize($r, $now, $signals);
            $outcome = 'qualified';
        });

        $fresh = UserReferral::query()->find($referralId);

        if ($outcome === 'qualified' && $fresh !== null) {
            $this->afterQualified($fresh);
        }

        return $fresh;
    }

    /**
     * يختم التأهيل: حالة qualified + لقطة المكافأة. يُستدعى داخل معاملة وتحت قفل صف الإحالة؛ يقفل صف المحيل لتسلسل السقوف.
     * ترتيب الأقفال ثابت (إحالة ثم محيل) فلا deadlock بين إحالتين لنفس المحيل.
     *
     * @param  list<string>  $signals
     */
    public function finalize(UserReferral $r, CarbonInterface $now, array $signals): void
    {
        User::query()->whereKey($r->referrer_user_id)->lockForUpdate()->first();

        $campaign = $r->referral_campaign_id !== null ? ReferralCampaign::query()->find($r->referral_campaign_id) : null;

        $r->forceFill([
            'status' => UserReferral::STATUS_QUALIFIED,
            'qualified_at' => $now,
            'risk_signals' => $signals === [] ? null : $signals,
        ] + $this->rewards->plan($r, $campaign, $now))->save();
    }

    /** بعد الـcommit: تسليم المكافآت ثم إشعار المحيل (بترتيب لا يؤثر على صحة التأهيل). */
    public function afterQualified(UserReferral $r): void
    {
        try {
            $this->rewards->deliver($r->id);
        } catch (Throwable $e) {
            report($e);
        }

        $this->notifier->qualified($r);
    }

    public function requiredSolves(UserReferral $r): int
    {
        $rules = (array) config('referrals.qualification.rules');

        return (int) ($rules[$r->qualification_rule] ?? $rules[config('referrals.qualification.default_rule')] ?? 1);
    }

    protected function ruleSatisfied(UserReferral $r, User $referred): bool
    {
        $need = $this->requiredSolves($r);

        if ($need <= 1) {
            return true; // الاستدعاء نفسه ناتج عن حلّ رسمي صحيح مُسجَّل (afterPuzzleSolved)
        }

        return $this->officialSolveCount($referred) >= $need;
    }

    /** أحجيات رسمية مختلفة حُلّت بشكل صحيح (من الجدول الحقيقي، لا من عدّاد قابل للتزييف). */
    public function officialSolveCount(User $user): int
    {
        return (int) DB::table('puzzle_attempts')
            ->join('puzzles', 'puzzles.id', '=', 'puzzle_attempts.puzzle_id')
            ->where('puzzle_attempts.user_id', $user->id)
            ->where('puzzle_attempts.is_correct', true)
            ->where('puzzles.origin', \App\Models\Puzzle::ORIGIN_OFFICIAL)
            ->distinct()
            ->count('puzzle_attempts.puzzle_id');
    }
}
