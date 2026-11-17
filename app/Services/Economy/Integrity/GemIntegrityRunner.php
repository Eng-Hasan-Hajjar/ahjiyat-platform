<?php

namespace App\Services\Economy\Integrity;

use App\Models\GemIntegrityFinding;
use App\Models\GemIntegrityRun;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * E46: يشغّل WalletIntegrityAuditor ويحفظ **نتيجة الفحص فقط** في جدولَي gem_integrity_runs/gem_integrity_findings (سجل تدقيق).
 * لا يكتب في wallets ولا currency_transactions أبدًا، ولا يصلح شيئًا، ولا يقترح تعديلًا على رصيد.
 *
 * - العدّادات في الملخّص دقيقة دائمًا. الحالات المفصّلة تُحفَظ بسقف لكل حكم (MAX_FINDINGS_PER_VERDICT) كي لا ينفجر الجدول؛
 *   تجاوز السقف يُسجَّل في findings_truncated ولا يُخفى.
 * - فحص فشل في منتصفه يُسجَّل failed (لا يصلح «آخر نتيجة موثوقة») ويُعاد رمي الاستثناء.
 * - يُبقي آخر KEEP_RUNS فحصًا ويحذف الأقدم (مع نتائجها بالتتالي): تنظيف ذاتي لجدولَي التدقيق فقط.
 */
class GemIntegrityRunner
{
    public const MAX_FINDINGS_PER_VERDICT = 1000;

    public const KEEP_RUNS = 50;

    public function __construct(protected WalletIntegrityAuditor $auditor) {}

    /**
     * @param  (callable(WalletAuditCase): void)|null  $onCase  يُستدعى لكل حالة بعد تسجيلها (للتقدّم في الأمر)
     */
    public function run(WalletAuditScope $scope, ?int $triggeredBy = null, ?callable $onCase = null): GemIntegrityRun
    {
        $run = GemIntegrityRun::create([
            'status' => GemIntegrityRun::STATUS_RUNNING,
            'scope_kind' => $scope->kind(),
            'scope_currency_ids' => $scope->currencyIds,
            'scope_user_id' => $scope->userId,
            'chunk' => $scope->chunk,
            'grace_seconds' => $scope->graceSeconds,
            'triggered_by' => $triggeredBy,
            'started_at' => Carbon::now(),
        ]);

        $stored = [WalletAuditCase::VERDICT_PROVEN => 0, WalletAuditCase::VERDICT_UNDETERMINED => 0];
        $truncated = false;
        $buffer = [];

        $flush = function () use (&$buffer): void {
            if ($buffer !== []) {
                GemIntegrityFinding::query()->insert($buffer);
                $buffer = [];
            }
        };

        try {
            $summary = $this->auditor->audit($scope, function (WalletAuditCase $case) use ($run, &$stored, &$truncated, &$buffer, $flush, $onCase) {
                if (! $case->isMatched()) {
                    if ($stored[$case->verdict] >= static::MAX_FINDINGS_PER_VERDICT) {
                        $truncated = true;
                    } else {
                        $stored[$case->verdict]++;
                        $buffer[] = [
                            'run_id' => $run->id,
                            'wallet_id' => $case->walletId,
                            'user_id' => $case->userId,
                            'currency_id' => $case->currencyId,
                            'verdict' => $case->verdict,
                            'stored_available' => $case->storedAvailable,
                            'derived_available' => $case->derivedAvailable,
                            'stored_pending' => $case->storedPending,
                            'derived_pending' => $case->derivedPending,
                            'ledger_rows' => $case->ledgerRows,
                            'reasons' => json_encode($case->reasons, JSON_UNESCAPED_UNICODE),
                            'notes' => json_encode($case->notes, JSON_UNESCAPED_UNICODE),
                            'created_at' => Carbon::now(),
                        ];

                        if (count($buffer) >= 100) {
                            $flush();
                        }
                    }
                }

                if ($onCase !== null) {
                    $onCase($case);
                }
            });

            $flush();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => GemIntegrityRun::STATUS_FAILED,
                'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500),
                'finished_at' => Carbon::now(),
            ])->save();

            throw $e;
        }

        $data = $summary->toArray();

        $run->forceFill([
            'status' => GemIntegrityRun::STATUS_COMPLETED,
            'scanned' => $data['scanned'],
            'matched' => $data['matched'],
            'proven_differences' => $data['proven_differences'],
            'undetermined' => $data['undetermined'],
            'reason_counts' => $data['reason_counts'],
            'by_currency' => $data['by_currency'],
            'findings_stored' => array_sum($stored),
            'findings_truncated' => $truncated,
            'finished_at' => $summary->finishedAt,
        ])->save();

        $this->prune();

        return $run->fresh();
    }

    /** يبقي أحدث KEEP_RUNS فحصًا فقط (جدولا التدقيق وحدهما). */
    public function prune(?int $keep = null): int
    {
        $cutoffId = GemIntegrityRun::query()->orderByDesc('id')->skip($keep ?? static::KEEP_RUNS)->take(1)->value('id');

        if ($cutoffId === null) {
            return 0;
        }

        return GemIntegrityRun::query()->where('id', '<=', $cutoffId)->delete();
    }
}
