<?php

namespace App\Services\CreatorFinance;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\CreatorRevenueAllocation;
use App\Models\CreatorRevenuePeriod;
use App\Models\User;
use App\Services\CreatorFinance\Concerns\AuthorizesCreatorFinance;
use App\Services\OperationalAuditService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;

/**
 * E39 - دورة حياة فترة الإيراد: إنشاء (draft) ← مراجعة (review) ← إقفال (finalized) أو إلغاء (cancelled).
 *
 *  - المجمّع صريح يحدده المدير (لا اشتقاق من أي إيراد). عملة واحدة (CreatorMoney::currency). لا تداخل بين الفترات غير الملغاة (قفل ثابت الترتيب على صفوف الفترات).
 *  - preview() قراءة فقط ولا تكتب شيئًا. الإقفال معاملة واحدة مقفلة: يحسب، يكتب التخصيصات وقيود الدفتر ولقطات الحسابات والفترة معًا أو لا شيء.
 *  - الإقفال idempotent: إعادة استدعاء فترة مُقفلة لا تنشئ شيئًا. الفترة المُقفلة لا تُعدَّل ولا تُلغى؛ التصحيح بالتعديل الإداري في الدفتر.
 *  - لا إقفال تلقائي بأي جدولة؛ القرار بشري صريح.
 */
class CreatorRevenuePeriodService
{
    use AuthorizesCreatorFinance;

    public function __construct(
        protected OperationalAuditService $audit,
        protected QualifiedUnitsQuery $units,
        protected CreatorLedgerService $ledger,
        protected CreatorFinanceNotifier $notifier,
    ) {}

    /** @param array{label?:string, starts_at?:mixed, ends_at?:mixed, pool?:string, note?:string|null} $input */
    public function create(User $actor, array $input): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-periods');
        $data = $this->normalize($input);

        return DB::transaction(function () use ($actor, $data) {
            $this->lockLivePeriods();
            $this->assertNoOverlap($data['starts_at'], $data['ends_at'], null);

            $period = new CreatorRevenuePeriod;
            $period->forceFill($data + ['status' => CreatorRevenuePeriod::STATUS_DRAFT, 'created_by' => $actor->id])->save();
            $this->audit->log('creator_revenue_period_created', $period, ['pool_minor' => $data['pool_minor'], 'starts_at' => (string) $data['starts_at'], 'ends_at' => (string) $data['ends_at']], $actor);

            return $period;
        });
    }

    /** @param array{label?:string, starts_at?:mixed, ends_at?:mixed, pool?:string, note?:string|null} $input */
    public function update(CreatorRevenuePeriod $period, User $actor, array $input): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-periods');
        $data = $this->normalize($input);

        return DB::transaction(function () use ($period, $actor, $data) {
            $this->lockLivePeriods();
            $locked = $this->fresh($period);

            if (! $locked->isDraft()) {
                throw new CreatorFinanceException('لا تُعدَّل الفترة إلا وهي مسودة.');
            }

            $this->assertNoOverlap($data['starts_at'], $data['ends_at'], (int) $locked->id);
            $locked->forceFill($data)->save();
            $this->audit->log('creator_revenue_period_updated', $locked, ['pool_minor' => $data['pool_minor']], $actor);

            return $locked;
        });
    }

    /**
     * حساب كامل بلا أي كتابة. للفترة المُقفلة يعيد الأرقام المخزَّنة (finalized=true).
     *
     * @return array{finalized:bool, rows:list<array<string,mixed>>, summary:array<string,mixed>}
     */
    public function preview(CreatorRevenuePeriod $period): array
    {
        if ($period->status === CreatorRevenuePeriod::STATUS_FINALIZED) {
            $allocations = $period->allocations()->orderBy('creator_profile_id')->get();

            return [
                'finalized' => true,
                'rows' => $allocations->map(fn (CreatorRevenueAllocation $a) => [
                    'creator_profile_id' => (int) $a->creator_profile_id,
                    'user_id' => (int) $a->user_id,
                    'raw_units' => (int) $a->raw_units,
                    'qualified_units' => (int) $a->qualified_units,
                    'unique_players' => (int) $a->unique_players,
                    'eligible' => true,
                    'reason' => null,
                    'allocated_minor' => (int) $a->allocated_minor,
                    'bonus' => (int) $a->remainder_bonus_minor,
                ])->all(),
                'summary' => [
                    'pool_minor' => (int) $period->pool_minor,
                    'total_units' => (int) $period->total_units,
                    'eligible_creators' => (int) $period->eligible_creators,
                    'allocated_minor' => (int) $period->allocated_minor,
                    'unallocated_minor' => (int) $period->unallocated_minor,
                    'ineligible' => [],
                ],
            ];
        }

        return $this->compute($period) + ['finalized' => false];
    }

    public function submitForReview(CreatorRevenuePeriod $period, User $actor): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-periods');

        return DB::transaction(function () use ($period, $actor) {
            $this->lockLivePeriods();
            $locked = $this->fresh($period);

            if ($locked->status === CreatorRevenuePeriod::STATUS_REVIEW) {
                return $locked; // idempotent
            }

            if (! $locked->isDraft()) {
                throw new CreatorFinanceException('الفترة ليست مسودة.');
            }

            $this->assertNoOverlap($locked->starts_at, $locked->ends_at, (int) $locked->id);
            $summary = $this->compute($locked)['summary'];
            $locked->forceFill([
                'status' => CreatorRevenuePeriod::STATUS_REVIEW,
                'review_summary' => $summary,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();
            $this->audit->log('creator_revenue_period_review', $locked, $summary, $actor);

            return $locked;
        });
    }

    public function sendBack(CreatorRevenuePeriod $period, User $actor): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-periods');

        return DB::transaction(function () use ($period, $actor) {
            $this->lockLivePeriods();
            $locked = $this->fresh($period);

            if ($locked->isDraft()) {
                return $locked; // idempotent
            }

            if ($locked->status !== CreatorRevenuePeriod::STATUS_REVIEW) {
                throw new CreatorFinanceException('لا تُعاد إلى المسودة إلا فترة قيد المراجعة.');
            }

            $locked->forceFill(['status' => CreatorRevenuePeriod::STATUS_DRAFT, 'review_summary' => null, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            $this->audit->log('creator_revenue_period_sent_back', $locked, [], $actor);

            return $locked;
        });
    }

    public function cancel(CreatorRevenuePeriod $period, User $actor, string $reason): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.manage-periods');
        $reason = trim($reason);

        if (mb_strlen($reason) < 8 || mb_strlen($reason) > (int) config('creator_monetization.periods.note_max')) {
            throw new CreatorFinanceException('سبب الإلغاء مطلوب (8 أحرف فأكثر).');
        }

        return DB::transaction(function () use ($period, $actor, $reason) {
            $this->lockLivePeriods();
            $locked = $this->fresh($period);

            if ($locked->status === CreatorRevenuePeriod::STATUS_CANCELLED) {
                return $locked; // idempotent
            }

            if ($locked->isFinalized()) {
                throw new CreatorFinanceException('الفترة المُقفلة لا تُلغى؛ التصحيح بتعديل إداري موثَّق في الدفتر.');
            }

            $locked->forceFill(['status' => CreatorRevenuePeriod::STATUS_CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->log('creator_revenue_period_cancelled', $locked, ['reason' => $reason], $actor);

            return $locked;
        });
    }

    public function finalize(CreatorRevenuePeriod $period, User $actor): CreatorRevenuePeriod
    {
        $this->authorizeFinance($actor, 'creator-finance.finalize');

        $created = [];

        $result = DB::transaction(function () use ($period, $actor, &$created) {
            $this->lockLivePeriods();
            $locked = $this->fresh($period);

            if ($locked->isFinalized()) {
                return $locked; // idempotent: لا شيء يُكتب ثانيةً
            }

            if ($locked->status !== CreatorRevenuePeriod::STATUS_REVIEW) {
                throw new CreatorFinanceException('لا تُقفل إلا فترة قيد المراجعة.');
            }

            $delay = (int) config('creator_monetization.periods.finalize_delay_hours', 0);

            if (now()->lt($locked->ends_at->copy()->addHours($delay))) {
                throw new CreatorFinanceException('لم تنتهِ الفترة بعد (أو لم تمرّ فترة الاستقرار قبل الإقفال).');
            }

            $this->assertNoOverlap($locked->starts_at, $locked->ends_at, (int) $locked->id);

            $computed = $this->compute($locked);
            $eligible = array_values(array_filter($computed['rows'], fn (array $r) => $r['eligible']));
            $now = now();

            foreach ($eligible as $row) {
                $allocation = new CreatorRevenueAllocation;
                $allocation->forceFill([
                    'creator_revenue_period_id' => $locked->id,
                    'creator_profile_id' => $row['creator_profile_id'],
                    'user_id' => $row['user_id'],
                    'raw_units' => $row['raw_units'],
                    'qualified_units' => $row['qualified_units'],
                    'unique_players' => $row['unique_players'],
                    'period_total_units' => $computed['summary']['total_units'],
                    'allocated_minor' => $row['allocated_minor'],
                    'remainder_bonus_minor' => $row['bonus'],
                    'currency' => $locked->currency,
                    'created_at' => $now,
                ])->save();

                if ($row['allocated_minor'] > 0) {
                    $account = $this->ledger->lockAccount((int) $row['account_id']);
                    $this->ledger->post($account, 'revenue_share', $row['allocated_minor'], 0, 0, 'revenue:'.$locked->public_id.':'.$row['creator_profile_id'], [
                        'period_id' => $locked->id,
                        'allocation_id' => $allocation->id,
                        'actor_id' => $actor->id,
                    ]);
                    $created[] = $allocation;
                }
            }

            $locked->forceFill([
                'status' => CreatorRevenuePeriod::STATUS_FINALIZED,
                'total_units' => $computed['summary']['total_units'],
                'eligible_creators' => count($eligible),
                'allocated_minor' => $computed['summary']['allocated_minor'],
                'unallocated_minor' => $computed['summary']['unallocated_minor'],
                'policy_snapshot' => $this->policySnapshot($locked),
                'finalized_by' => $actor->id,
                'finalized_at' => $now,
            ])->save();

            $this->audit->log('creator_revenue_period_finalized', $locked, [
                'pool_minor' => (int) $locked->pool_minor,
                'allocated_minor' => (int) $locked->allocated_minor,
                'unallocated_minor' => (int) $locked->unallocated_minor,
                'eligible_creators' => count($eligible),
                'total_units' => (int) $locked->total_units,
            ], $actor);

            return $locked;
        });

        foreach ($created as $allocation) {
            $this->notifier->statementReady($allocation);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function policySnapshot(CreatorRevenuePeriod $period): array
    {
        $a = (array) config('creator_monetization.allocation');

        return [
            'formula_version' => (int) ($a['formula_version'] ?? 1),
            'method' => 'largest_remainder',
            'tie_break' => 'creator_profile_id_asc',
            'unit' => 'distinct_user_and_work_correct_answer',
            'per_user_per_creator_cap' => (int) $a['per_user_per_creator_cap'],
            'min_units_per_creator' => (int) $a['min_units_per_creator'],
            'min_unique_players_per_creator' => (int) $a['min_unique_players_per_creator'],
            'currency' => (string) $period->currency,
            'counts_archived_works' => true,
            'excludes' => ['wrong_answers', 'outside_period', 'self_play', 'frozen_players', 'moderation_or_suspension_hidden_works', 'unpublished_works'],
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>, summary:array<string,mixed>}
     */
    protected function compute(CreatorRevenuePeriod $period): array
    {
        $policy = $this->policySnapshot($period);
        $rows = $this->units->perCreator($period->starts_at, $period->ends_at, $policy['per_user_per_creator_cap']);
        $ids = array_map(fn (array $r) => $r['creator_profile_id'], $rows);
        $chunk = max(50, (int) config('creator_monetization.allocation.chunk', 500));
        $profiles = collect();
        $accounts = collect();

        foreach (array_chunk($ids, $chunk) as $part) {
            $profiles = $profiles->concat(CreatorProfile::query()->with('user:id,is_frozen')->whereIn('id', $part)->get());
            $accounts = $accounts->concat(CreatorEarningAccount::query()->whereIn('creator_profile_id', $part)->get());
        }

        $profiles = $profiles->keyBy('id');
        $accounts = $accounts->keyBy('creator_profile_id');

        $detailed = [];
        $ineligible = [];
        $eligibleInput = [];

        foreach ($rows as $row) {
            $profile = $profiles->get($row['creator_profile_id']);
            $account = $accounts->get($row['creator_profile_id']);

            $reason = match (true) {
                $profile === null => 'profile_missing',
                $account === null => 'not_enrolled',
                ! $account->isEnabled() => 'paused',
                (string) $account->currency !== (string) $period->currency => 'currency_mismatch',
                ! $profile->isApproved() => 'profile_not_approved',
                (bool) $profile->user?->is_frozen => 'creator_frozen',
                $row['qualified_units'] < $policy['min_units_per_creator'] => 'below_min_units',
                $row['unique_players'] < $policy['min_unique_players_per_creator'] => 'below_min_players',
                default => null,
            };

            $detailed[$row['creator_profile_id']] = $row + [
                'user_id' => (int) ($profile?->user_id ?? 0),
                'account_id' => (int) ($account?->id ?? 0),
                'eligible' => $reason === null,
                'reason' => $reason,
                'allocated_minor' => 0,
                'bonus' => 0,
            ];

            if ($reason === null) {
                $eligibleInput[] = ['creator_profile_id' => $row['creator_profile_id'], 'qualified_units' => $row['qualified_units']];
            } else {
                $ineligible[$reason] = ($ineligible[$reason] ?? 0) + 1;
            }
        }

        $allocation = RevenueAllocator::allocate((int) $period->pool_minor, $eligibleInput, (int) config('creator_monetization.allocation.max_total_units'));

        foreach ($allocation['allocations'] as $creatorId => $alloc) {
            $detailed[$creatorId]['allocated_minor'] = $alloc['allocated_minor'];
            $detailed[$creatorId]['bonus'] = $alloc['bonus'];
        }

        ksort($detailed);

        return [
            'rows' => array_values($detailed),
            'summary' => [
                'pool_minor' => (int) $period->pool_minor,
                'creators_with_units' => count($rows),
                'raw_units_total' => array_sum(array_column($rows, 'raw_units')),
                'qualified_units_total' => array_sum(array_column($rows, 'qualified_units')),
                'eligible_creators' => count($eligibleInput),
                'total_units' => $allocation['total_units'],
                'allocated_minor' => $allocation['allocated_minor'],
                'unallocated_minor' => $allocation['unallocated_minor'],
                'ineligible' => $ineligible,
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    protected function normalize(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        $max = (int) config('creator_monetization.periods.label_max');

        if ($label === '' || mb_strlen($label) > $max || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            throw new CreatorFinanceException("عنوان الفترة مطلوب (حتى {$max} حرفًا) بلا محارف تحكم.");
        }

        $start = $this->date($input['starts_at'] ?? null);
        $end = $this->date($input['ends_at'] ?? null);

        if ($end->lte($start)) {
            throw new CreatorFinanceException('نهاية الفترة يجب أن تكون بعد بدايتها.');
        }

        if ($start->diffInHours($end) < (int) config('creator_monetization.periods.min_hours')) {
            throw new CreatorFinanceException('الفترة أقصر من الحدّ الأدنى المسموح.');
        }

        if ($start->diffInDays($end) > (int) config('creator_monetization.periods.max_days')) {
            throw new CreatorFinanceException('الفترة أطول من الحدّ الأقصى المسموح.');
        }

        $currency = CreatorMoney::currency();
        $pool = CreatorMoney::parse((string) ($input['pool'] ?? ''), $currency, (int) config('creator_monetization.periods.max_pool_minor'));
        $note = isset($input['note']) ? trim((string) $input['note']) : null;

        if ($note !== null && mb_strlen($note) > (int) config('creator_monetization.periods.note_max')) {
            throw new CreatorFinanceException('الملاحظة أطول من المسموح.');
        }

        return ['label' => $label, 'starts_at' => $start, 'ends_at' => $end, 'currency' => $currency, 'pool_minor' => $pool, 'note' => $note === '' ? null : $note];
    }

    protected function date(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->startOfSecond();
        }

        if (! is_string($value) || trim($value) === '') {
            throw new CreatorFinanceException('تاريخ الفترة مطلوب.');
        }

        try {
            return Carbon::parse(trim($value))->startOfSecond();
        } catch (InvalidFormatException) {
            throw new CreatorFinanceException('تاريخ غير صالح.');
        }
    }

    protected function assertNoOverlap(CarbonInterface $start, CarbonInterface $end, ?int $ignoreId): void
    {
        $query = CreatorRevenuePeriod::query()->whereIn('status', CreatorRevenuePeriod::LIVE_STATUSES)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw new CreatorFinanceException('الفترة تتداخل مع فترة إيراد أخرى غير ملغاة.');
        }
    }

    /** يقفل كل فترات الحالات الحيّة بترتيب المعرّف الثابت (تسلسل الإنشاء/الإقفال وتفادي الجمود). */
    protected function lockLivePeriods(): void
    {
        CreatorRevenuePeriod::query()->whereIn('status', CreatorRevenuePeriod::LIVE_STATUSES)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    protected function fresh(CreatorRevenuePeriod $period): CreatorRevenuePeriod
    {
        return CreatorRevenuePeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
    }
}
