<?php

namespace App\Console\Commands;

use App\Models\CreatorRevenuePeriod;
use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceException;
use App\Services\CreatorFinance\CreatorMoney;
use App\Services\CreatorFinance\CreatorRevenuePeriodService;
use Illuminate\Console\Command;

class CreatorRevenueFinalize extends Command
{
    protected $signature = 'creator-revenue:finalize {period : public_id لفترة الإيراد (قيد المراجعة)} {--actor= : بريد أو public_id للمسؤول المنفِّذ (يلزمه creator-finance.finalize)} {--confirm= : أعد كتابة public_id الفترة نفسه للتأكيد}';

    protected $description = 'E39: إقفال فترة إيراد صراحةً (يوزّع المجمّع ويكتب التخصيصات والدفتر). لا جدولة تلقائية؛ يتطلب فترة صريحة ومسؤولًا ومطابقة تأكيد. إعادة التشغيل على فترة مُقفلة بلا أثر.';

    public function handle(CreatorRevenuePeriodService $service): int
    {
        $publicId = (string) $this->argument('period');

        if ((string) $this->option('confirm') !== $publicId) {
            $this->error('التأكيد مطلوب: أضف --confirm='.$publicId.' لإقفال هذه الفترة (لا رجعة بعد الإقفال).');

            return self::FAILURE;
        }

        $identifier = trim((string) $this->option('actor'));

        if ($identifier === '') {
            $this->error('حدّد المسؤول المنفِّذ بـ --actor=<بريد|public_id>.');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', $identifier)->orWhere('public_id', $identifier)->first();

        if ($actor === null) {
            $this->error('لا مسؤول بهذا المعرّف.');

            return self::FAILURE;
        }

        $period = CreatorRevenuePeriod::query()->where('public_id', $publicId)->first();

        if ($period === null) {
            $this->error('لا فترة بهذا المعرّف العام.');

            return self::FAILURE;
        }

        $already = $period->isFinalized();

        try {
            $period = $service->finalize($period, $actor);
        } catch (CreatorFinanceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $c = $period->currency;
        $this->info(($already ? 'الفترة مُقفلة مسبقًا (بلا أي أثر جديد). ' : 'أُقفلت الفترة. ').sprintf('الموزَّع: %s — غير الموزَّع: %s — مبدعون: %d', CreatorMoney::format((int) $period->allocated_minor, $c), CreatorMoney::format((int) $period->unallocated_minor, $c), (int) $period->eligible_creators));

        return self::SUCCESS;
    }
}
