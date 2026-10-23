<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\CreatorFinance\Concerns\AuthorizesCreatorFinance;
use App\Services\OperationalAuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E39 - بوابة التسييل (منفصلة تمامًا عن CreatorProfile.status) + تعليق الدفعات.
 *
 *  - التفعيل: ملف معتمَد وحساب غير مجمَّد فقط. ينشئ حساب أرباح بعملة المحاسبة الحالية (أو يستأنف حسابًا موقوفًا). لا يمنح مبلغًا.
 *  - الإيقاف المؤقت: يمنع استحقاقات جديدة وطلبات واعتماد دفعات؛ الأرصدة تبقى كما هي ولا يُمسّ الدفتر.
 *  - التعليق (hold): يمنع طلب/اعتماد/صرف الدفعات فقط ولا يمنع الاستحقاق. ربط حالة E38 اختياري (رقم الحالة) ولا ينسخ أي تفصيل منها.
 *    **بلاغ أو علامة احتيال وحدهما لا يُنشئان تعليقًا** (لا مسار آلي هنا؛ التعليق قرار بشري بسبب من قائمة مغلقة).
 *  - لا أحد يعالج حسابه الخاص. الصلاحيات تُفرض داخل الخدمة.
 */
class CreatorMonetizationService
{
    use AuthorizesCreatorFinance;

    public function __construct(protected OperationalAuditService $audit) {}

    public function enable(CreatorProfile $profile, User $actor, ?string $note = null): CreatorEarningAccount
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-monetization');

        if ((int) $actor->getKey() === (int) $profile->user_id) {
            throw new CreatorFinanceException('لا يجوز لك تفعيل التسييل لحسابك الخاص.');
        }

        $note = $this->cleanNote($note);

        try {
            return DB::transaction(function () use ($profile, $actor, $note) {
                // ترتيب الأقفال: حساب الأرباح قبل ملف المبدع دائمًا (الإقفال والدفعات تقفل الحساب أولًا)، فلا انعكاس يؤدي إلى deadlock.
                // حساب موجود ⇒ نقفل صفه وحده (صف موجود: لا قفل فجوة) ونقرأ الملف والمستخدم قراءةً عادية.
                // حساب غير موجود ⇒ نقفل الملف أولًا (نقطة تسلسل لمن يفعّلون المبدع نفسه معًا) ثم ننشئ الحساب، فلا يتسابق مُدرِجان على فجوة.
                $existingId = CreatorEarningAccount::query()->where('creator_profile_id', $profile->id)->value('id');

                if ($existingId !== null) {
                    $account = CreatorEarningAccount::query()->whereKey($existingId)->lockForUpdate()->firstOrFail();
                    $profile = CreatorProfile::query()->findOrFail($profile->id);
                } else {
                    $profile = CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                    $account = CreatorEarningAccount::query()->where('creator_profile_id', $profile->id)->lockForUpdate()->first();
                }

                $owner = User::query()->find($profile->user_id);

                if (! $profile->isApproved()) {
                    throw new CreatorFinanceException('لا يُفعَّل التسييل إلا لمبدع معتمَد.');
                }

                if ($owner === null || $owner->is_frozen) {
                    throw new CreatorFinanceException('لا يُفعَّل التسييل لحساب مجمَّد.');
                }

                if ($account === null) {
                    $account = new CreatorEarningAccount;
                    $account->forceFill([
                        'creator_profile_id' => $profile->id,
                        'user_id' => $profile->user_id,
                        'currency' => CreatorMoney::currency(),
                        'monetization_status' => CreatorEarningAccount::STATUS_ENABLED,
                        'monetization_enabled_at' => now(),
                        'monetization_changed_at' => now(),
                        'monetization_changed_by' => $actor->id,
                    ])->save();
                    $this->audit->log('creator_monetization_enabled', $account, ['creator_profile' => $profile->id, 'note' => $note], $actor);

                    return $account;
                }

                if ($account->isEnabled()) {
                    return $account; // idempotent
                }

                $account->forceFill([
                    'monetization_status' => CreatorEarningAccount::STATUS_ENABLED,
                    'monetization_changed_at' => now(),
                    'monetization_changed_by' => $actor->id,
                ])->save();
                $this->audit->log('creator_monetization_resumed', $account, ['note' => $note], $actor);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            // سباق تفعيل متزامن: الحساب موجود الآن.
            return CreatorEarningAccount::query()->where('creator_profile_id', $profile->id)->firstOrFail();
        }
    }

    public function pause(CreatorEarningAccount $account, User $actor, string $note): CreatorEarningAccount
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-monetization');
        $this->assertNotOwnAccount($actor, $account);
        $note = $this->requireNote($note, 8);

        return DB::transaction(function () use ($account, $actor, $note) {
            $locked = CreatorEarningAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEnabled()) {
                return $locked; // idempotent
            }

            $locked->forceFill([
                'monetization_status' => CreatorEarningAccount::STATUS_PAUSED,
                'monetization_changed_at' => now(),
                'monetization_changed_by' => $actor->id,
            ])->save();
            $this->audit->log('creator_monetization_paused', $locked, ['note' => $note], $actor);

            return $locked;
        });
    }

    /** @param string|null $caseNumber رقم حالة إشراف E38 (اختياري؛ يُتحقق من وجودها فقط). */
    public function placeHold(CreatorEarningAccount $account, User $actor, string $reasonCode, string $note, ?string $caseNumber = null): CreatorEarningAccount
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-holds');
        $this->assertNotOwnAccount($actor, $account);

        if (! array_key_exists($reasonCode, (array) config('creator_monetization.holds.reasons'))) {
            throw new CreatorFinanceException('سبب التعليق غير معتمد.');
        }

        $note = $this->requireNote($note, (int) config('creator_monetization.holds.note_min'), (int) config('creator_monetization.holds.note_max'));
        $caseNumber = $caseNumber !== null ? trim($caseNumber) : null;
        $case = null;

        if ($caseNumber !== null && $caseNumber !== '') {
            $case = ModerationCase::query()->where('case_number', $caseNumber)->first();

            if ($case === null) {
                throw new CreatorFinanceException('رقم حالة الإشراف غير موجود.');
            }
        }

        return DB::transaction(function () use ($account, $actor, $reasonCode, $note, $case) {
            $locked = CreatorEarningAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($locked->isOnHold()) {
                if ($locked->hold_reason_code === $reasonCode) {
                    return $locked; // idempotent
                }

                throw new CreatorFinanceException('الحساب معلَّق بالفعل؛ ارفع التعليق الحالي أولًا.');
            }

            $locked->forceFill([
                'hold_reason_code' => $reasonCode,
                'hold_note' => $note,
                'hold_placed_at' => now(),
                'hold_placed_by' => $actor->id,
                'hold_case_id' => $case?->id,
            ])->save();
            $this->audit->log('creator_payout_hold_placed', $locked, ['reason' => $reasonCode, 'note' => $note, 'case_number' => $case?->case_number], $actor);

            return $locked;
        });
    }

    public function releaseHold(CreatorEarningAccount $account, User $actor, string $note): CreatorEarningAccount
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-holds');
        $this->assertNotOwnAccount($actor, $account);
        $note = $this->requireNote($note, (int) config('creator_monetization.holds.note_min'), (int) config('creator_monetization.holds.note_max'));

        return DB::transaction(function () use ($account, $actor, $note) {
            $locked = CreatorEarningAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOnHold()) {
                return $locked; // idempotent
            }

            $previous = ['reason' => $locked->hold_reason_code, 'case_id' => $locked->hold_case_id];
            $locked->forceFill([
                'hold_reason_code' => null,
                'hold_note' => null,
                'hold_placed_at' => null,
                'hold_placed_by' => null,
                'hold_case_id' => null,
            ])->save();
            $this->audit->log('creator_payout_hold_released', $locked, ['previous' => $previous, 'note' => $note], $actor);

            return $locked;
        });
    }

    protected function cleanNote(?string $note): ?string
    {
        $note = $note !== null ? trim($note) : null;

        return $note === '' ? null : ($note !== null ? mb_substr($note, 0, 500) : null);
    }

    /** @throws CreatorFinanceException */
    protected function requireNote(string $note, int $min, int $max = 500): string
    {
        $note = trim($note);

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorFinanceException("الملاحظة مطلوبة ({$min}–{$max} حرفًا).");
        }

        return $note;
    }
}
