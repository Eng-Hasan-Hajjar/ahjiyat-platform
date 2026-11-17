<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\Integrity\GemIntegrityRunner;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\Economy\Integrity\WalletAuditScope;
use App\Services\Economy\Integrity\WalletIntegrityAuditor;
use App\Services\OperationalAuditService;
use Illuminate\Console\Command;

/**
 * E46: تدقيق «سلامة الجواهر» - **قراءة فقط** على أرصدة المحافظ ودفتر العملات. لا يعدّل رصيدًا ولا دفترًا ولا يصلح شيئًا.
 * الكتابة الوحيدة (ما لم تمرّر --no-save): تسجيل نتيجة الفحص في جدولَي gem_integrity_runs/findings وسطر في سجل العمليات الإدارية.
 *
 * النطاق الافتراضي: عملة «الجواهر المكتسبة» الافتراضية فقط. --currency=KEY (مكرَّر) أو --all-currencies لغيرها؛ كل عملة معزولة عن غيرها.
 * رمز الخروج: 1 إن وُجد «فرق مثبت»، وإلا 0 (الحالات «لا يمكن الجزم» تُعرض ولا تُفشل الأمر؛ هي طلب مراجعة لا حكم).
 */
class GemsIntegrityAudit extends Command
{
    protected $signature = 'gems:audit
        {--user= : معرّف مستخدم واحد (نطاق محدود؛ لا يُعدّ «آخر فحص موثوق» في المركز)}
        {--currency=* : مفتاح عملة داخلي (internal_key) أو معرّفها؛ كرّر الخيار لأكثر من عملة}
        {--all-currencies : كل العملات (كل عملة تُدقَّق بمعزل عن غيرها)}
        {--chunk=500 : حجم دفعة المحافظ (50 - 2000)}
        {--grace=120 : نافذة الحركة الجارية بالثواني: فرق على محفظة تحرّكت خلالها لا يُحسم}
        {--limit=20 : أقصى عدد حالات غير مطابقة تُعرض في الشاشة}
        {--no-save : لا تحفظ النتيجة (عرض فقط)}
        {--json : مخرجات JSON}';

    protected $description = 'E46: يقارن رصيدَي (المتاح/المعلَّق) كل محفظة بدفتر currency_transactions. قراءة فقط: لا يعدّل أرصدة ولا يصلح شيئًا.';

    public function handle(CurrencyRegistry $registry, WalletIntegrityAuditor $auditor, GemIntegrityRunner $runner, OperationalAuditService $audit): int
    {
        $userId = $this->option('user');

        if ($userId !== null && (! ctype_digit((string) $userId) || (int) $userId < 1)) {
            $this->error('--user يجب أن يكون معرّف مستخدم صحيحًا.');

            return self::INVALID;
        }

        $currencyIds = $this->resolveCurrencies($registry);

        if ($currencyIds === false) {
            return self::INVALID;
        }

        $scope = new WalletAuditScope(
            userId: $userId === null ? null : (int) $userId,
            currencyIds: $currencyIds,
            chunk: max(50, min(2000, (int) $this->option('chunk'))),
            graceSeconds: max(0, min(3600, (int) $this->option('grace'))),
        );

        $limit = max(0, (int) $this->option('limit'));
        $shown = [];
        $onCase = function (WalletAuditCase $case) use (&$shown, $limit): void {
            if (! $case->isMatched() && count($shown) < $limit) {
                $shown[] = $case;
            }
        };

        if ($this->option('no-save')) {
            $summary = $auditor->audit($scope, $onCase);
            $data = $summary->toArray();
            $savedRunId = null;
        } else {
            $run = $runner->run($scope, null, $onCase);
            $data = [
                'scope' => $run->scope_kind,
                'scanned' => $run->scanned,
                'matched' => $run->matched,
                'proven_differences' => $run->proven_differences,
                'undetermined' => $run->undetermined,
                'reason_counts' => $run->reason_counts ?? [],
                'by_currency' => $run->by_currency ?? [],
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ];
            $savedRunId = $run->id;

            $audit->log('gem_integrity_scan', $run, [
                'scope' => $run->scope_kind, 'scanned' => $run->scanned,
                'proven_differences' => $run->proven_differences, 'undetermined' => $run->undetermined,
            ]);
        }

        $exit = $data['proven_differences'] > 0 ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $exit === self::SUCCESS,
                'read_only' => true,
                'saved_run_id' => $savedRunId,
                'summary' => $data,
                'cases' => array_map(fn (WalletAuditCase $c) => $c->toArray(), $shown),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $exit;
        }

        $this->renderHuman($data, $shown, $savedRunId);

        return $exit;
    }

    /** @return list<int>|null|false  null = كل العملات، false = خطأ إدخال */
    protected function resolveCurrencies(CurrencyRegistry $registry): array|null|false
    {
        if ($this->option('all-currencies')) {
            return null;
        }

        $requested = array_filter((array) $this->option('currency'), fn ($v) => $v !== null && $v !== '');

        if ($requested === []) {
            return [$registry->defaultEarnedCurrency()->id];
        }

        $ids = [];

        foreach ($requested as $value) {
            $currency = ctype_digit((string) $value)
                ? Currency::query()->find((int) $value)
                : Currency::query()->where('internal_key', (string) $value)->first();

            if ($currency === null) {
                $this->error("عملة غير معروفة: {$value}");

                return false;
            }

            $ids[] = $currency->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<WalletAuditCase>  $shown
     */
    protected function renderHuman(array $data, array $shown, ?int $savedRunId): void
    {
        $this->line('سلامة الجواهر (قراءة فقط: لم يُعدَّل أي رصيد ولا دفتر).');
        $this->table(['البند', 'العدد'], [
            ['محافظ/حالات مفحوصة (scanned)', $data['scanned']],
            [WalletAuditCase::verdictLabel(WalletAuditCase::VERDICT_MATCHED).' (matched)', $data['matched']],
            [WalletAuditCase::verdictLabel(WalletAuditCase::VERDICT_PROVEN).' (proven_difference)', $data['proven_differences']],
            [WalletAuditCase::verdictLabel(WalletAuditCase::VERDICT_UNDETERMINED).' (undetermined)', $data['undetermined']],
        ]);

        foreach ($data['reason_counts'] as $reason => $count) {
            $this->line(sprintf('  - %s: %d  [%s]', WalletAuditCase::reasonLabel($reason), $count, $reason));
        }

        if ($shown !== []) {
            $this->newLine();
            $this->line('حالات غير مطابقة (الترتيب حتمي بمعرّف المحفظة):');
            $this->table(
                ['wallet', 'user', 'currency', 'الحكم', 'المتاح مخزَّن/دفتر', 'المعلَّق مخزَّن/دفتر', 'الأسباب'],
                array_map(fn (WalletAuditCase $c) => [
                    $c->walletId ?? '—', $c->userId, $c->currencyId, WalletAuditCase::verdictLabel($c->verdict),
                    $c->storedAvailable.' / '.$c->derivedAvailable, $c->storedPending.' / '.$c->derivedPending,
                    implode(',', $c->reasons) ?: '—',
                ], $shown),
            );
        }

        if ($savedRunId !== null) {
            $this->line("حُفظت النتيجة برقم فحص #{$savedRunId} (تظهر في مركز العمليات > سلامة الجواهر).");
        } else {
            $this->line('لم تُحفظ النتيجة (--no-save).');
        }

        $this->line('لا يوجد إصلاح تلقائي: أي معالجة تتم يدويًا بعد مراجعة بشرية.');
    }
}
