<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\SponsorshipPayment;
use App\Models\User;
use App\Services\AhjiyatPass\Concerns\AuthorizesPass;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProvider;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceRefundService;
use App\Services\Commerce\CommerceSyncService;
use App\Services\Commerce\DigitalPurchaseService;
use App\Services\OperationalAuditService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentProofService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E40 - شراء المسار المميز (مرة واحدة لكل مسار، ليس اشتراكًا ولا تجديدًا آليًا). **السلطة الوحيدة** على فتح المميز هي التحقق المالي (verify) بصلاحية مخوَّلة:
 *  - السعر والعملة والاسم تُقرأ من تعريف المسار على الخادم وتُلتقط لقطةً في الطلب؛ لا شيء منها يأتي من العميل. عدد صحيح بالوحدة الصغرى، عملة واحدة، بلا تحويل عملات.
 *  - لا يفتح المميز: نقر زر «شراء»، ولا إنشاء الطلب، ولا تقديم الإثبات، ولا أي علم من العميل. verify وحده يكتب premium_unlocked_at تحت قفل.
 *  - طلب مفتوح واحد ومحقَّق واحد لكل مستخدم/مسار بقيد قاعدة (open_key unique) + طلب متكرر بنفس request_key يرجع الطلب نفسه (idempotent).
 *  - المميز بأثر رجعي: عند التحقق تصير مكافآت الشرائح المبلوغة قابلة للاستلام (مشتقّة) دون أي منح تلقائي أو نقاط.
 *  - الاسترداد: بلا مطالبات مميزة ⇒ سحب آمن وتدقيق؛ بعد مطالبات ⇒ يلزم تأكيد صريح ومراجعة مالية، **لا** سحب صامت للعناصر الممنوحة (تبقى، ويُسجَّل عددها).
 *  - لا بيانات بطاقات: مرجع التحويل/الملاحظة نص قصير مُنقّى، ويُرفض ما يشبه رقم بطاقة (Luhn). الإثبات ملف خاص بقواعد E32.
 *  - الإشعارات بعد الـcommit وبعزل أخطاء. السجل (digital_purchase_events + OperationalAudit) ثابت.
 *
 * E41: هذه الخدمة تبقى **واجهة مجال المسار المميز** (طلب/تقديم/إلغاء/تحقق/رفض/استرداد/انتهاء) لكنها لم تعد بوابة دفع: مزوّد الشراء من سجل المزوّدين (الافتراضي أو البديل اليدوي) ويُجمَّد وقت الإنشاء،
 *  و**منطق الفتح/السحب** صار في مكان واحد (PassPremiumProductHandler عبر DigitalPurchaseService) يشترك فيه التحقق اليدوي ومسار المزوّد الموثوق (webhook/مزامنة) — فلا نسخة ثانية.
 *  الدفع المستضافة: بدء الجلسة في CommerceCheckoutService، وأحداث المزوّد في CommerceWebhookService، والاسترداد عبر المزوّد في CommerceRefundService.
 */
class PassPremiumService
{
    use AuthorizesPass;

    public const REFUND_REASONS = [
        'user_request' => 'طلب المشتري',
        'duplicate' => 'دفع مكرر',
        'payment_issue' => 'مشكلة في الدفع',
        'fraud' => 'اشتباه احتيال',
        'error' => 'خطأ إداري',
        'other' => 'سبب آخر',
    ];

    public function __construct(
        protected CommercePaymentProviderRegistry $providers,
        protected DigitalPurchaseService $core,
        protected CommerceRefundService $refunds,
        protected CommerceSyncService $sync,
        protected PassProgressService $progress,
        protected PassNotifier $notifier,
        protected PaymentProofService $proofs,
        protected OperationalAuditService $audit,
    ) {}

    // ───────────────────────── اللاعب ─────────────────────────

    /**
     * ينشئ طلب شراء المميز أو يُرجع الطلب المفتوح القائم (idempotent). لا يفتح شيئًا.
     *
     * @throws PassException
     */
    public function request(User $user, AhjiyatPass $pass, ?string $requestKey = null, bool $manualOnly = false): DigitalPurchase
    {
        if (! (bool) config('ahjiyat_pass.enabled', true)) {
            throw new PassException('disabled', 'مسار الأحجيات غير متاح حاليًا.');
        }

        if ($user->is_frozen) {
            throw new PassException('frozen', 'حسابك مقيَّد؛ لا يمكن شراء المسار المميز.');
        }

        if (! $this->anyProviderEnabled($manualOnly)) {
            throw new PassException('provider_disabled', 'طريقة الدفع غير متاحة حاليًا.');
        }

        $requestKey = $this->cleanKey($requestKey);

        // السعر والنافذة من الخادم: نعيد قراءة المسار من قاعدة البيانات (لا من كاش ولا من العميل).
        $fresh = AhjiyatPass::query()->find($pass->getKey());

        if ($fresh === null || ! $fresh->isPremiumSalesOpen()) {
            throw new PassException('sales_closed', 'شراء المسار المميز غير متاح الآن.');
        }

        if (PassRules::priceProblem((int) $fresh->premium_price_minor, (string) $fresh->currency) !== null || ! PassRules::currencyAllowed((string) $fresh->currency)) {
            throw new PassException('price_invalid', 'سعر المسار المميز غير مضبوط بشكل صحيح. تواصل مع الدعم.');
        }

        // مزوّد الشراء الجديد (الافتراضي المفعَّل الذي يقبل العملة وإلا البديل اليدوي) ويُجمَّد في الطلب. تقاطع العملات: المزوّد ∩ التجارة ∩ المسار.
        try {
            $provider = $this->providers->forNewPurchase((string) $fresh->currency, $manualOnly);
        } catch (CommerceException) {
            throw new PassException('price_invalid', 'سعر المسار المميز غير مضبوط بشكل صحيح. تواصل مع الدعم.');
        }

        if ($requestKey !== null) {
            $byKey = DigitalPurchase::query()->where('user_id', $user->getKey())->where('request_key', $requestKey)->first();

            if ($byKey !== null) {
                return $byKey;
            }
        }

        $progress = $this->progress->find($user, $fresh);

        if ($progress?->hasPremium()) {
            throw new PassException('already_owned', 'أنت تملك المسار المميز بالفعل.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $keyed = $this->keyedPurchase($user, $fresh);

            if ($keyed !== null) {
                if ($keyed->isVerified()) {
                    throw new PassException('already_owned', 'أنت تملك المسار المميز بالفعل.');
                }

                if (! $this->expireIfStale($keyed)) {
                    return $keyed->fresh();
                }
            }

            try {
                return DB::transaction(function () use ($user, $fresh, $requestKey, $provider) {
                    $purchase = new DigitalPurchase;
                    $purchase->forceFill([
                        'user_id' => $user->getKey(),
                        'product_type' => DigitalPurchase::PRODUCT_PASS_PREMIUM,
                        'product_id' => $fresh->getKey(),
                        'product_public_id' => $fresh->public_id,
                        'product_name' => mb_substr((string) $fresh->name, 0, 160),
                        'amount_minor' => (int) $fresh->premium_price_minor,
                        'currency' => (string) $fresh->currency,
                        'provider' => $provider->key(),
                        'status' => DigitalPurchase::STATUS_AWAITING,
                        'request_key' => $requestKey,
                        'reference_code' => $this->newReference(),
                        'expires_at' => now()->addHours(max(1, $provider->supportsRedirect() ? (int) config('commerce.checkout.purchase_expiry_hours', 24) : (int) config('ahjiyat_pass.purchase.expiry_hours', 48))),
                    ])->save();

                    $this->event($purchase, 'created', null, DigitalPurchase::STATUS_AWAITING, $user, null);

                    return $purchase;
                });
            } catch (UniqueConstraintViolationException) {
                // سباق: طلب متزامن فاز بـopen_key أو request_key أو المرجع؛ نعيد التقييم (يُرجع الفائز أو يعيد توليد المرجع).
                if ($requestKey !== null) {
                    $byKey = DigitalPurchase::query()->where('user_id', $user->getKey())->where('request_key', $requestKey)->first();

                    if ($byKey !== null) {
                        return $byKey;
                    }
                }
            }
        }

        throw new PassException('busy', 'تعذّر إنشاء الطلب الآن، أعد المحاولة بعد لحظات.');
    }

    /**
     * تقديم مرجع التحويل (وإثبات اختياري/إلزامي) للمراجعة. لا يفتح المميز.
     *
     * @throws PassException
     */
    public function submit(User $user, DigitalPurchase $purchase, ?string $transferReference, ?string $payerNote, ?UploadedFile $proof = null): DigitalPurchase
    {
        $this->assertOwner($user, $purchase);

        if ($user->is_frozen) {
            throw new PassException('frozen', 'حسابك مقيَّد؛ لا يمكن تقديم الدفع.');
        }

        // E41: الطلب المستضاف يُدفع عند المزوّد ويتأكد بتوقيعه؛ لا مرجع تحويل ولا إثبات يدوي له (يفتح المميز مسارًا غير مسار المزوّد!).
        if ($purchase->isHosted()) {
            throw new PassException('hosted_purchase', 'هذا الطلب يُدفع عبر صفحة الدفع الإلكتروني ويتأكد تلقائيًا؛ لا حاجة لتقديم مرجع تحويل.');
        }

        $reference = $this->cleanText($transferReference, (int) config('ahjiyat_pass.purchase.transfer_reference_max', 120));
        $note = $this->cleanText($payerNote, (int) config('ahjiyat_pass.purchase.payer_note_max', 255));

        foreach ([$reference, $note] as $text) {
            if ($text !== null && $this->looksLikeCardNumber($text)) {
                throw new PassException('card_data', 'لا تُدخل أرقام بطاقات أو رموزًا سرية. أدخل مرجع التحويل فقط (يمكنك إضافة حروف لتمييزه).');
            }
        }

        if ($reference === null && $proof === null) {
            throw new PassException('evidence_required', 'أدخل مرجع التحويل أو أرفق إثباتًا.');
        }

        if ($proof === null && $this->providerFor($purchase)?->proofRequired()) {
            throw new PassException('proof_required', 'إثبات التحويل مطلوب لإتمام الطلب.');
        }

        $stored = null;

        if ($proof !== null) {
            try {
                $stored = $this->proofs->store($proof);
            } catch (PaymentException $e) {
                throw new PassException('proof_invalid', $e->getMessage());
            }
        }

        try {
            $result = DB::transaction(function () use ($user, $purchase, $reference, $note, $stored) {
                $locked = DigitalPurchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== DigitalPurchase::STATUS_AWAITING) {
                    throw new PassException('invalid_state', $locked->status === DigitalPurchase::STATUS_SUBMITTED ? 'قدّمت هذا الطلب بالفعل وهو قيد المراجعة.' : 'لا يمكن تقديم هذا الطلب في حالته الحالية.');
                }

                if ($locked->expires_at !== null && $locked->expires_at->lte(now())) {
                    $this->expireLocked($locked); // يُلتزَم بالانتهاء (لا استثناء داخل المعاملة كي لا يُتراجَع عنه).

                    return null;
                }

                $attributes = [
                    'transfer_reference' => $reference,
                    'payer_note' => $note,
                    'status' => DigitalPurchase::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                ];

                if ($stored !== null) {
                    $attributes += [
                        'proof_path' => $stored['path'],
                        'proof_mime' => $stored['mime'],
                        'proof_size' => $stored['size'],
                        'proof_sha256' => $stored['sha256'],
                        'proof_uploaded_at' => now(),
                    ];
                }

                $locked->forceFill($attributes)->save();
                $this->event($locked, 'submitted', DigitalPurchase::STATUS_AWAITING, DigitalPurchase::STATUS_SUBMITTED, $user, $stored !== null ? 'proof' : null);

                return $locked;
            });

            if ($result === null) {
                throw new PassException('expired', 'انتهت صلاحية هذا الطلب. ابدأ طلبًا جديدًا.');
            }

            return $result;
        } catch (\Throwable $e) {
            // لا يبقى ملف يتيم إن فشلت المعاملة.
            if ($stored !== null) {
                $this->proofs->delete($stored['path']);
            }

            throw $e;
        }
    }

    /** @throws PassException */
    public function cancelByOwner(User $user, DigitalPurchase $purchase): DigitalPurchase
    {
        $this->assertOwner($user, $purchase);

        return DB::transaction(function () use ($user, $purchase) {
            $locked = DigitalPurchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new PassException('invalid_state', 'لا يمكن إلغاء هذا الطلب في حالته الحالية.');
            }

            $from = $locked->status;
            $locked->forceFill(['status' => DigitalPurchase::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            $this->event($locked, 'cancelled', $from, DigitalPurchase::STATUS_CANCELLED, $user, 'owner');

            return $locked;
        });
    }

    // ───────────────────────── المراجعة المالية ─────────────────────────

    /**
     * التحقق المالي بمراجع مخوَّل: يتطلب طلبًا مقدَّمًا (يدوي)، أو طلبًا مستضافًا عليه علم مراجعة مالية (قبول صريح مع سبب موثَّق).
     * يمرّ بمسار التحقق الموحَّد نفسه الذي يستعمله المزوّد الموثوق (DigitalPurchaseService::verifyLocked) — لا نسخة ثانية من فتح المميز. تحت قفل (الطلب ثم التقدّم).
     *
     * @throws PassException
     */
    public function verify(User $actor, DigitalPurchase $purchase, ?string $reviewNote = null): DigitalPurchase
    {
        $this->authorizePass($actor, 'passes.verify-purchases');

        $verified = DB::transaction(function () use ($actor, $purchase, $reviewNote) {
            $locked = $this->core->lock($purchase);

            $reviewed = $locked->status === DigitalPurchase::STATUS_AWAITING && $locked->isHosted() && $locked->needsReview();

            if ($locked->status !== DigitalPurchase::STATUS_SUBMITTED && ! $reviewed) {
                throw new PassException('invalid_state', 'يمكن التحقق من الطلبات المقدَّمة فقط.');
            }

            $note = $this->cleanText($reviewNote, 1000);

            if ($reviewed && ($note === null || mb_strlen($note) < 5)) {
                throw new PassException('note_required', 'اكتب سبب قبول هذا الدفع رغم علم المراجعة (5 أحرف على الأقل) للتوثيق.');
            }

            try {
                $this->core->verifyLocked($locked, $actor, $reviewed ? 'review' : 'manual', $note);
            } catch (CommerceException $e) {
                throw new PassException($e->reason, $e->getMessage(), $e->details);
            }

            return $locked;
        });

        $this->notifier->premiumUnlocked($verified);

        return $verified;
    }

    /** @throws PassException */
    public function reject(User $actor, DigitalPurchase $purchase, string $decisionMessage, ?string $reviewNote = null): DigitalPurchase
    {
        $this->authorizePass($actor, 'passes.verify-purchases');

        $message = $this->cleanText($decisionMessage, (int) config('ahjiyat_pass.purchase.decision_message_max', 300));

        if ($message === null) {
            throw new PassException('message_required', 'اكتب سبب الرفض ليراه المشتري.');
        }

        $rejected = DB::transaction(function () use ($actor, $purchase, $message, $reviewNote) {
            $locked = DigitalPurchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new PassException('invalid_state', 'لا يمكن رفض هذا الطلب في حالته الحالية.');
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => DigitalPurchase::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $actor->getKey(),
                'decision_message' => $message,
                'review_note' => $this->cleanText($reviewNote, 1000),
            ])->save();

            $this->event($locked, 'rejected', $from, DigitalPurchase::STATUS_REJECTED, $actor, null);
            $this->audit->log('digital_purchase_rejected', $locked, ['reference' => $locked->reference_code, 'from' => $from], $actor);

            return $locked;
        });

        $this->notifier->purchaseRejected($rejected);

        return $rejected;
    }

    /**
     * استرداد **كامل** لشراء محقَّق. مزوّد يدعم الاسترداد ⇒ يُنفَّذ عبر المزوّد أولًا (لا refunded قبل تأكيده — E41 M2)؛ اليدوي ⇒ تسجيل داخل المنصة بعد استرداد خارجي (E40 كما هو).
     * المميز يُسحب آمنًا؛ إن وُجدت مطالبات مميزة يلزم `$confirmClaimed`: العناصر الممنوحة **تبقى** (لا سحب صامت) ويُسجَّل عددها لمراجعة مالية/تشغيلية.
     *
     * @throws PassException
     */
    public function refund(User $actor, DigitalPurchase $purchase, string $reason, string $note, bool $confirmClaimed = false): DigitalPurchase
    {
        $this->authorizePass($actor, 'passes.refund-purchases');

        if (! array_key_exists($reason, self::REFUND_REASONS)) {
            throw new PassException('invalid_reason', 'سبب الاسترداد غير صالح.');
        }

        $cleanNote = $this->cleanText($note, 1000);

        if ($cleanNote === null || mb_strlen($cleanNote) < 5) {
            throw new PassException('note_required', 'اكتب ملاحظة الاسترداد (5 أحرف على الأقل) للتوثيق.');
        }

        try {
            if ($this->refunds->supports($purchase)) {
                // المزوّد أكّد الاسترداد فعلًا (حدث/مزامنة) والطلب محقَّق بسبب عناصر مستلَمة: تسجيل محلي بقرار صريح بلا نداء مزوّد ثانٍ.
                if ($purchase->providerState() === CommerceProviderState::Refunded) {
                    return $this->refunds->recordProviderRefunded($actor, $purchase, $reason, $cleanNote, $confirmClaimed);
                }

                return $this->refunds->refundVerified($actor, $purchase, $reason, $cleanNote, $confirmClaimed);
            }

            return DB::transaction(function () use ($actor, $purchase, $reason, $cleanNote, $confirmClaimed) {
                $locked = $this->core->lock($purchase);
                $this->core->refundLocked($locked, $actor, $reason, $cleanNote, $confirmClaimed, 'manual');

                return $locked;
            });
        } catch (CommerceException $e) {
            throw new PassException($e->reason, $e->getMessage(), $e->details);
        }
    }

    /**
     * استرداد دفعة غير مطابقة/متأخرة (علم مراجعة قائم والطلب ليس محقَّقًا) عبر المزوّد ثم إغلاق العلم.
     *
     * @throws PassException
     */
    public function refundUnmatched(User $actor, DigitalPurchase $purchase, string $note): DigitalPurchase
    {
        $this->authorizePass($actor, 'passes.refund-purchases');

        $cleanNote = $this->cleanText($note, 1000);

        if ($cleanNote === null || mb_strlen($cleanNote) < 5) {
            throw new PassException('note_required', 'اكتب ملاحظة الاسترداد (5 أحرف على الأقل) للتوثيق.');
        }

        try {
            return $this->refunds->refundUnmatched($actor, $purchase, $cleanNote);
        } catch (CommerceException $e) {
            throw new PassException($e->reason, $e->getMessage(), $e->details);
        }
    }

    /**
     * يغلق علم مراجعة مالية بقرار «تجاهل» موثَّق (عولج الأمر خارج المنصة أو لا إجراء مطلوب). لا يغيّر حالة الدفع ولا يفتح المميز؛ التحقق/الاسترداد يمران بإجراءيهما.
     *
     * @throws PassException
     */
    public function dismissReview(User $actor, DigitalPurchase $purchase, string $note): DigitalPurchase
    {
        $this->authorizePass($actor, 'passes.verify-purchases');

        $cleanNote = $this->cleanText($note, 1000);

        if ($cleanNote === null || mb_strlen($cleanNote) < 5) {
            throw new PassException('note_required', 'اكتب سبب إغلاق المراجعة (5 أحرف على الأقل) للتوثيق.');
        }

        try {
            return DB::transaction(function () use ($actor, $purchase, $cleanNote) {
                $locked = $this->core->lock($purchase);
                $this->core->resolveLocked($locked, $actor, 'dismissed', $cleanNote);

                return $locked;
            });
        } catch (CommerceException $e) {
            throw new PassException($e->reason, $e->getMessage(), $e->details);
        }
    }

    /** مزامنة فورية لحالة طلب مستضاف من المزوّد (إن دعم الاستعلام) عبر المعالج نفسه. @return array{result: string, detail: ?string, state: ?string} */
    public function syncNow(User $actor, DigitalPurchase $purchase): array
    {
        $this->authorizePass($actor, 'passes.verify-purchases');

        $outcome = $this->sync->sync($purchase);
        $this->audit->log('commerce_manual_sync', $purchase, ['reference' => $purchase->reference_code, 'result' => $outcome['result']], $actor);

        return $outcome;
    }

    /** يُنهي صلاحية الطلبات «بانتظار الدفع» المتأخرة فقط (المقدَّم/المحقَّق لا ينتهي آليًا). @return int عدد المنتهية */
    public function expireStale(?int $limit = null): int
    {
        $limit ??= (int) config('ahjiyat_pass.purchase.expire_batch', 200);
        $expired = 0;

        DigitalPurchase::query()
            ->where('status', DigitalPurchase::STATUS_AWAITING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('review_flag')->orWhereNotNull('review_resolved_at')) // E41: طلب عليه علم مراجعة مالية قائم لا ينتهي آليًا (قرار مالي أولًا).
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->each(function (int $id) use (&$expired): void {
                $expired += DB::transaction(function () use ($id): int {
                    $locked = DigitalPurchase::query()->whereKey($id)->lockForUpdate()->first();

                    if ($locked === null || $locked->status !== DigitalPurchase::STATUS_AWAITING || $locked->expires_at === null || $locked->expires_at->gt(now()) || $locked->needsReview()) {
                        return 0;
                    }

                    $this->expireLocked($locked);

                    return 1;
                });
            });

        return $expired;
    }

    /** تنزيل إثبات خاص لمراجع مخوَّل (attachment + nosniff + no-store). يُدقَّق عرضه. */
    public function proofDownload(User $actor, DigitalPurchase $purchase): StreamedResponse
    {
        $this->authorizePass($actor, 'passes.view-purchases');

        abort_unless($purchase->hasProof() && $this->proofs->ownedPath($purchase->proof_path) && $this->proofs->disk()->exists((string) $purchase->proof_path), 404);

        $extension = (string) (((array) config('payments.proof.mimes'))[(string) $purchase->proof_mime] ?? 'bin');
        $this->audit->log('digital_purchase_proof_viewed', $purchase, ['reference' => $purchase->reference_code], $actor);

        return $this->proofs->disk()->download((string) $purchase->proof_path, 'proof-'.$purchase->reference_code.'.'.$extension, [
            'Content-Type' => (string) $purchase->proof_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** مؤشر احتيال للمراجع: كم طلبًا آخر (مسار/رعاية) استخدم الإثبات نفسه؟ */
    public function duplicateProofCount(DigitalPurchase $purchase): int
    {
        if (blank($purchase->proof_sha256)) {
            return 0;
        }

        return DigitalPurchase::query()->where('proof_sha256', $purchase->proof_sha256)->whereKeyNot($purchase->getKey())->count()
            + SponsorshipPayment::query()->where('proof_sha256', $purchase->proof_sha256)->count();
    }

    // ───────────────────────── مساعدات ─────────────────────────

    protected function assertOwner(User $user, DigitalPurchase $purchase): void
    {
        if ((int) $purchase->user_id !== (int) $user->getKey()) {
            throw new PassException('forbidden', 'لا تملك صلاحية على هذا الطلب.');
        }
    }

    protected function keyedPurchase(User $user, AhjiyatPass $pass): ?DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('open_key', DigitalPurchase::PRODUCT_PASS_PREMIUM.':'.$pass->getKey().':'.$user->getKey())
            ->first();
    }

    /** ينهي صلاحية طلب «بانتظار الدفع» متأخر عند اللمس. @return bool true إن انتهى الآن (فيُنشأ بديل). */
    protected function expireIfStale(DigitalPurchase $purchase): bool
    {
        if ($purchase->status !== DigitalPurchase::STATUS_AWAITING || $purchase->expires_at === null || $purchase->expires_at->gt(now())) {
            return false;
        }

        return DB::transaction(function () use ($purchase): bool {
            $locked = DigitalPurchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DigitalPurchase::STATUS_AWAITING || $locked->expires_at === null || $locked->expires_at->gt(now()) || $locked->needsReview()) {
                return false;
            }

            $this->expireLocked($locked);

            return true;
        });
    }

    protected function expireLocked(DigitalPurchase $locked): void
    {
        $locked->forceFill(['status' => DigitalPurchase::STATUS_EXPIRED, 'expired_at' => now()])->save();
        $this->event($locked, 'expired', DigitalPurchase::STATUS_AWAITING, DigitalPurchase::STATUS_EXPIRED, null, null);
    }

    protected function event(DigitalPurchase $purchase, string $event, ?string $from, ?string $to, ?User $actor, ?string $note): void
    {
        $this->core->event($purchase, $event, $from, $to, $actor, $note);
    }

    /** مزوّد الطلب المجمَّد وقت الإنشاء، أو null إن لم يعد مسجَّلًا (لا نفترض مزوّدًا آخر). */
    protected function providerFor(DigitalPurchase $purchase): ?CommercePaymentProvider
    {
        return $this->providers->has($purchase->provider) ? $this->providers->get($purchase->provider) : null;
    }

    protected function anyProviderEnabled(bool $manualOnly): bool
    {
        $keys = $manualOnly ? ['manual'] : array_values(array_unique([$this->providers->defaultKey(), $this->providers->fallbackKey()]));

        foreach ($keys as $key) {
            if ($this->providers->has($key) && $this->providers->get($key)->isEnabled()) {
                return true;
            }
        }

        return false;
    }

    protected function newReference(): string
    {
        $alphabet = (string) config('ahjiyat_pass.purchase.reference_alphabet', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $length = max(6, min(16, (int) config('ahjiyat_pass.purchase.reference_length', 8)));
        $prefix = strtoupper((string) config('ahjiyat_pass.purchase.reference_prefix', 'PASS'));
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            $reference = $prefix.'-'.$code;

            if (! DigitalPurchase::query()->where('reference_code', $reference)->exists()) {
                return $reference;
            }
        }

        return $prefix.'-'.strtoupper(bin2hex(random_bytes(max(4, intdiv($length, 2)))));
    }

    protected function cleanKey(?string $key): ?string
    {
        return ($key !== null && preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $key) === 1) ? $key : null;
    }

    /** نص حر قصير آمن: بلا وسوم ولا محارف تحكّم، مسافات موحَّدة، مقتطع. null إن فرغ. */
    protected function cleanText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value))));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    /** يكشف نصًا يشبه رقم بطاقة (13–19 رقمًا بمسافات/شرطات وفق Luhn) لمنع إدخاله خطأً. */
    protected function looksLikeCardNumber(string $text): bool
    {
        if (preg_match_all('/(?<![0-9])(?:[0-9][ \-]?){13,19}(?![0-9])/', $text, $matches) < 1) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);

            if (strlen($digits) < 13 || strlen($digits) > 19) {
                continue;
            }

            $sum = 0;
            $alt = false;

            for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                $n = (int) $digits[$i];

                if ($alt) {
                    $n *= 2;
                    $n = $n > 9 ? $n - 9 : $n;
                }

                $sum += $n;
                $alt = ! $alt;
            }

            if ($sum % 10 === 0) {
                return true;
            }
        }

        return false;
    }
}
