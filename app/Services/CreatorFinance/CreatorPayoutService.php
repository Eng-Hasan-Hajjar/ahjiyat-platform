<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\CreatorPayout;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Creators\CreatorEligibility;
use App\Services\CreatorFinance\Concerns\AuthorizesCreatorFinance;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E39 - دورة طلب الدفعة اليدوية: requested → (under_review) → approved → paid | rejected | cancelled.
 *
 *  - الطلب يحجز المبلغ فورًا (available → reserved) بقيد دفتر؛ لا يمكن إنفاق المبلغ نفسه مرتين. طلب مفتوح واحد لكل حساب (فحص تحت القفل + unique(open_account_id)).
 *  - الرفض/الإلغاء يفكّان الحجز (reserved → available)؛ الصرف ينقله (reserved → paid_lifetime). كل حركة بقيد مفتاحه دلالي (idempotent).
 *  - يمنع الاعتماد والصرف: تسييل موقوف، حساب معلَّق، مبدع مجمَّد أو غير معتمَد (E38/E28). **الرفض مسموح دائمًا** كي لا يبقى المال محجوزًا بلا مخرج.
 *  - لا وجهة تحويل ولا بيانات بنكية في أي مكان: المرجع الخارجي فقط (فريد عالميًا). لا أحد يعالج دفعته الخاصة. اختياريًا: من اعتمد لا يصرف (config).
 *  - الإشعارات بعد الـcommit ومعزولة الفشل.
 */
class CreatorPayoutService
{
    use AuthorizesCreatorFinance;

    public function __construct(
        protected OperationalAuditService $audit,
        protected CreatorLedgerService $ledger,
        protected CreatorFinanceNotifier $notifier,
        protected CreatorEligibility $eligibility,
        protected CreatorPayoutProvider $provider,
    ) {}

    /** طلب المبدع نفسه. المبلغ نص بالوحدة الرئيسية. */
    public function request(User $user, string $amountText, string $methodCode): CreatorPayout
    {
        if (! (bool) config('creator_monetization.enabled', true)) {
            throw new CreatorFinanceException('ميزة الأرباح معطَّلة حاليًا.');
        }

        $denial = $this->eligibility->studioDenial($user);

        if ($denial !== null) {
            throw new CreatorFinanceException($denial);
        }

        if (! array_key_exists($methodCode, (array) config('creator_monetization.payout.methods'))) {
            throw new CreatorFinanceException('وسيلة الدفع غير معتمدة.');
        }

        $profile = $this->eligibility->profileFor($user);
        $account = CreatorEarningAccount::query()->where('creator_profile_id', $profile->id)->first();

        if ($account === null) {
            throw new CreatorFinanceException('التسييل غير مُفعَّل لحسابك بعد.');
        }

        try {
            return DB::transaction(function () use ($account, $user, $amountText, $methodCode) {
                $locked = $this->ledger->lockAccount((int) $account->id);

                if (! $locked->isEnabled()) {
                    throw new CreatorFinanceException('التسييل موقوف مؤقتًا لحسابك.');
                }

                if ($locked->isOnHold()) {
                    throw new CreatorFinanceException('طلبات الدفع متوقفة مؤقتًا على حسابك؛ تواصل مع الدعم.');
                }

                if ((string) $locked->currency !== CreatorMoney::currency()) {
                    throw new CreatorFinanceException('عملة حسابك لا تطابق عملة المحاسبة الحالية؛ تواصل مع الدعم.');
                }

                $amount = CreatorMoney::parse($amountText, (string) $locked->currency, (int) config('creator_monetization.payout.max_amount_minor'));
                $min = (int) config('creator_monetization.payout.min_amount_minor');

                if ($amount < $min) {
                    throw new CreatorFinanceException('أقل مبلغ للدفعة '.CreatorMoney::format($min, (string) $locked->currency).'.');
                }

                if ($amount > (int) $locked->available_minor) {
                    throw new CreatorFinanceException('المبلغ أكبر من رصيدك المتاح.');
                }

                if (CreatorPayout::query()->where('creator_earning_account_id', $locked->id)->whereIn('status', CreatorPayout::OPEN_STATUSES)->exists()) {
                    throw new CreatorFinanceException('لديك طلب دفعة مفتوح بالفعل؛ انتظر نتيجته أو ألغِه.');
                }

                $payout = new CreatorPayout;
                $payout->forceFill([
                    'creator_earning_account_id' => $locked->id,
                    'creator_profile_id' => $locked->creator_profile_id,
                    'user_id' => $locked->user_id,
                    'amount_minor' => $amount,
                    'currency' => $locked->currency,
                    'method_code' => $methodCode,
                    'provider' => $this->provider->code(),
                    'status' => CreatorPayout::STATUS_REQUESTED,
                    'requested_at' => now(),
                ])->save();

                $this->ledger->post($locked, CreatorEarningEntry::TYPE_PAYOUT_RESERVE, -$amount, $amount, 0, 'payout:'.$payout->public_id.':reserve', ['payout_id' => $payout->id, 'actor_id' => $user->id]);
                $this->audit->log('creator_payout_requested', $payout, ['amount_minor' => $amount, 'method' => $methodCode], $user);

                return $payout;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreatorFinanceException('لديك طلب دفعة مفتوح بالفعل؛ انتظر نتيجته أو ألغِه.');
        }
    }

    /** إلغاء المبدع لطلبه قبل بدء المراجعة. المالك فقط. */
    public function cancel(User $user, CreatorPayout $payout): CreatorPayout
    {
        return DB::transaction(function () use ($user, $payout) {
            [$account, $locked] = $this->lock($payout);

            if ((int) $locked->user_id !== (int) $user->getKey()) {
                throw new CreatorFinanceException('هذا الطلب ليس لك.');
            }

            if ($locked->status === CreatorPayout::STATUS_CANCELLED) {
                return $locked; // idempotent
            }

            if ($locked->status !== CreatorPayout::STATUS_REQUESTED) {
                throw new CreatorFinanceException('لا يمكن إلغاء الطلب بعد بدء مراجعته.');
            }

            $locked->forceFill(['status' => CreatorPayout::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            $this->release($account, $locked, $user);
            $this->audit->log('creator_payout_cancelled', $locked, ['amount_minor' => (int) $locked->amount_minor], $user);

            return $locked;
        });
    }

    public function startReview(CreatorPayout $payout, User $actor): CreatorPayout
    {
        $this->authorizeFinance($actor, 'creator-finance.review-payouts');

        return DB::transaction(function () use ($payout, $actor) {
            [$account, $locked] = $this->lock($payout);
            $this->assertNotOwnAccount($actor, $account);

            if ($locked->status === CreatorPayout::STATUS_UNDER_REVIEW) {
                return $locked; // idempotent
            }

            if ($locked->status !== CreatorPayout::STATUS_REQUESTED) {
                throw new CreatorFinanceException('لا تبدأ المراجعة إلا لطلب جديد.');
            }

            $locked->forceFill(['status' => CreatorPayout::STATUS_UNDER_REVIEW, 'review_started_by' => $actor->id, 'review_started_at' => now()])->save();
            $this->audit->log('creator_payout_review_started', $locked, [], $actor);

            return $locked;
        });
    }

    public function approve(CreatorPayout $payout, User $actor, ?string $note = null): CreatorPayout
    {
        $this->authorizeFinance($actor, 'creator-finance.review-payouts');
        $note = $this->optionalNote($note);

        $approved = false;
        $result = DB::transaction(function () use ($payout, $actor, $note, &$approved) {
            [$account, $locked] = $this->lock($payout);
            $this->assertNotOwnAccount($actor, $account);

            if ($locked->status === CreatorPayout::STATUS_APPROVED) {
                return $locked; // idempotent
            }

            if (! in_array($locked->status, [CreatorPayout::STATUS_REQUESTED, CreatorPayout::STATUS_UNDER_REVIEW], true)) {
                throw new CreatorFinanceException('لا يُعتمد إلا طلب مفتوح قيد المراجعة.');
            }

            $this->assertPayable($account, $locked);

            if ((int) $account->reserved_minor < (int) $locked->amount_minor) {
                throw new CreatorFinanceException('المبلغ المحجوز لا يغطي الطلب؛ شغّل المصالحة قبل المتابعة.');
            }

            $locked->forceFill([
                'status' => CreatorPayout::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'review_started_by' => $locked->review_started_by ?? $actor->id,
                'review_started_at' => $locked->review_started_at ?? now(),
                'review_note' => $note ?? $locked->review_note,
            ])->save();
            $this->audit->log('creator_payout_approved', $locked, ['amount_minor' => (int) $locked->amount_minor], $actor);
            $approved = true;

            return $locked;
        });

        if ($approved) {
            $this->notifier->payoutApproved($result);
        }

        return $result;
    }

    public function reject(CreatorPayout $payout, User $actor, string $publicMessage, ?string $note = null): CreatorPayout
    {
        $this->authorizeFinance($actor, 'creator-finance.review-payouts');
        $publicMessage = trim($publicMessage);
        $max = (int) config('creator_monetization.payout.message_max');

        if (mb_strlen($publicMessage) < 8 || mb_strlen($publicMessage) > $max) {
            throw new CreatorFinanceException("رسالة الرفض للمبدع مطلوبة (8–{$max} حرفًا).");
        }

        $note = $this->optionalNote($note);
        $rejected = false;

        $result = DB::transaction(function () use ($payout, $actor, $publicMessage, $note, &$rejected) {
            [$account, $locked] = $this->lock($payout);
            $this->assertNotOwnAccount($actor, $account);

            if ($locked->status === CreatorPayout::STATUS_REJECTED) {
                return $locked; // idempotent
            }

            if (! $locked->isOpen()) {
                throw new CreatorFinanceException('لا يُرفض إلا طلب مفتوح.');
            }

            $locked->forceFill([
                'status' => CreatorPayout::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'decision_message' => $publicMessage,
                'review_note' => $note ?? $locked->review_note,
            ])->save();
            $this->release($account, $locked, $actor);
            $this->audit->log('creator_payout_rejected', $locked, ['amount_minor' => (int) $locked->amount_minor], $actor);
            $rejected = true;

            return $locked;
        });

        if ($rejected) {
            $this->notifier->payoutRejected($result);
        }

        return $result;
    }

    /** تسجيل الصرف الفعلي (تم خارج المنصة) بمرجع خارجي فريد. idempotent بالمرجع نفسه. */
    public function settle(CreatorPayout $payout, User $actor, string $reference): CreatorPayout
    {
        $this->authorizeFinance($actor, 'creator-finance.settle-payouts');
        $reference = $this->provider->normalizeReference($reference);
        $settled = false;

        try {
            $result = DB::transaction(function () use ($payout, $actor, $reference, &$settled) {
                [$account, $locked] = $this->lock($payout);
                $this->assertNotOwnAccount($actor, $account);

                if ($locked->status === CreatorPayout::STATUS_PAID) {
                    if ($locked->external_reference === $reference) {
                        return $locked; // idempotent
                    }

                    throw new CreatorFinanceException('الدفعة مصروفة بالفعل بمرجع مختلف.');
                }

                if ($locked->status !== CreatorPayout::STATUS_APPROVED) {
                    throw new CreatorFinanceException('لا يُسجَّل الصرف إلا لدفعة معتمدة.');
                }

                $this->assertPayable($account, $locked);

                if ((bool) config('creator_monetization.payout.require_separate_settler') && (int) $locked->approved_by === (int) $actor->getKey()) {
                    throw new CreatorFinanceException('من اعتمد الدفعة لا يسجّل صرفها؛ يلزم مسؤول آخر.');
                }

                if (CreatorPayout::query()->where('external_reference', $reference)->where('id', '!=', $locked->id)->exists()) {
                    throw new CreatorFinanceException('مرجع الصرف مستخدم لدفعة أخرى.');
                }

                if ((int) $account->reserved_minor < (int) $locked->amount_minor) {
                    throw new CreatorFinanceException('المبلغ المحجوز لا يغطي الدفعة؛ شغّل المصالحة قبل المتابعة.');
                }

                $amount = (int) $locked->amount_minor;
                $locked->forceFill(['status' => CreatorPayout::STATUS_PAID, 'paid_by' => $actor->id, 'paid_at' => now(), 'external_reference' => $reference])->save();
                $this->ledger->post($account, CreatorEarningEntry::TYPE_PAYOUT_SETTLE, 0, -$amount, $amount, 'payout:'.$locked->public_id.':settle', ['payout_id' => $locked->id, 'actor_id' => $actor->id]);
                $this->audit->log('creator_payout_paid', $locked, ['amount_minor' => $amount, 'reference' => $reference], $actor);
                $settled = true;

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CreatorFinanceException('مرجع الصرف مستخدم لدفعة أخرى.');
        }

        if ($settled) {
            $this->notifier->payoutPaid($result);
        }

        return $result;
    }

    /**
     * يقفل الحساب أولًا ثم الطلب (ترتيب ثابت في كل المسارات) ويعيدهما طازجين.
     *
     * @return array{0: CreatorEarningAccount, 1: CreatorPayout}
     */
    protected function lock(CreatorPayout $payout): array
    {
        $accountId = (int) CreatorPayout::query()->whereKey($payout->id)->value('creator_earning_account_id');
        $account = $this->ledger->lockAccount($accountId);
        $locked = CreatorPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

        return [$account, $locked];
    }

    protected function release(CreatorEarningAccount $lockedAccount, CreatorPayout $payout, User $actor): void
    {
        $amount = (int) $payout->amount_minor;
        $this->ledger->post($lockedAccount, CreatorEarningEntry::TYPE_PAYOUT_RELEASE, $amount, -$amount, 0, 'payout:'.$payout->public_id.':release', ['payout_id' => $payout->id, 'actor_id' => $actor->id]);
    }

    /** @throws CreatorFinanceException */
    protected function assertPayable(CreatorEarningAccount $account, CreatorPayout $payout): void
    {
        if (! $account->isEnabled()) {
            throw new CreatorFinanceException('تسييل المبدع موقوف مؤقتًا؛ استأنفه أو ارفض الطلب.');
        }

        if ($account->isOnHold()) {
            throw new CreatorFinanceException('حساب المبدع معلَّق للدفعات؛ ارفع التعليق أو ارفض الطلب.');
        }

        $user = User::query()->find($payout->user_id);

        if ($user === null || $user->is_frozen) {
            throw new CreatorFinanceException('حساب المبدع مجمَّد؛ لا اعتماد ولا صرف حتى رفع التجميد (أو ارفض الطلب).');
        }

        $profile = CreatorProfile::query()->find($payout->creator_profile_id);

        if ($profile === null || ! $profile->isApproved()) {
            throw new CreatorFinanceException('ملف المبدع غير معتمَد حاليًا؛ لا اعتماد ولا صرف (أو ارفض الطلب).');
        }
    }

    protected function optionalNote(?string $note): ?string
    {
        $note = $note !== null ? trim($note) : null;

        if ($note === null || $note === '') {
            return null;
        }

        if (mb_strlen($note) > (int) config('creator_monetization.payout.note_max')) {
            throw new CreatorFinanceException('الملاحظة أطول من المسموح.');
        }

        return $note;
    }
}
