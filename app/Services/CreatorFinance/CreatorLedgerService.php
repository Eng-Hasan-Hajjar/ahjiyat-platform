<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorEarningEntry;
use App\Models\User;
use App\Services\CreatorFinance\Concerns\AuthorizesCreatorFinance;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E39 - نقطة الكتابة الوحيدة إلى دفتر الأرباح ولقطة الحساب. القواعد:
 *  - لا تُستدعى دوال الكتابة خارج معاملة تحمل قفل صف الحساب (lockAccount)؛ تُرمى استثناء إن غابت المعاملة.
 *  - القيد إلحاقي، و source_key فريد: التكرار يُرجِع القيد الأول ولا يدرج ثانيًا (ولا يغيّر اللقطة). مفتاح يُعاد لقيد مختلف يُرفض.
 *  - اللقطة تتغير بنفس الحركات المسجَّلة تمامًا وبلا سالب: الرصيد غير الكافي يُفشل العملية كلها.
 *  - التعديل الإداري (adjust) إضافة/خصم من «المتاح» فقط بسبب منظَّم وملاحظة؛ لا يمس المحجوز ولا المدفوع ولا حسابه الشخصي.
 */
class CreatorLedgerService
{
    use AuthorizesCreatorFinance;

    public function __construct(protected OperationalAuditService $audit) {}

    /** يقفل صف الحساب ويعيد نسخة طازجة. يجب استدعاؤها داخل معاملة. */
    public function lockAccount(int $accountId): CreatorEarningAccount
    {
        $this->assertInTransaction();

        $account = CreatorEarningAccount::query()->whereKey($accountId)->lockForUpdate()->first();

        if ($account === null) {
            throw new CreatorFinanceException('حساب الأرباح غير موجود.');
        }

        return $account;
    }

    /**
     * @param  array{period_id?:int|null, allocation_id?:int|null, payout_id?:int|null, reason_code?:string|null, note?:string|null, actor_id?:int|null}  $meta
     *                                                                                                                                                  القيد الجديد أو الموجود سابقًا بالمفتاح نفسه (wasRecentlyCreated يميّز).
     */
    public function post(CreatorEarningAccount $lockedAccount, string $type, int $available, int $reserved, int $paid, string $sourceKey, array $meta = []): CreatorEarningEntry
    {
        $this->assertInTransaction();

        $existing = CreatorEarningEntry::query()->where('source_key', $sourceKey)->first();

        if ($existing !== null) {
            if ((int) $existing->creator_earning_account_id !== (int) $lockedAccount->id || $existing->type !== $type
                || (int) $existing->available_delta_minor !== $available || (int) $existing->reserved_delta_minor !== $reserved || (int) $existing->paid_delta_minor !== $paid) {
                throw new CreatorFinanceException('مفتاح القيد مستخدم لقيد مختلف.');
            }

            return $existing;
        }

        $entry = new CreatorEarningEntry;
        $entry->forceFill([
            'creator_earning_account_id' => $lockedAccount->id,
            'creator_profile_id' => $lockedAccount->creator_profile_id,
            'user_id' => $lockedAccount->user_id,
            'type' => $type,
            'available_delta_minor' => $available,
            'reserved_delta_minor' => $reserved,
            'paid_delta_minor' => $paid,
            'currency' => $lockedAccount->currency,
            'source_key' => $sourceKey,
            'creator_revenue_period_id' => $meta['period_id'] ?? null,
            'creator_revenue_allocation_id' => $meta['allocation_id'] ?? null,
            'creator_payout_id' => $meta['payout_id'] ?? null,
            'reason_code' => $meta['reason_code'] ?? null,
            'note' => $meta['note'] ?? null,
            'actor_id' => $meta['actor_id'] ?? null,
        ]);

        // شكل الحركة يُفحص أولًا (LogicException = خطأ برمجي)، ثم كفاية الأرصدة (CreatorFinanceException = رفض عمل).
        $entry->assertShape();

        $newAvailable = (int) $lockedAccount->available_minor + $available;
        $newReserved = (int) $lockedAccount->reserved_minor + $reserved;
        $newPaid = (int) $lockedAccount->paid_lifetime_minor + $paid;

        if ($newAvailable < 0) {
            throw new CreatorFinanceException('الرصيد المتاح غير كافٍ.');
        }

        if ($newReserved < 0 || $newPaid < 0) {
            throw new CreatorFinanceException('الحركة تجعل الرصيد المحجوز أو المدفوع سالبًا.');
        }

        $entry->save();

        $lockedAccount->forceFill([
            'available_minor' => $newAvailable,
            'reserved_minor' => $newReserved,
            'paid_lifetime_minor' => $newPaid,
        ])->save();

        return $entry;
    }

    /** @return array{available:int, reserved:int, paid:int} ما يقوله الدفتر فعلًا (جمع قاعدة البيانات). */
    public function ledgerBalances(int $accountId): array
    {
        $row = DB::table('creator_earning_entries')->where('creator_earning_account_id', $accountId)
            ->selectRaw('COALESCE(SUM(available_delta_minor),0) as a, COALESCE(SUM(reserved_delta_minor),0) as r, COALESCE(SUM(paid_delta_minor),0) as p')->first();

        return ['available' => (int) $row->a, 'reserved' => (int) $row->r, 'paid' => (int) $row->p];
    }

    /**
     * تعديل إداري موثَّق. direction: credit|debit. المبلغ نص بالوحدة الرئيسية يحلله CreatorMoney (مثل "12.50").
     * requestKey اختياري (8–64 حرفًا) يجعل الإرسال المكرر بلا أثر ثانٍ.
     */
    public function adjust(CreatorEarningAccount $account, User $actor, string $direction, string $amount, string $reasonCode, string $note, ?string $requestKey = null): CreatorEarningEntry
    {
        $this->authorizeFinance($actor, 'creator-finance.adjust');
        $this->assertNotOwnAccount($actor, $account);

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new CreatorFinanceException('اتجاه التعديل غير صالح.');
        }

        if (! array_key_exists($reasonCode, (array) config('creator_monetization.adjustments.reasons'))) {
            throw new CreatorFinanceException('سبب التعديل غير معتمد.');
        }

        $note = trim($note);
        $min = (int) config('creator_monetization.adjustments.note_min');
        $max = (int) config('creator_monetization.adjustments.note_max');

        if (mb_strlen($note) < $min || mb_strlen($note) > $max) {
            throw new CreatorFinanceException("ملاحظة التعديل مطلوبة ({$min}–{$max} حرفًا).");
        }

        $minor = CreatorMoney::parse($amount, (string) $account->currency);

        if ($minor > (int) config('creator_monetization.adjustments.max_minor')) {
            throw new CreatorFinanceException('مبلغ التعديل يتجاوز السقف المسموح.');
        }

        if ($requestKey !== null && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestKey) !== 1) {
            throw new CreatorFinanceException('مفتاح الطلب غير صالح.');
        }

        $key = 'adjustment:'.($requestKey ?? Str::lower((string) Str::ulid()));
        $signed = $direction === 'credit' ? $minor : -$minor;
        $type = $direction === 'credit' ? CreatorEarningEntry::TYPE_ADJUSTMENT_CREDIT : CreatorEarningEntry::TYPE_ADJUSTMENT_DEBIT;

        $entry = DB::transaction(function () use ($account, $actor, $type, $signed, $key, $reasonCode, $note, $minor, $direction) {
            $locked = $this->lockAccount((int) $account->id);
            $entry = $this->post($locked, $type, $signed, 0, 0, $key, ['reason_code' => $reasonCode, 'note' => $note, 'actor_id' => $actor->id]);

            if ($entry->wasRecentlyCreated) {
                $this->audit->log('creator_earnings_adjusted', $locked, ['direction' => $direction, 'amount_minor' => $minor, 'reason' => $reasonCode, 'entry' => $entry->public_id], $actor);
            }

            return $entry;
        });

        $account->refresh();

        return $entry;
    }

    /** يعيد حساب لقطة حساب واحد من الدفتر (مشتقة فقط). يرجع true إن تغيّرت. */
    public function rebuildSnapshot(CreatorEarningAccount $account, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($account, $actor) {
            $locked = $this->lockAccount((int) $account->id);
            $b = $this->ledgerBalances((int) $locked->id);

            if ((int) $locked->available_minor === $b['available'] && (int) $locked->reserved_minor === $b['reserved'] && (int) $locked->paid_lifetime_minor === $b['paid']) {
                return false;
            }

            $before = ['available' => (int) $locked->available_minor, 'reserved' => (int) $locked->reserved_minor, 'paid' => (int) $locked->paid_lifetime_minor];
            $locked->forceFill(['available_minor' => $b['available'], 'reserved_minor' => $b['reserved'], 'paid_lifetime_minor' => $b['paid']])->save();
            $this->audit->log('creator_finance_balances_fixed', $locked, ['before' => $before, 'after' => $b], $actor);

            return true;
        });
    }

    protected function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new CreatorFinanceException('عملية الدفتر تتطلب معاملة قاعدة بيانات.');
        }
    }
}
