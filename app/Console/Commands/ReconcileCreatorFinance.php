<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CreatorFinance\CreatorFinanceReconciler;
use Illuminate\Console\Command;

class ReconcileCreatorFinance extends Command
{
    protected $signature = 'creator-finance:reconcile {--fix-balances : أعد بناء لقطات الأرصدة المنحرفة من الدفتر (مشتقّ فقط)} {--limit= : أقصى عدد حسابات/دفعات/فترات تُفحص} {--actor= : بريد أو public_id لتدوينه في سجل التدقيق عند الإصلاح}';

    protected $description = 'E39: مصالحة مالية المبدعين (قراءة فقط): لقطات الحسابات مقابل الدفتر، المحجوز مقابل الدفعات المفتوحة، المصروف مقابل المصروفة، تخصيصات الفترات مقابل قيودها. --fix-balances لا يمسّ إلا اللقطة المشتقة؛ لا يعدّل دفترًا ولا دفعة ولا فترة. رمز الخروج 1 إن بقي أي انحراف.';

    public function handle(CreatorFinanceReconciler $reconciler): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : (int) config('creator_monetization.reconcile.default_limit', 500);
        $actor = null;
        $identifier = trim((string) $this->option('actor'));

        if ($identifier !== '') {
            $actor = User::query()->where('email', $identifier)->orWhere('public_id', $identifier)->first();

            if ($actor === null) {
                $this->error('لا مسؤول بهذا المعرّف.');

                return self::FAILURE;
            }
        }

        $r = $reconciler->run($limit, (bool) $this->option('fix-balances'), $actor);

        $this->line(sprintf('حسابات مفحوصة: %d — فترات مُقفلة مفحوصة: %d — نتائج: %d — أُصلح: %d — متبقٍّ: %d', $r['checked_accounts'], $r['checked_periods'], count($r['findings']), $r['fixed'], $r['remaining']));

        foreach (array_slice($r['findings'], 0, 100) as $f) {
            $this->line(sprintf('  [%s] %s — %s%s', $f['code'], $f['subject'], $f['detail'], $f['fixed'] ? ' (أُصلح)' : ($f['fixable'] ? ' (قابل للإصلاح بـ --fix-balances)' : ' (يدوي)')));
        }

        if ($r['remaining'] === 0) {
            $this->info('الدفتر واللقطات والدفعات والفترات متسقة.');

            return self::SUCCESS;
        }

        $this->warn('بقيت انحرافات تحتاج مراجعة بشرية؛ راجع دليل تشغيل مالية المبدعين.');

        return self::FAILURE;
    }
}
