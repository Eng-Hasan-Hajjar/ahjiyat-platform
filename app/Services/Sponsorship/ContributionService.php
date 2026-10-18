<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E30 - دورة حياة المساهمة. **لا بوابة دفع**: تُسجَّل معلّقة ثم يؤكّدها مدير مخوَّل فقط (sponsorships.confirm، منفصلة عن إدارة الملفات/البرامج).
 *
 * - المبلغ الأصلي + عملته (قائمة بيضاء) أعداد صحيحة بالوحدة الصغرى. عند التأكيد يُسجَّل المعادل المحاسبي: عملة المحاسبة نفسها ⇒ نفس المبلغ؛ عملة أخرى ⇒
 *   يدخله المدير (لا سعر صرف حيّ) ويُدقَّق قرار التحويل. بعد التأكيد تُحمى الحقول المالية بنيويًا (SponsorshipContribution): التصحيح = استرداد ثم مساهمة جديدة.
 * - التأكيد/الإلغاء/الاسترداد بقفل الصف + تحقق الحالة داخل المعاملة (تأكيدان متزامنان ⇒ واحد ينجح). الاسترداد لا يسحب مكافآت لاعبين مُنحت سابقًا (لقطات تاريخية)،
 *   لكنه يخفّض التمويل/المستوى/الصدارة فورًا ويُسقط دور الراعي إن لم يبقَ للداعم مساهمة مؤكَّدة بالبرنامج.
 * - الإشعارات والكاش بعد الـcommit؛ فشلها لا يُرجع شيئًا. قراءات الكاش لا تُستعمل هنا إطلاقًا (القرارات من قاعدة البيانات).
 */
class ContributionService
{
    public function __construct(
        protected OperationalAuditService $audit,
        protected SupportTotals $totals,
        protected SupporterTierService $tiers,
        protected SponsorshipNotifier $notifier,
    ) {}

    /**
     * @param  array{reference?: ?string, notes?: ?string, is_anonymous?: ?bool}  $options
     *
     * @throws SponsorshipException
     */
    public function record(User $actor, SupporterProfile $supporter, SponsorshipProgram $program, string|int $amount, string $currency, array $options = []): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        $currency = strtoupper(trim($currency));
        $minor = SponsorshipMoney::parse($amount, $currency);
        $extra = $this->cleanText($options);

        if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
            throw new SponsorshipException('لا تُسجَّل مساهمات على برنامج مغلق أو ملغى.');
        }

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف.');
        }

        $contribution = DB::transaction(function () use ($actor, $supporter, $program, $minor, $currency, $options, $extra) {
            $contribution = new SponsorshipContribution;
            $contribution->forceFill([
                'supporter_profile_id' => $supporter->id,
                'sponsorship_program_id' => $program->id,
                'original_amount_minor' => $minor,
                'currency' => $currency,
                'status' => SponsorshipContribution::STATUS_PENDING,
                'is_anonymous' => array_key_exists('is_anonymous', $options) && $options['is_anonymous'] !== null ? (bool) $options['is_anonymous'] : (bool) $supporter->anonymous_by_default,
                'reference' => $extra['reference'],
                'notes' => $extra['notes'],
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('sponsorship_contribution_recorded', $contribution, [
                'public_id' => $contribution->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
                'original_amount_minor' => $minor, 'currency' => $currency,
            ], $actor);

            return $contribution;
        });

        SponsorshipCache::bust();

        return $contribution;
    }

    /** تصحيح مساهمة **معلّقة** فقط (خطأ إدخال). تغيير البرنامج/المبلغ/العملة يُدقَّق بإجراء مستقل. @throws SponsorshipException */
    public function updatePending(User $actor, SponsorshipContribution $contribution, array $data): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        return DB::transaction(function () use ($actor, $contribution, $data) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('لا يُعدَّل إلا المعلّق؛ صحّح المؤكَّد بالاسترداد.');
            }

            $changes = [];

            if (isset($data['amount']) || isset($data['currency'])) {
                $currency = strtoupper(trim((string) ($data['currency'] ?? $locked->currency)));
                $minor = isset($data['amount']) ? SponsorshipMoney::parse($data['amount'], $currency) : SponsorshipMoney::assertMinor((int) $locked->original_amount_minor);

                if ($minor !== (int) $locked->original_amount_minor || $currency !== $locked->currency) {
                    $changes['original_amount_minor'] = $minor;
                    $changes['currency'] = $currency;
                }
            }

            if (isset($data['sponsorship_program_id']) && (int) $data['sponsorship_program_id'] !== (int) $locked->sponsorship_program_id) {
                $target = SponsorshipProgram::query()->findOrFail((int) $data['sponsorship_program_id']);

                if (! in_array($target->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
                    throw new SponsorshipException('لا يمكن النقل إلى برنامج مغلق أو ملغى.');
                }

                $changes['sponsorship_program_id'] = $target->id;
            }

            $text = $this->cleanText($data);

            foreach (['reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $text[$field];
                }
            }

            if (array_key_exists('is_anonymous', $data) && $data['is_anonymous'] !== null) {
                $changes['is_anonymous'] = (bool) $data['is_anonymous'];
            }

            if ($changes === []) {
                return $locked;
            }

            $before = $locked->only(array_keys($changes));
            $locked->forceFill($changes)->save();

            $this->audit->log('sponsorship_contribution_updated', $locked, ['changed' => array_keys($changes)], $actor);

            if (isset($changes['sponsorship_program_id'])) {
                $this->audit->log('sponsorship_contribution_program_changed', $locked, ['from' => $before['sponsorship_program_id'] ?? null, 'to' => $changes['sponsorship_program_id']], $actor);
            }

            return $locked;
        });
    }

    /**
     * يؤكّد مساهمة معلّقة: يسجّل المعادل المحاسبي ويُدقَّق. فقط sponsorships.confirm.
     *
     * @throws SponsorshipException
     */
    public function confirm(User $actor, SponsorshipContribution $contribution, string|int|null $accountingAmount = null): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);

        $before = null;
        $supporterId = 0;

        $confirmed = DB::transaction(function () use ($actor, $contribution, $accountingAmount, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            return $this->confirmLocked($actor, $locked, $accountingAmount, $before, $supporterId);
        });

        SponsorshipCache::bust();
        $this->publishChange($confirmed, $supporterId, $before, true);

        return $confirmed;
    }

    /**
     * E32: قلب التأكيد القابل لإعادة الاستعمال **داخل معاملة يملكها المنادي** (الصف مقفول مسبقًا بـlockForUpdate). نفس قواعد E30 حرفيًا (موقوف/برنامج مغلق/خلط عملات/المعادل المحاسبي/تدقيق)،
     * بلا تحقق صلاحية (المنادي مسؤول عنها) وبلا كاش أو إشعارات: يقوم بها المنادي بعد الـcommit (publishChange).
     *
     * @throws SponsorshipException
     */
    public function confirmLocked(?User $actor, SponsorshipContribution $locked, string|int|null $accountingAmount, mixed &$before, int &$supporterId): SponsorshipContribution
    {
        $accounting = SponsorshipMoney::accountingCurrency();

        if (! $locked->isPending()) {
            throw new SponsorshipException('هذه المساهمة ليست معلّقة (ربما عولجت للتو).');
        }

        $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
        $program = SponsorshipProgram::query()->findOrFail($locked->sponsorship_program_id);

        if ($supporter->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new SponsorshipException('ملف الداعم موقوف؛ لا يمكن تأكيد مساهمته.');
        }

        if (! in_array($program->status, [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE], true)) {
            throw new SponsorshipException('البرنامج مغلق أو ملغى؛ لا يمكن تأكيد مساهمة عليه.');
        }

        if (SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('accounting_currency', '!=', $accounting)->exists()) {
            throw new SponsorshipException('عملة المحاسبة تغيّرت بعد وجود مساهمات مؤكَّدة بعملة أخرى؛ لا يمكن خلط العملات (راجع التوثيق).');
        }

        if ($locked->currency === $accounting) {
            $accountingMinor = (int) $locked->original_amount_minor;

            if ($accountingAmount !== null && $accountingAmount !== '' && SponsorshipMoney::parse($accountingAmount, $accounting) !== $accountingMinor) {
                throw new SponsorshipException('بعملة المحاسبة نفسها يجب أن يساوي المعادل المبلغ الأصلي.');
            }
        } else {
            if ($accountingAmount === null || $accountingAmount === '') {
                throw new SponsorshipException('أدخل المعادل بعملة المحاسبة ('.$accounting.') لأن عملة المساهمة مختلفة.');
            }

            $accountingMinor = SponsorshipMoney::parse($accountingAmount, $accounting);
        }

        $supporterId = (int) $supporter->id;
        $before = $this->tiers->forSupporter($supporter);

        $locked->forceFill([
            'accounting_amount_minor' => $accountingMinor,
            'accounting_currency' => $accounting,
            'status' => SponsorshipContribution::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $actor?->id,
        ])->save();

        $this->audit->log('sponsorship_contribution_confirmed', $locked, [
            'public_id' => $locked->public_id, 'supporter' => $supporter->public_id, 'program' => $program->slug,
            'original_amount_minor' => (int) $locked->original_amount_minor, 'currency' => $locked->currency,
            'accounting_amount_minor' => $accountingMinor, 'accounting_currency' => $accounting,
        ], $actor);

        if ($locked->currency !== $accounting) {
            $this->audit->log('sponsorship_contribution_normalized', $locked, [
                'from_currency' => $locked->currency, 'from_minor' => (int) $locked->original_amount_minor,
                'to_currency' => $accounting, 'to_minor' => $accountingMinor,
            ], $actor);
        }

        return $locked;
    }

    /** @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);

        $cancelled = DB::transaction(function () use ($actor, $contribution, $reason) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            if (! $locked->isPending()) {
                throw new SponsorshipException('يُلغى المعلّق فقط؛ المؤكَّد يُسترد.');
            }

            $locked->forceFill(['status' => SponsorshipContribution::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'status_note' => $reason])->save();
            $this->audit->log('sponsorship_contribution_cancelled', $locked, ['public_id' => $locked->public_id, 'reason' => $reason], $actor);

            return $locked;
        });

        SponsorshipCache::bust();

        return $cancelled;
    }

    /** @throws SponsorshipException */
    public function refund(User $actor, SponsorshipContribution $contribution, string $reason): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.confirm'), 403);
        $reason = $this->requireReason($reason);
        $before = null;
        $supporterId = 0;

        $refunded = DB::transaction(function () use ($actor, $contribution, $reason, &$before, &$supporterId) {
            $locked = SponsorshipContribution::query()->lockForUpdate()->findOrFail($contribution->id);

            $locked = $this->refundLocked($actor, $locked, $reason, $before, $supporterId);

            // E32: مساهمة ناتجة عن طلب دفع ⇒ يُسجَّل الطلب مستردًّا في المعاملة نفسها (لا تعارض حالات بين الطلب والمساهمة).
            app(\App\Services\Payments\SponsorshipPaymentService::class)->syncRefundFromContribution($locked, $actor, $reason);

            return $locked;
        });

        SponsorshipCache::bust();
        $this->publishChange($refunded, $supporterId, $before, false);
        app(\App\Services\Payments\SponsorshipPaymentService::class)->notifyLinkedRefund($refunded);

        return $refunded;
    }

    /**
     * E32: قلب الاسترداد **داخل معاملة المنادي** (الصف مقفول مسبقًا). نفس قواعد E30 (المؤكَّد فقط، إسقاط دور الراعي عند انعدام المؤكَّد، تدقيق) بلا صلاحية/كاش/إشعارات.
     * لا يسحب أي مكافأة لاعب سبق منحها (لقطات تاريخية).
     *
     * @throws SponsorshipException
     */
    public function refundLocked(?User $actor, SponsorshipContribution $locked, string $reason, mixed &$before, int &$supporterId): SponsorshipContribution
    {
        if (! $locked->isConfirmed()) {
            throw new SponsorshipException('يُسترد المؤكَّد فقط.');
        }

        $supporter = SupporterProfile::query()->findOrFail($locked->supporter_profile_id);
        $supporterId = (int) $supporter->id;
        $before = $this->tiers->forSupporter($supporter);

        $locked->forceFill(['status' => SponsorshipContribution::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor?->id, 'status_note' => $reason])->save();
        $this->audit->log('sponsorship_contribution_refunded', $locked, [
            'public_id' => $locked->public_id, 'reason' => $reason, 'accounting_amount_minor' => (int) $locked->accounting_amount_minor,
        ], $actor);

        // لا مساهمة مؤكَّدة متبقية للداعم بهذا البرنامج ⇒ يسقط دوره (راعٍ/راعٍ رئيسي) ويُدقَّق.
        if (! $this->totals->hasConfirmed($supporterId, (int) $locked->sponsorship_program_id)) {
            $roles = SponsorshipProgramRole::query()->where('sponsorship_program_id', $locked->sponsorship_program_id)->where('supporter_profile_id', $supporterId)->get();

            foreach ($roles as $role) {
                $this->audit->log($role->role === SponsorshipProgramRole::ROLE_MAIN_SPONSOR ? 'main_sponsor_removed' : 'sponsorship_role_removed', $locked->program, ['supporter' => $supporter->public_id, 'reason' => 'refund'], $actor);
                $role->delete();
            }
        }

        return $locked;
    }

    /** يبدّل إخفاء الهوية لمساهمة (قرار خصوصية الداعم) دون المساس بالمبالغ. مدقَّق. */
    public function setAnonymity(User $actor, SponsorshipContribution $contribution, bool $anonymous): SponsorshipContribution
    {
        abort_unless($actor->can('sponsorships.manage'), 403);

        if ((bool) $contribution->is_anonymous !== $anonymous) {
            $contribution->forceFill(['is_anonymous' => $anonymous])->save();
            $this->audit->log('sponsorship_contribution_anonymity_changed', $contribution, ['public_id' => $contribution->public_id, 'anonymous' => $anonymous], $actor);
            SponsorshipCache::bust();
        }

        return $contribution;
    }

    /** إشعارات ما بعد الـcommit: تأكيد الدعم (للتأكيد فقط) وتغيّر المستوى. فشلها لا يمسّ المعاملة. (عامة منذ E32: يستدعيها مسار التحقق من الدفع بعد commit معاملته.) */
    public function publishChange(SponsorshipContribution $contribution, int $supporterId, mixed $tierBefore, bool $confirmed): void
    {
        try {
            $supporter = SupporterProfile::query()->with('user')->find($supporterId);

            if ($supporter === null) {
                return;
            }

            $contribution->setRelation('supporter', $supporter);

            if ($confirmed) {
                $this->notifier->supportConfirmed($contribution);
            }

            $this->notifier->tierChanged($supporter, $tierBefore, $this->tiers->forSupporter($supporter), (int) $contribution->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new SponsorshipException('اكتب سببًا واضحًا (3–255 حرفًا).');
        }

        if (Validator::make(['r' => $reason], ['r' => [new NoMarkup]])->fails()) {
            throw new SponsorshipException('لا يُسمح بالوسوم أو الأكواد في السبب.');
        }

        return $reason;
    }

    /** @return array{reference: ?string, notes: ?string} */
    protected function cleanText(array $data): array
    {
        $max = (array) config('sponsorship.profile');
        $validator = Validator::make($data, [
            'reference' => ['nullable', 'string', 'max:'.$max['reference_max'], new NoMarkup],
            'notes' => ['nullable', 'string', 'max:'.$max['notes_max'], new NoMarkup],
        ]);

        if ($validator->fails()) {
            throw new SponsorshipException((string) $validator->errors()->first());
        }

        return [
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }
}
