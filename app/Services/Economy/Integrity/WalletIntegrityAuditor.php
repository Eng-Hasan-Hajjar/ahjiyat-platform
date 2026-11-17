<?php

namespace App\Services\Economy\Integrity;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E46: تدقيق «سلامة الجواهر» - **قراءة فقط حصرًا**: لا INSERT ولا UPDATE ولا DELETE ولا قفل صفوف، ولا يمرّ عبر CurrencyWalletService.
 * يقارن رصيدَي كل محفظة (المتاح والمعلَّق، كلًا على حدة) بما يشتقّه من دفتر `currency_transactions` وفق LedgerEffects.
 * (لا يكفي SUM(amount) وحده: earn_pending لا يمسّ المتاح، و release_available صفّ موجب واحد ينقل من المعلَّق إلى المتاح.)
 *
 * ضمانات التصميم:
 *  - النطاق: مستخدم و/أو عملات (WalletAuditScope). كل (مستخدم، عملة) معزول؛ رصيد عملة لا يدخل حساب عملة أخرى أبدًا.
 *  - دفعات حتمية: المحافظ بترتيب المعرّف تصاعديًا بمؤشر `id > آخر معرّف` (لا OFFSET)، وبحجم `chunk` فلا تُحمَّل كل المحافظ في الذاكرة.
 *  - لا إنذار كاذب بسبب حركة جارية: (1) قراءة المحافظ والتجميعات للدفعة داخل معاملة قراءة واحدة (لقطة متسقة: الخدمة تكتب المحفظة والدفتر في معاملة واحدة)،
 *    (2) فرق يظهر على محفظة تحرّكت خلال نافذة السماح يُصنَّف «لا يمكن الجزم»، (3) كل حالة غير مطابقة تُعاد قراءتها مرة ثانية: إن تغيّرت أرقامها بين المرورين
 *    لا تُحسم (أو تُعتبر مطابقة إن اختفى الفرق).
 *  - الأرصدة الافتتاحية/المرحَّلة: لا نوع «رصيد افتتاحي» ولا جدول له في النظام؛ لذلك لا يُسمّى أي فرق «مثبتًا» إلا إذا انتفت قرائن وجود جواهر خارج الدفتر
 *    (دفتر غير فارغ + lifetime_earned ضمن المدى الذي يفسّره الدفتر). راجع WalletAuditCase.
 */
class WalletIntegrityAuditor
{
    /**
     * يدقّق النطاق كله على دفعات. $onCase يُستدعى لكل حالة (مطابقة وغيرها) بالترتيب الحتمي؛ الملخّص يحمل العدّادات فقط.
     *
     * @param  (callable(WalletAuditCase): void)|null  $onCase
     */
    public function audit(WalletAuditScope $scope, ?callable $onCase = null): WalletAuditSummary
    {
        $startedAt = Carbon::now();
        $summary = new WalletAuditSummary($scope, $startedAt);

        $emit = function (WalletAuditCase $case) use ($summary, $onCase): void {
            $summary->record($case);

            if ($onCase !== null) {
                $onCase($case);
            }
        };

        $afterId = 0;

        while (true) {
            [$cases, $lastId, $size] = $this->walletBatch($scope, $afterId, $startedAt);

            foreach ($cases as $case) {
                $emit($this->recheck($case, $scope, $startedAt));
            }

            if ($size < $scope->chunk) {
                break;
            }

            $afterId = $lastId;
        }

        if ($scope->includeOrphans) {
            $this->auditOrphans($scope, $startedAt, $emit);
        }

        $summary->finishedAt = Carbon::now();

        return $summary;
    }

    /**
     * حالة واحدة (مستخدم + عملة) بقراءة متسقة. null إن لم توجد محفظة ولا دفتر لهذا الزوج.
     */
    public function auditPair(int $userId, int $currencyId, ?WalletAuditScope $scope = null, ?CarbonInterface $startedAt = null): ?WalletAuditCase
    {
        $scope ??= new WalletAuditScope(userId: $userId, currencyIds: [$currencyId]);
        $startedAt ??= Carbon::now();

        return $this->snapshot(function () use ($userId, $currencyId, $scope, $startedAt) {
            $wallet = DB::table('wallets')
                ->where('user_id', $userId)->where('currency_id', $currencyId)
                ->first(['id', 'user_id', 'currency_id', 'pending_balance', 'available_balance', 'lifetime_earned', 'updated_at']);

            $agg = $this->aggregates([$userId], [$currencyId])[$this->key($userId, $currencyId)] ?? null;

            if ($wallet === null && $agg === null) {
                return null;
            }

            if ($wallet === null) {
                return $this->orphanCase($userId, $currencyId, $agg, $scope, $startedAt);
            }

            return $this->evaluate($wallet, $agg, $scope, $startedAt);
        });
    }

    /**
     * معاملة قراءة بلقطة متسقة. على MySQL/MariaDB نطلب REPEATABLE READ صراحةً لهذه المعاملة وحدها كي لا يعتمد الضمان على الإعداد الافتراضي للخادم
     * (READ COMMITTED مثلًا يجعل كل استعلام يرى لقطة مختلفة). لا يُنفَّذ الأمر داخل معاملة خارجية قائمة (لا يجوز تغيير الخصائص أثناءها)،
     * وهناك تبقى لقطة المعاملة الخارجية. أمر قراءة/إعداد جلسة فقط: لا كتابة ولا قفل.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function snapshot(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction($callback);
    }

    /**
     * @return array{0: list<WalletAuditCase>, 1: int, 2: int} [الحالات، آخر معرّف محفظة، حجم الدفعة]
     */
    protected function walletBatch(WalletAuditScope $scope, int $afterId, CarbonInterface $startedAt): array
    {
        return $this->snapshot(function () use ($scope, $afterId, $startedAt) {
            $wallets = DB::table('wallets')
                ->when($scope->userId !== null, fn ($q) => $q->where('user_id', $scope->userId))
                ->when($scope->currencyIds !== null, fn ($q) => $q->whereIn('currency_id', $scope->currencyIds))
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($scope->chunk)
                ->get(['id', 'user_id', 'currency_id', 'pending_balance', 'available_balance', 'lifetime_earned', 'updated_at']);

            if ($wallets->isEmpty()) {
                return [[], $afterId, 0];
            }

            $aggregates = $this->aggregates(
                $wallets->pluck('user_id')->unique()->values()->all(),
                $wallets->pluck('currency_id')->unique()->values()->all(),
            );

            $cases = [];

            foreach ($wallets as $wallet) {
                $cases[] = $this->evaluate($wallet, $aggregates[$this->key($wallet->user_id, $wallet->currency_id)] ?? null, $scope, $startedAt);
            }

            return [$cases, (int) $wallets->last()->id, $wallets->count()];
        });
    }

    /**
     * @param  callable(WalletAuditCase): void  $emit
     */
    protected function auditOrphans(WalletAuditScope $scope, CarbonInterface $startedAt, callable $emit): void
    {
        $afterUser = 0;
        $afterCurrency = 0;

        while (true) {
            $rows = $this->snapshot(function () use ($scope, $afterUser, $afterCurrency) {
                return DB::table('currency_transactions as t')
                    ->leftJoin('wallets as w', fn ($join) => $join->on('w.user_id', '=', 't.user_id')->on('w.currency_id', '=', 't.currency_id'))
                    ->whereNull('w.id')
                    ->whereNotNull('t.currency_id')
                    ->when($scope->userId !== null, fn ($q) => $q->where('t.user_id', $scope->userId))
                    ->when($scope->currencyIds !== null, fn ($q) => $q->whereIn('t.currency_id', $scope->currencyIds))
                    ->where(fn ($q) => $q->where('t.user_id', '>', $afterUser)
                        ->orWhere(fn ($q) => $q->where('t.user_id', $afterUser)->where('t.currency_id', '>', $afterCurrency)))
                    ->groupBy('t.user_id', 't.currency_id')
                    ->orderBy('t.user_id')->orderBy('t.currency_id')
                    ->limit($scope->chunk)
                    ->selectRaw(...$this->aggregateSelect('t.'))
                    ->get();
            });

            foreach ($rows as $row) {
                $case = $this->orphanCase((int) $row->user_id, (int) $row->currency_id, $row, $scope, $startedAt);

                if ($case !== null) {
                    $emit($this->recheck($case, $scope, $startedAt));
                }
            }

            if ($rows->count() < $scope->chunk) {
                break;
            }

            $afterUser = (int) $rows->last()->user_id;
            $afterCurrency = (int) $rows->last()->currency_id;
        }
    }

    /**
     * مرور ثانٍ لكل حالة غير مطابقة: قراءة جديدة للزوج نفسه. إن تغيّرت الأرقام بين المرورين فهذه حركة جارية.
     */
    protected function recheck(WalletAuditCase $first, WalletAuditScope $scope, CarbonInterface $startedAt): WalletAuditCase
    {
        if ($first->isMatched()) {
            return $first;
        }

        $second = $this->auditPair($first->userId, $first->currencyId, $scope, $startedAt);

        if ($second === null) {
            // اختفى الزوج بين المرورين (حذف): لا حكم.
            return $this->withReason($first, WalletAuditCase::REASON_IN_FLIGHT);
        }

        if ($this->signature($first) === $this->signature($second)) {
            return $second;
        }

        if ($second->isMatched()) {
            return new WalletAuditCase(
                $second->walletId, $second->userId, $second->currencyId, WalletAuditCase::VERDICT_MATCHED,
                $second->storedAvailable, $second->derivedAvailable, $second->storedPending, $second->derivedPending,
                $second->ledgerRows, [], array_values(array_unique([...$second->notes, WalletAuditCase::NOTE_SETTLED_ON_RECHECK])),
            );
        }

        return $this->withReason($second, WalletAuditCase::REASON_IN_FLIGHT);
    }

    /** @return array<int, int> */
    protected function signature(WalletAuditCase $case): array
    {
        return [$case->storedAvailable, $case->derivedAvailable, $case->storedPending, $case->derivedPending, $case->ledgerRows];
    }

    protected function withReason(WalletAuditCase $case, string $reason): WalletAuditCase
    {
        return new WalletAuditCase(
            $case->walletId, $case->userId, $case->currencyId, WalletAuditCase::VERDICT_UNDETERMINED,
            $case->storedAvailable, $case->derivedAvailable, $case->storedPending, $case->derivedPending,
            $case->ledgerRows, array_values(array_unique([...$case->reasons, $reason])), $case->notes,
        );
    }

    /**
     * الحكم لمحفظة موجودة. المتاح والمعلَّق يُقارَنان كلٌّ على حدة؛ الحكم النهائي يجمعهما.
     */
    protected function evaluate(object $wallet, ?object $agg, WalletAuditScope $scope, CarbonInterface $startedAt): WalletAuditCase
    {
        [$derivedAvailable, $derivedPending, $rows] = $this->derive($agg);

        $storedAvailable = (int) $wallet->available_balance;
        $storedPending = (int) $wallet->pending_balance;
        $anomalies = $this->anomalyCount($agg);

        if ($storedAvailable === $derivedAvailable && $storedPending === $derivedPending) {
            return new WalletAuditCase(
                (int) $wallet->id, (int) $wallet->user_id, (int) $wallet->currency_id, WalletAuditCase::VERDICT_MATCHED,
                $storedAvailable, $derivedAvailable, $storedPending, $derivedPending, $rows,
                [], $anomalies > 0 ? [WalletAuditCase::NOTE_LEDGER_ANOMALY] : [],
            );
        }

        $reasons = [];

        if ($rows === 0) {
            $reasons[] = WalletAuditCase::REASON_NO_LEDGER;
        } else {
            $lifetime = (int) $wallet->lifetime_earned;
            $min = (int) $agg->earn_sum;
            // creditAvailable يزيد lifetime_earned أيضًا ويُسجَّل بنوع غير earn_pending؛ لا يمكن تمييزه عن refund/adjust، فالمدى المسموح يشمل كل المبالغ الموجبة لغير earn/release.
            $max = $min + (int) $agg->other_positive_sum;

            if ($lifetime > $max) {
                $reasons[] = WalletAuditCase::REASON_CARRIED_EARNINGS;
            } elseif ($lifetime < $min) {
                $reasons[] = WalletAuditCase::REASON_LIFETIME_BELOW_LEDGER;
            }
        }

        if ($anomalies > 0) {
            $reasons[] = WalletAuditCase::REASON_LEDGER_ANOMALY;
        }

        if ($this->isRecent($wallet->updated_at ?? null, $agg->last_ledger_at ?? null, $scope, $startedAt)) {
            $reasons[] = WalletAuditCase::REASON_IN_FLIGHT;
        }

        return new WalletAuditCase(
            (int) $wallet->id, (int) $wallet->user_id, (int) $wallet->currency_id,
            $reasons === [] ? WalletAuditCase::VERDICT_PROVEN : WalletAuditCase::VERDICT_UNDETERMINED,
            $storedAvailable, $derivedAvailable, $storedPending, $derivedPending, $rows, $reasons,
        );
    }

    /** حالة صفوف دفتر بلا محفظة: null إن كان صافيها صفرًا في الرصيدين (لا شيء يستحق البلاغ)، وإلا «لا يمكن الجزم» دائمًا. */
    protected function orphanCase(int $userId, int $currencyId, ?object $agg, WalletAuditScope $scope, CarbonInterface $startedAt): ?WalletAuditCase
    {
        [$derivedAvailable, $derivedPending, $rows] = $this->derive($agg);

        if ($derivedAvailable === 0 && $derivedPending === 0) {
            return null;
        }

        $reasons = [WalletAuditCase::REASON_WALLET_MISSING];

        if ($this->anomalyCount($agg) > 0) {
            $reasons[] = WalletAuditCase::REASON_LEDGER_ANOMALY;
        }

        if ($this->isRecent(null, $agg->last_ledger_at ?? null, $scope, $startedAt)) {
            $reasons[] = WalletAuditCase::REASON_IN_FLIGHT;
        }

        return new WalletAuditCase(null, $userId, $currencyId, WalletAuditCase::VERDICT_UNDETERMINED, 0, $derivedAvailable, 0, $derivedPending, $rows, $reasons);
    }

    /** @return array{0: int, 1: int, 2: int} [المتاح المشتق، المعلَّق المشتق، عدد صفوف الدفتر] */
    protected function derive(?object $agg): array
    {
        if ($agg === null) {
            return [0, 0, 0];
        }

        $earn = (int) $agg->earn_sum;
        $release = (int) $agg->release_sum;
        $other = (int) $agg->other_sum;

        // المعلَّق = كل كسب − كل تحرير. المتاح = كل تحرير + بقية الأنواع بإشاراتها (انظر LedgerEffects).
        return [$release + $other, $earn - $release, (int) $agg->rows_count];
    }

    protected function anomalyCount(?object $agg): int
    {
        return $agg === null ? 0 : (int) $agg->unknown_rows + (int) $agg->sign_anomaly_rows;
    }

    protected function isRecent(mixed $walletUpdatedAt, mixed $lastLedgerAt, WalletAuditScope $scope, CarbonInterface $startedAt): bool
    {
        if ($scope->graceSeconds === 0) {
            return false;
        }

        $cutoff = $startedAt->copy()->subSeconds($scope->graceSeconds);

        foreach ([$walletUpdatedAt, $lastLedgerAt] as $moment) {
            if ($moment !== null && Carbon::parse($moment)->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * تجميع الدفتر لكل (مستخدم، عملة) بالدفعة: استعلام واحد. العملات تُفصل بالتجميع نفسه فلا تختلط.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $currencyIds
     * @return array<string, object>
     */
    protected function aggregates(array $userIds, array $currencyIds): array
    {
        $rows = DB::table('currency_transactions')
            ->whereIn('user_id', $userIds)
            ->whereIn('currency_id', $currencyIds)
            ->groupBy('user_id', 'currency_id')
            ->selectRaw(...$this->aggregateSelect(''))
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$this->key((int) $row->user_id, (int) $row->currency_id)] = $row;
        }

        return $keyed;
    }

    /**
     * عبارة التجميع المشتركة. الأنواع تأتي من ثوابت LedgerEffects عبر bindings (لا إدخال خارجي).
     *
     * @return array{0: string, 1: list<string>} [SQL, bindings] للتمرير إلى selectRaw(...)
     */
    protected function aggregateSelect(string $p): array
    {
        $earn = LedgerEffects::EARN;
        $release = LedgerEffects::RELEASE;
        $known = LedgerEffects::knownTypes();
        $positive = LedgerEffects::alwaysPositiveTypes();
        $negative = LedgerEffects::alwaysNegativeTypes();

        $marks = fn (array $list): string => implode(',', array_fill(0, count($list), '?'));

        $sql = "{$p}user_id AS user_id, {$p}currency_id AS currency_id, "
            .'COUNT(*) AS rows_count, '
            ."COALESCE(SUM(CASE WHEN {$p}type = ? THEN {$p}amount ELSE 0 END), 0) AS earn_sum, "
            ."COALESCE(SUM(CASE WHEN {$p}type = ? THEN {$p}amount ELSE 0 END), 0) AS release_sum, "
            ."COALESCE(SUM(CASE WHEN {$p}type NOT IN (?, ?) THEN {$p}amount ELSE 0 END), 0) AS other_sum, "
            ."COALESCE(SUM(CASE WHEN {$p}type NOT IN (?, ?) AND {$p}amount > 0 THEN {$p}amount ELSE 0 END), 0) AS other_positive_sum, "
            ."COALESCE(SUM(CASE WHEN {$p}type NOT IN ({$marks($known)}) THEN 1 ELSE 0 END), 0) AS unknown_rows, "
            ."COALESCE(SUM(CASE WHEN {$p}amount = 0 "
                ."OR ({$p}type IN ({$marks($positive)}) AND {$p}amount < 0) "
                ."OR ({$p}type IN ({$marks($negative)}) AND {$p}amount > 0) THEN 1 ELSE 0 END), 0) AS sign_anomaly_rows, "
            ."MAX({$p}created_at) AS last_ledger_at";

        $bindings = [$earn, $release, $earn, $release, $earn, $release, ...$known, ...$positive, ...$negative];

        return [$sql, $bindings];
    }

    protected function key(int|string $userId, int|string $currencyId): string
    {
        return (int) $userId.':'.(int) $currencyId;
    }
}
