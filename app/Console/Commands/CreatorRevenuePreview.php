<?php

namespace App\Console\Commands;

use App\Models\CreatorRevenuePeriod;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Illuminate\Console\Command;

class CreatorRevenuePreview extends Command
{
    protected $signature = 'creator-revenue:preview {period : public_id لفترة الإيراد} {--json : مخرجات JSON}';

    protected $description = 'E39: معاينة توزيع فترة إيراد بلا أي كتابة (وحدات مؤهَّلة، استبعادات، حصص). للفترة المُقفلة يعرض الأرقام المخزَّنة.';

    public function handle(CreatorRevenuePeriodService $service): int
    {
        $period = CreatorRevenuePeriod::query()->where('public_id', (string) $this->argument('period'))->first();

        if ($period === null) {
            $this->error('لا فترة بهذا المعرّف العام.');

            return self::FAILURE;
        }

        try {
            $preview = $service->preview($period);
        } catch (CreatorFinanceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $s = $preview['summary'];
        $c = $period->currency;
        $this->line(sprintf('الفترة: %s [%s] (%s → %s) — الحالة: %s%s', $period->label, $period->public_id, $period->starts_at, $period->ends_at, $period->statusLabel(), $preview['finalized'] ? ' — أرقام مخزَّنة' : ' — معاينة فقط (لم يُكتب شيء)'));
        $this->line(sprintf('المجمّع: %s — الموزَّع: %s — غير الموزَّع: %s — مبدعون مؤهَّلون: %d — وحدات: %d', CreatorMoney::format((int) $s['pool_minor'], $c), CreatorMoney::format((int) $s['allocated_minor'], $c), CreatorMoney::format((int) $s['unallocated_minor'], $c), (int) $s['eligible_creators'], (int) $s['total_units']));

        foreach ((array) ($s['ineligible'] ?? []) as $reason => $n) {
            $this->line("  مستبعَد ({$reason}): {$n}");
        }

        $rows = array_map(fn (array $r) => [$r['creator_profile_id'], $r['raw_units'], $r['qualified_units'], $r['unique_players'], $r['eligible'] ? 'نعم' : ($r['reason'] ?? '—'), $r['allocated_minor']], array_slice($preview['rows'], 0, 50));
        $this->table(['creator', 'raw', 'units', 'players', 'مؤهَّل', 'حصة (وحدة صغرى)'], $rows);

        if (count($preview['rows']) > 50) {
            $this->line('… عُرض أول 50 فقط؛ استعمل --json للكل.');
        }

        return self::SUCCESS;
    }
}
