<?php

namespace App\Services\Payments;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Rules\NoMarkup;
use App\Services\OperationalAuditService;
use App\Services\PlatformSettingsService;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipCache;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipNotifier;
use App\Services\Sponsorship\SupporterService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * E32 - دورة حياة طلب الدعم/الدفع. **نقطة الكتابة المالية الوحيدة** لطلبات الدعم: لا متحكم ولا إجراء Filament يحدّث الطلب ثم ينشئ المساهمة بنفسه.
 *
 * التحقق الذرّي (verify): معاملة واحدة تقفل الطلب (lockForUpdate) ⇒ تتحقق من حالته ⇒ تقفل المستخدم وتحلّ/تنشئ ملف الداعم ⇒ تنشئ المساهمة معلّقة ثم تؤكّدها بـ**منطق E30 نفسه**
 * (ContributionService::confirmLocked: المعادل المحاسبي/البرنامج/الإيقاف/تدقيق التطبيع) ⇒ تربط الطلب بالمساهمة وتسجّل التدقيق ⇒ commit. الإشعارات والكاش بعد الـcommit وفشلها لا يرجع المال.
 * أي استثناء بالمنتصف ⇒ rollback كامل (لا مساهمة ولا ملف داعم ولا تغيير حالة).
 *
 * الحماية من التكرار (قاعدة البيانات هي الحدّ النهائي): إعادة Verify ⇒ لا أثر ثانٍ (فحص الحالة تحت القفل)؛ واليقين البنيوي: UNIQUE(payment.sponsorship_contribution_id)،
 * UNIQUE(contributions.provider, external_reference = مرجع الطلب)، UNIQUE(provider, provider_reference)، UNIQUE(user_id, request_key).
 *
 * لا استرداد آلي لمكافآت اللاعبين (لقطات تاريخية)؛ الاسترداد يعدّل صافي الدعم/المستوى/الصدارة/التمويل فقط عبر منطق E30. ترتيب الأقفال: مساهمة ثم طلب (في الاسترداد) بما يطابق مسار E30 المباشر.
 * لا TransactionPolicy هنا عمدًا (تثبيت E31 P-2: أربع نقاط دخول فقط).
 */
class SponsorshipPaymentService
{
    public const PERMISSION = 'sponsorships.confirm';

    public function __construct(
        protected OperationalAuditService $audit,
        protected PaymentProviderRegistry $providers,
        protected ContributionService $contributions,
        protected SupporterService $supporters,
        protected SponsorshipNotifier $notifier,
        protected PaymentProofService $proofs,
        protected PlatformSettingsService $settings,
    ) {}

    // ------------------------------------------------------------------ إنشاء الطلب (المستخدم)

    /**
     * @throws PaymentException
     */
    public function createRequest(User $user, SponsorshipProgram $program, string|int $amount, string $currency, bool $anonymous, ?string $displayName = null, ?string $providerKey = null, ?string $requestKey = null): SponsorshipPayment
    {
        if (! (bool) config('sponsorship.enabled', true) || ! (bool) config('payments.enabled', true)) {
            throw new PaymentException('الدعم غير متاح حاليًا.');
        }

        if ($user->is_frozen) {
            throw new PaymentException('حسابك مجمّد؛ لا يمكن إنشاء طلب دعم.');
        }

        if (! $user->hasVerifiedEmail()) {
            throw new PaymentException('وثّق بريدك الإلكتروني أولًا لإنشاء طلب دعم.');
        }

        $provider = $this->providers->get($providerKey ?: (string) config('payments.default_provider', 'manual'));
        $currency = strtoupper(trim($currency));
        $minor = PaymentRules::parseAmount($amount, $currency, $provider);
        $requestKey = $this->cleanRequestKey($requestKey);
        $name = $anonymous ? null : $this->cleanOptionalText($displayName ?? $user->name, (int) config('payments.checkout.display_name_max', 80), 'الاسم');

        if ($requestKey !== null && ($existing = $this->findByRequestKey($user, $requestKey)) !== null) {
            return $existing;
        }

        if (! $program->isActiveNow()) {
            throw new PaymentException('هذا البرنامج غير متاح للدعم حاليًا.');
        }

        $profile = SupporterProfile::query()->where('user_id', $user->id)->first(['id', 'status']);

        if ($profile !== null && $profile->status === SupporterProfile::STATUS_SUSPENDED) {
            throw new PaymentException('ملف الداعم الخاص بك موقوف؛ تواصل مع الإدارة.');
        }

        $open = SponsorshipPayment::query()->where('user_id', $user->id)->open()->count();

        if ($open >= max(1, (int) config('payments.checkout.max_open_requests_per_user', 5))) {
            throw new PaymentException('لديك طلبات دعم مفتوحة كثيرة؛ أكمل أو ألغِ بعضها أولًا.');
        }

        $payment = null;

        for ($attempt = 0; $attempt < 5 && $payment === null; $attempt++) {
            try {
                $payment = DB::transaction(function () use ($user, $program, $provider, $minor, $currency, $anonymous, $name, $requestKey, $profile) {
                    $payment = new SponsorshipPayment;
                    $payment->forceFill([
                        'reference' => PaymentReferenceGenerator::generate(),
                        'sponsorship_program_id' => $program->id,
                        'user_id' => $user->id,
                        'supporter_profile_id' => $profile?->id,
                        'provider' => $provider->key(),
                        'request_key' => $requestKey,
                        'amount_minor' => $minor,
                        'currency' => $currency,
                        'status' => SponsorshipPayment::STATUS_AWAITING,
                        'is_anonymous' => $anonymous,
                        'display_name' => $name,
                        'expires_at' => now()->addHours(max(1, (int) config('payments.request.expiry_hours', 48))),
                    ])->save();

                    $this->audit->log('payment_request_created', $payment, [
                        'public_id' => $payment->public_id, 'reference' => $payment->reference, 'program' => $program->slug,
                        'amount_minor' => $minor, 'currency' => $currency, 'provider' => $provider->key(), 'anonymous' => $anonymous,
                    ], $user);

                    return $payment;
                });
            } catch (UniqueConstraintViolationException $e) {
                // نقر مزدوج بنفس المفتاح ⇒ الطلب نفسه؛ وإلا تصادم مرجع نادر ⇒ نعيد بمرجع جديد.
                if ($requestKey !== null && ($existing = $this->findByRequestKey($user, $requestKey)) !== null) {
                    return $existing;
                }
            }
        }

        if ($payment === null) {
            throw new PaymentException('تعذّر إنشاء الطلب الآن، أعد المحاولة.');
        }

        try {
            $init = $provider->createPayment($payment);

            if ($init->providerReference !== null) {
                $payment->forceFill(['provider_reference' => $init->providerReference])->save();
            }
        } catch (\Throwable $e) {
            $this->cancelSystem($payment, 'provider_error');

            throw $e instanceof PaymentException ? $e : new PaymentException('تعذّر بدء الدفع لدى المزوّد.');
        }

        $this->notifier->paymentReceived($payment);

        return $payment->refresh();
    }

    // ------------------------------------------------------------------ خطوات المالك (إثبات/تحويل/إلغاء)

    /**
     * «حوّلت المبلغ»: awaiting_payment → submitted مع ملاحظة المحوِّل وإثبات اختياري (إلزامي إن فرضته الإعدادات). نقر مزدوج ⇒ لا أثر ثانٍ.
     *
     * @throws PaymentException
     */
    public function markTransferred(User $user, SponsorshipPayment $payment, ?string $payerNote = null, ?UploadedFile $proof = null): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);
        $note = $this->cleanOptionalText($payerNote, (int) config('payments.checkout.note_max', 255), 'الملاحظة');
        $stored = $proof !== null ? $this->proofs->store($proof) : null;
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($user, $payment, $note, $stored, &$oldPath) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $this->assertOwner($user, $locked);

                if ($locked->status === SponsorshipPayment::STATUS_SUBMITTED) {
                    if ($stored !== null) {
                        $oldPath = $this->applyProof($locked, $stored);
                        $this->audit->log('payment_proof_replaced', $locked, ['public_id' => $locked->public_id, 'mime' => $stored['mime'], 'size' => $stored['size']], $user);
                    }

                    return $locked;
                }

                if ($locked->status !== SponsorshipPayment::STATUS_AWAITING) {
                    throw new PaymentException('لا يمكن إرسال هذا الطلب في حالته الحالية.');
                }

                if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                    throw new PaymentException('انتهت صلاحية هذا الطلب؛ أنشئ طلب دعم جديدًا.');
                }

                if ($stored === null && ! $locked->hasProof() && (bool) $this->settings->get('payments', 'proof_required', false)) {
                    throw new PaymentException('أرفق إثبات التحويل لإرسال الطلب.');
                }

                if ($stored !== null) {
                    $oldPath = $this->applyProof($locked, $stored);
                }

                $locked->forceFill([
                    'status' => SponsorshipPayment::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'expires_at' => null,
                    'payer_note' => $note ?? $locked->payer_note,
                ])->save();

                $this->audit->log('payment_proof_submitted', $locked, [
                    'public_id' => $locked->public_id, 'reference' => $locked->reference, 'has_proof' => $locked->hasProof(),
                    'mime' => $locked->proof_mime, 'size' => $locked->proof_size,
                ], $user);

                return $locked;
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->proofs->delete($stored['path']);
            }

            throw $e;
        }

        $this->proofs->delete($oldPath);

        return $updated;
    }

    /**
     * رفع/استبدال الإثبات (المالك) ما دام الطلب مفتوحًا. بعد التحقق/الرفض/الإلغاء/الانتهاء: ثابت. @throws PaymentException
     */
    public function attachProof(User $user, SponsorshipPayment $payment, UploadedFile $proof): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);
        $stored = $this->proofs->store($proof);
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($user, $payment, $stored, &$oldPath) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $this->assertOwner($user, $locked);

                if (! $locked->isOpen()) {
                    throw new PaymentException('الإثبات ثابت بعد انتهاء معالجة الطلب.');
                }

                $replacing = $locked->hasProof();
                $oldPath = $this->applyProof($locked, $stored);
                $this->audit->log($replacing ? 'payment_proof_replaced' : 'payment_proof_submitted', $locked, [
                    'public_id' => $locked->public_id, 'mime' => $stored['mime'], 'size' => $stored['size'], 'status' => $locked->status,
                ], $user);

                return $locked;
            });
        } catch (\Throwable $e) {
            $this->proofs->delete($stored['path']);

            throw $e;
        }

        $this->proofs->delete($oldPath);

        return $updated;
    }

    /** المالك يلغي طلبه **قبل** الإرسال فقط (بعد الإرسال يلغيه المراجع). @throws PaymentException */
    public function cancelByOwner(User $user, SponsorshipPayment $payment): SponsorshipPayment
    {
        $this->assertOwner($user, $payment);

        return DB::transaction(function () use ($user, $payment) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->assertOwner($user, $locked);

            if ($locked->status === SponsorshipPayment::STATUS_CANCELLED) {
                return $locked;
            }

            if ($locked->status !== SponsorshipPayment::STATUS_AWAITING) {
                throw new PaymentException('يُلغى الطلب قبل إرسال التحويل فقط؛ للتعديل بعدها تواصل مع الإدارة.');
            }

            $locked->forceFill(['status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id])->save();
            $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'owner'], $user);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ المراجعة المالية (sponsorships.confirm)

    /**
     * يتحقق من الدفع ويجسّد مساهمة مؤكَّدة ذريًا. إعادة النداء على طلب محقَّق ⇒ يُعاد كما هو (لا مساهمة ثانية).
     *
     * @throws SponsorshipException
     */
    public function verify(User $actor, SponsorshipPayment $payment, string|int|null $accountingAmount = null): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->verifyCore($actor, $payment, $accountingAmount);
    }

    /** @throws SponsorshipException */
    public function reject(User $actor, SponsorshipPayment $payment, string $failureCode, ?string $message = null, ?string $reviewNote = null): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->rejectCore($actor, $payment, $failureCode, $message, $reviewNote);
    }

    /** يطلب توضيحًا/إثباتًا إضافيًا: submitted → awaiting_payment برسالة مرئية لصاحب الطلب ومهلة جديدة. @throws SponsorshipException */
    public function requestClarification(User $actor, SponsorshipPayment $payment, string $message): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $message = $this->contributions->requireReason($message);

        $updated = DB::transaction(function () use ($actor, $payment, $message) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== SponsorshipPayment::STATUS_SUBMITTED) {
                throw new PaymentException('يُطلب التوضيح للطلبات المرسَلة فقط.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_AWAITING,
                'clarification_requested_at' => now(),
                'user_message' => $message,
                'expires_at' => now()->addHours(max(1, (int) config('payments.request.expiry_hours', 48))),
            ])->save();

            $this->audit->log('payment_clarification_requested', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference], $actor);

            return $locked;
        });

        $this->notifier->paymentActionRequired($updated);

        return $updated;
    }

    /** إلغاء إداري لطلب مفتوح (مثل: تكرار/خطأ). السبب مرئي لصاحب الطلب. @throws SponsorshipException */
    public function cancel(User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);
        $reason = $this->contributions->requireReason($reason);

        $updated = DB::transaction(function () use ($actor, $payment, $reason) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_CANCELLED) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يُلغى إلا الطلب المفتوح؛ المحقَّق يُسترد.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'user_message' => $reason,
            ])->save();
            $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'staff', 'reason' => $reason], $actor);

            return $locked;
        });

        $this->notifier->paymentRejected($updated);

        return $updated;
    }

    /**
     * يسجّل استرداد دعم محقَّق (التحويل المعاكس يتم خارجيًا). المساهمة تُسترد بسلطة E30 (refundLocked) داخل المعاملة نفسها؛ لا سحب لمكافآت لاعبين سابقة.
     * إعادة الاسترداد ⇒ آمنة (لا أثر ثانٍ). @throws SponsorshipException
     */
    public function refund(User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        abort_unless($actor->can(self::PERMISSION), 403);

        return $this->refundCore($actor, $payment, $this->contributions->requireReason($reason));
    }

    // ------------------------------------------------------------------ مزوّد خارجي مستقبلي (webhook موقَّع)

    /**
     * يطبّق حدثًا موحَّدًا **بعد** تحقق توقيع المزوّد (يتم في المزوّد/المتحكم). idempotent بطبيعة الحالة (تكرار الحدث لا يكرّر الأثر).
     * لا يُحقَّق آليًا إلا إن طابق المبلغ والعملة الطلب وكانت عملة الطلب هي عملة المحاسبة (لا FX حيّ)؛ غير ذلك يُترك للمراجعة مع تدقيق.
     *
     * @return string applied|duplicate|ignored|needs_review
     */
    public function applyProviderCallback(string $providerKey, ProviderCallback $callback): string
    {
        $payment = SponsorshipPayment::query()->where('provider', $providerKey)->where('provider_reference', $callback->providerReference)->first();

        if ($payment === null) {
            return 'ignored';
        }

        if ($callback->state === ProviderCallback::STATE_PAID) {
            if ($payment->status === SponsorshipPayment::STATUS_VERIFIED || $payment->status === SponsorshipPayment::STATUS_REFUNDED) {
                return 'duplicate';
            }

            $matches = ($callback->amountMinor === null || $callback->amountMinor === (int) $payment->amount_minor) && ($callback->currency === null || strtoupper($callback->currency) === $payment->currency);

            if (! $matches || $payment->currency !== \App\Services\Sponsorship\SponsorshipMoney::accountingCurrency() || ! $payment->isOpen()) {
                $this->audit->log('payment_callback_needs_review', $payment, ['public_id' => $payment->public_id, 'event' => $callback->eventId, 'amount_matches' => $matches]);

                return 'needs_review';
            }

            $this->verifyCore(null, $payment, null);

            return 'applied';
        }

        if ($callback->state === ProviderCallback::STATE_FAILED) {
            if (! $payment->isOpen()) {
                return 'duplicate';
            }

            $this->rejectCore(null, $payment, 'not_received', null, null);

            return 'applied';
        }

        if ($callback->state === ProviderCallback::STATE_REFUNDED) {
            if ($payment->status === SponsorshipPayment::STATUS_REFUNDED) {
                return 'duplicate';
            }

            $this->refundCore(null, $payment, 'refund reported by provider');

            return 'applied';
        }

        return 'ignored';
    }

    // ------------------------------------------------------------------ انتهاء الصلاحية المجدول

    /**
     * يُنهي الطلبات «بانتظار الدفع» المتأخرة فقط (المرسَل/المحقَّق/المسترد لا ينتهي آليًا). حالة فقط: لا حذف تاريخ. idempotent. @return int عدد المنتهي.
     */
    public function expireStale(?int $limit = null): int
    {
        $limit ??= max(1, (int) config('payments.request.expire_batch', 200));
        $ids = SponsorshipPayment::query()->where('status', SponsorshipPayment::STATUS_AWAITING)->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $locked = SponsorshipPayment::query()->lockForUpdate()->find($id);

                if ($locked === null || $locked->status !== SponsorshipPayment::STATUS_AWAITING || $locked->expires_at === null || $locked->expires_at->isFuture()) {
                    return 0;
                }

                $locked->forceFill(['status' => SponsorshipPayment::STATUS_EXPIRED, 'expired_at' => now()])->save();
                $this->audit->log('payment_expired', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference, 'program' => $locked->sponsorship_program_id]);

                return 1;
            });
        }

        return $count;
    }

    // ------------------------------------------------------------------ تكامل E30 (استرداد مباشر لمساهمة مرتبطة بطلب)

    /**
     * يُستدعى من ContributionService::refund داخل معاملته (الصف مقفول): إن كانت المساهمة مجسَّدة من طلب محقَّق ⇒ يصير الطلب «مُستردًا» بالمعاملة نفسها فلا تتعارض الحالتان.
     */
    public function syncRefundFromContribution(SponsorshipContribution $contribution, User $actor, string $reason): void
    {
        $payment = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->lockForUpdate()->first();

        if ($payment === null || $payment->status !== SponsorshipPayment::STATUS_VERIFIED) {
            return;
        }

        $payment->forceFill(['status' => SponsorshipPayment::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor->id])->save();
        $this->audit->log('payment_refunded', $payment, ['public_id' => $payment->public_id, 'reference' => $payment->reference, 'source' => 'contribution_refund', 'reason' => $reason], $actor);
    }

    /** بعد الـcommit في مسار الاسترداد المباشر: إشعار صاحب الطلب (مفتاح دلالي ⇒ لا تكرار مع مسار الاسترداد عبر الخدمة). */
    public function notifyLinkedRefund(SponsorshipContribution $contribution): void
    {
        try {
            $payment = SponsorshipPayment::query()->where('sponsorship_contribution_id', $contribution->id)->where('status', SponsorshipPayment::STATUS_REFUNDED)->first();

            if ($payment !== null) {
                $this->notifier->paymentRefunded($payment);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ================================================================== الأنوية (قابلة لاستدعاء بلا مستخدم من مسار webhook الموقَّع)

    /** @throws SponsorshipException */
    protected function verifyCore(?User $actor, SponsorshipPayment $payment, string|int|null $accountingAmount): SponsorshipPayment
    {
        $before = null;
        $supporterId = 0;
        $contribution = null;
        $materialized = false;

        $verified = DB::transaction(function () use ($actor, $payment, $accountingAmount, &$before, &$supporterId, &$contribution, &$materialized) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            // إعادة Verify (زر مكرر/إعادة محاولة مهمة): لا أثر ثانٍ.
            if ($locked->status === SponsorshipPayment::STATUS_VERIFIED && $locked->sponsorship_contribution_id !== null) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يمكن التحقق من طلب في حالة «'.$locked->statusLabel().'».');
            }

            $owner = User::query()->findOrFail($locked->user_id);
            $program = SponsorshipProgram::query()->findOrFail($locked->sponsorship_program_id);
            $profile = $this->supporters->ensureForUser($owner, $locked->display_name, ! $locked->is_anonymous, $actor);

            $contribution = new SponsorshipContribution;
            $contribution->forceFill([
                'supporter_profile_id' => $profile->id,
                'sponsorship_program_id' => $program->id,
                'original_amount_minor' => (int) $locked->amount_minor,
                'currency' => $locked->currency,
                'status' => SponsorshipContribution::STATUS_PENDING,
                'is_anonymous' => (bool) $locked->is_anonymous,
                'reference' => $locked->reference,
                'provider' => $locked->provider,
                'external_reference' => $locked->reference,
                'created_by' => $actor?->id,
            ])->save();

            // منطق التأكيد والمعادل المحاسبي كما في E30 تمامًا (أي رفض هنا يتراجع بكل المعاملة).
            $contribution = $this->contributions->confirmLocked($actor, $contribution, $accountingAmount, $before, $supporterId);

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_VERIFIED,
                'verified_at' => now(),
                'verified_by' => $actor?->id,
                'supporter_profile_id' => $profile->id,
                'sponsorship_contribution_id' => $contribution->id,
                'accounting_amount_minor' => (int) $contribution->accounting_amount_minor,
                'accounting_currency' => $contribution->accounting_currency,
            ])->save();

            $this->audit->log('payment_verified', $locked, [
                'public_id' => $locked->public_id, 'reference' => $locked->reference, 'contribution' => $contribution->public_id, 'supporter' => $profile->public_id,
                'amount_minor' => (int) $locked->amount_minor, 'currency' => $locked->currency,
                'accounting_amount_minor' => (int) $contribution->accounting_amount_minor, 'accounting_currency' => $contribution->accounting_currency,
                'normalized' => $locked->currency !== $contribution->accounting_currency,
            ], $actor);
            $this->audit->log('contribution_materialized', $contribution, [
                'public_id' => $contribution->public_id, 'payment' => $locked->public_id, 'reference' => $locked->reference, 'anonymous' => (bool) $contribution->is_anonymous,
            ], $actor);

            $materialized = true;

            return $locked;
        });

        if ($materialized && $contribution !== null) {
            SponsorshipCache::bust();
            $this->contributions->publishChange($contribution, $supporterId, $before, true);
        }

        return $verified;
    }

    /** @throws SponsorshipException */
    protected function rejectCore(?User $actor, SponsorshipPayment $payment, string $failureCode, ?string $message, ?string $reviewNote): SponsorshipPayment
    {
        if (! in_array($failureCode, SponsorshipPayment::FAILURE_CODES, true)) {
            throw new PaymentException('سبب الرفض غير صالح.');
        }

        $message = $this->cleanOptionalText($message, 255, 'الرسالة') ?? (SponsorshipPayment::failureLabels()[$failureCode] ?? null);
        $reviewNote = $this->cleanOptionalText($reviewNote, 500, 'الملاحظة الداخلية');
        $changed = false;

        $updated = DB::transaction(function () use ($actor, $payment, $failureCode, $message, $reviewNote, &$changed) {
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_REJECTED) {
                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new PaymentException('لا يُرفض إلا الطلب المفتوح؛ المحقَّق يُسترد.');
            }

            $locked->forceFill([
                'status' => SponsorshipPayment::STATUS_REJECTED, 'rejected_at' => now(), 'rejected_by' => $actor?->id,
                'failure_code' => $failureCode, 'user_message' => $message, 'review_note' => $reviewNote,
            ])->save();
            $this->audit->log('payment_rejected', $locked, ['public_id' => $locked->public_id, 'reference' => $locked->reference, 'code' => $failureCode], $actor);
            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->notifier->paymentRejected($updated);
        }

        return $updated;
    }

    /** @throws SponsorshipException */
    protected function refundCore(?User $actor, SponsorshipPayment $payment, string $reason): SponsorshipPayment
    {
        $before = null;
        $supporterId = 0;
        $contribution = null;
        $changed = false;

        // ترتيب الأقفال: المساهمة ثم الطلب (مطابق لمسار E30 المباشر ⇒ لا انعكاس أقفال).
        $contributionId = (int) SponsorshipPayment::query()->whereKey($payment->id)->value('sponsorship_contribution_id');

        $updated = DB::transaction(function () use ($actor, $payment, $reason, $contributionId, &$before, &$supporterId, &$contribution, &$changed) {
            $lockedContribution = $contributionId > 0 ? SponsorshipContribution::query()->lockForUpdate()->find($contributionId) : null;
            $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === SponsorshipPayment::STATUS_REFUNDED) {
                return $locked;
            }

            if ($locked->status !== SponsorshipPayment::STATUS_VERIFIED || $lockedContribution === null) {
                throw new PaymentException('يُسترد الطلب المحقَّق فقط.');
            }

            if ($lockedContribution->isConfirmed()) {
                $contribution = $this->contributions->refundLocked($actor, $lockedContribution, $reason, $before, $supporterId);
            }

            $locked->forceFill(['status' => SponsorshipPayment::STATUS_REFUNDED, 'refunded_at' => now(), 'refunded_by' => $actor?->id])->save();
            $this->audit->log('payment_refunded', $locked, [
                'public_id' => $locked->public_id, 'reference' => $locked->reference, 'contribution' => $lockedContribution->public_id, 'reason' => $reason, 'source' => 'payment',
            ], $actor);
            $changed = true;

            return $locked;
        });

        if ($changed) {
            SponsorshipCache::bust();

            if ($contribution !== null) {
                $this->contributions->publishChange($contribution, $supporterId, $before, false);
            }

            $this->notifier->paymentRefunded($updated);
        }

        return $updated;
    }

    // ================================================================== مساعدات

    protected function assertOwner(User $user, SponsorshipPayment $payment): void
    {
        if ((int) $payment->user_id !== (int) $user->id) {
            throw new PaymentException('هذا الطلب لا يخصّك.');
        }
    }

    /**
     * يكتب حقول الإثبات على الصف المقفول ويُرجع مسار الملف القديم ليُحذف **بعد** الـcommit.
     *
     * @param  array{path: string, mime: string, size: int, sha256: string}  $stored
     */
    protected function applyProof(SponsorshipPayment $locked, array $stored): ?string
    {
        $old = $locked->proof_path;

        $locked->forceFill([
            'proof_path' => $stored['path'], 'proof_mime' => $stored['mime'], 'proof_size' => $stored['size'], 'proof_sha256' => $stored['sha256'],
            'proof_uploaded_at' => now(), 'proof_purged_at' => null,
        ])->save();

        return $old;
    }

    protected function cancelSystem(SponsorshipPayment $payment, string $code): void
    {
        try {
            DB::transaction(function () use ($payment, $code) {
                $locked = SponsorshipPayment::query()->lockForUpdate()->findOrFail($payment->id);

                if ($locked->status === SponsorshipPayment::STATUS_AWAITING) {
                    $locked->forceFill(['status' => SponsorshipPayment::STATUS_CANCELLED, 'cancelled_at' => now(), 'failure_code' => substr($code, 0, 32)])->save();
                    $this->audit->log('payment_cancelled', $locked, ['public_id' => $locked->public_id, 'by' => 'system', 'code' => $code]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function findByRequestKey(User $user, string $key): ?SponsorshipPayment
    {
        return SponsorshipPayment::query()->where('user_id', $user->id)->where('request_key', $key)->first();
    }

    protected function cleanRequestKey(?string $key): ?string
    {
        $key = trim((string) $key);

        return $key !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) === 1 ? $key : null;
    }

    /** نص عادي اختياري (NoMarkup + حدّ طول) أو null. @throws PaymentException */
    protected function cleanOptionalText(?string $value, int $max, string $label): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $validator = Validator::make(['v' => $value], ['v' => ['string', 'min:2', 'max:'.$max, new NoMarkup]], [], ['v' => $label]);

        if ($validator->fails()) {
            throw new PaymentException((string) $validator->errors()->first());
        }

        return $value;
    }
}
