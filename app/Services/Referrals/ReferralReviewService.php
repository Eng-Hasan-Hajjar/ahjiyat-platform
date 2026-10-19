<?php

namespace App\Services\Referrals;

use App\Models\User;
use App\Models\UserReferral;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * E33 (E) - مراجعة المشرف للإحالات (referrals.review). الإشارات تقترح والإنسان يقرر:
 *  - approve: إحالة pending_review ⇒ qualified (مع تخطيط المكافأة وقت الاعتماد) ثم التسليم الاعتيادي بعد الـcommit.
 *  - disqualify: بسبب منظَّم من قائمة مغلقة. **لا يُخصم شيء من دفتر المحفظة** (لا خصم صامت): إن كانت قد كوفئت يجب إقرار صريح بأن الجواهر المسلَّمة تبقى،
 *    ويُسجَّل ذلك بالتدقيق. إحالة مستبعدة لا يُسلَّم لها جانب لم يُسلَّم بعد (reward_status ⇒ skipped).
 *  - كل إجراء: صلاحية + حد معدّل لكل مشرف + تدقيق. لا يغيّر حساب أي لاعب (لا تجميد) ولا ترتيبًا ولا نقاطًا.
 *  - المحيل لا يتغيّر أبدًا؛ المراجعة لا تلمس referrer_user_id.
 */
class ReferralReviewService
{
    public function __construct(
        protected ReferralQualificationService $qualification,
        protected OperationalAuditService $audit,
    ) {}

    public function approve(User $admin, UserReferral $referral, ?string $note = null): UserReferral
    {
        $this->authorize($admin);

        $result = DB::transaction(function () use ($admin, $referral, $note) {
            $r = UserReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== UserReferral::STATUS_PENDING_REVIEW) {
                throw new ReferralException('not_in_review', 'الإحالة ليست بانتظار المراجعة.');
            }

            if ($r->referrer_user_id === null) {
                throw new ReferralException('referrer_missing', 'لا يمكن اعتماد إحالة محيلها محذوف.');
            }

            $this->qualification->finalize($r, now(), (array) $r->risk_signals);
            $r->forceFill(['reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $this->note($note)])->save();
            $this->audit->log('referral_review_approved', $r, ['signals' => $r->risk_signals], $admin);

            return $r;
        });

        $this->qualification->afterQualified($result);
        app(ReferralAnalytics::class)->forget(); // قرار بشري: تنعكس أرقام اللوحة فورًا لا بعد انتهاء الكاش

        return $result->fresh();
    }

    public function disqualify(User $admin, UserReferral $referral, string $reason, ?string $note = null, bool $acknowledgeKeptRewards = false): UserReferral
    {
        $this->authorize($admin);

        if (! array_key_exists($reason, UserReferral::DISQUALIFY_REASONS)) {
            throw new ReferralException('invalid_reason', 'سبب الاستبعاد غير صالح.');
        }

        $result = DB::transaction(function () use ($admin, $referral, $reason, $note, $acknowledgeKeptRewards) {
            $r = UserReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($r->status === UserReferral::STATUS_DISQUALIFIED) {
                throw new ReferralException('already_disqualified', 'الإحالة مستبعدة مسبقًا.');
            }

            $granted = ['referrer' => $r->referrer_granted_at !== null ? (int) $r->snap_referrer_gems : 0, 'referred' => $r->referred_granted_at !== null ? (int) $r->snap_referred_gems : 0];
            $anyGranted = $granted['referrer'] > 0 || $granted['referred'] > 0;

            if ($anyGranted && ! $acknowledgeKeptRewards) {
                throw new ReferralException('kept_rewards_ack_required', 'سُلّمت جواهر لهذه الإحالة ولن تُخصم تلقائيًا. أقرّ بذلك صراحةً لإتمام الاستبعاد.');
            }

            $attrs = [
                'status' => UserReferral::STATUS_DISQUALIFIED,
                'disqualified_reason' => $reason,
                'disqualified_at' => now(),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $this->note($note),
            ];

            if (! $anyGranted) {
                $attrs += ['reward_status' => UserReferral::REWARD_SKIPPED, 'reward_skip_reason' => 'disqualified'];
            }

            $r->forceFill($attrs)->save();
            $this->audit->log('referral_review_disqualified', $r, ['reason' => $reason, 'rewards_kept' => $granted, 'ledger_untouched' => true], $admin);

            return $r;
        });

        app(ReferralAnalytics::class)->forget();

        return $result;
    }

    protected function authorize(User $admin): void
    {
        if (! $admin->can('referrals.review')) {
            throw new ReferralException('forbidden', 'لا تملك صلاحية مراجعة الإحالات.');
        }

        $key = 'referral-campaign-action:'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('referrals.limits.campaign_action_per_minute'))) {
            throw new ReferralException('rate_limited', 'عدد كبير من الإجراءات خلال دقيقة. انتظر قليلًا.');
        }

        RateLimiter::hit($key, 60);
    }

    protected function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
