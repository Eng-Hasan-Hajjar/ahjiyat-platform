<?php

namespace App\Services\Economy\Integrity;

use Carbon\CarbonInterface;

/**
 * E46: ملخّص فحص كامل (عدّادات فقط؛ لا يحتفظ بالحالات نفسها كي لا يتضخّم في الذاكرة).
 */
final class WalletAuditSummary
{
    public int $scanned = 0;

    public int $matched = 0;

    public int $provenDifferences = 0;

    public int $undetermined = 0;

    /** @var array<string, int> رمز السبب => عدد الحالات غير المحسومة التي ذكرته */
    public array $reasonCounts = [];

    /** @var array<int, array{matched: int, proven_difference: int, undetermined: int}> currency_id => أعداد */
    public array $byCurrency = [];

    public ?CarbonInterface $finishedAt = null;

    public function __construct(
        public readonly WalletAuditScope $scope,
        public readonly CarbonInterface $startedAt,
    ) {}

    public function record(WalletAuditCase $case): void
    {
        $this->scanned++;

        $this->byCurrency[$case->currencyId] ??= [
            WalletAuditCase::VERDICT_MATCHED => 0,
            WalletAuditCase::VERDICT_PROVEN => 0,
            WalletAuditCase::VERDICT_UNDETERMINED => 0,
        ];
        $this->byCurrency[$case->currencyId][$case->verdict]++;

        match ($case->verdict) {
            WalletAuditCase::VERDICT_MATCHED => $this->matched++,
            WalletAuditCase::VERDICT_PROVEN => $this->provenDifferences++,
            default => $this->undetermined++,
        };

        foreach ($case->reasons as $reason) {
            $this->reasonCounts[$reason] = ($this->reasonCounts[$reason] ?? 0) + 1;
        }
    }

    public function hasProvenDifferences(): bool
    {
        return $this->provenDifferences > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->reasonCounts);
        ksort($this->byCurrency);

        return [
            'scope' => $this->scope->kind(),
            'scanned' => $this->scanned,
            'matched' => $this->matched,
            'proven_differences' => $this->provenDifferences,
            'undetermined' => $this->undetermined,
            'reason_counts' => $this->reasonCounts,
            'by_currency' => $this->byCurrency,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
        ];
    }
}
