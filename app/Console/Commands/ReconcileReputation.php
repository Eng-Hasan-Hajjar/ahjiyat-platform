<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\ReputationConfigException;
use App\Services\Reputation\ReputationRankings;
use Illuminate\Console\Command;

class ReconcileReputation extends Command
{
    protected $signature = 'reputation:reconcile {--fix : أصلح اللقطات المنحرفة (يعيد حسابها) بدل الإبلاغ فقط} {--limit=500 : أقدم عدد لقطات تُفحص} {--user= : public_id لاعب واحد}';

    protected $description = 'E37: يقارن لقطات السمعة بما تحسبه السجلات الأصلية الآن ويبلغ الانحراف (ودرجات خارج المدى وصيغة قديمة ومستخدمين بلا لقطة)؛ --fix يعيد حساب المنحرف. لا يمسّ إلا اللقطات (مشتقّة).';

    public function handle(CommunityReputationService $service, ReputationRankings $rankings): int
    {
        $userId = null;

        if ($this->option('user') !== null) {
            $user = User::query()->where('public_id', (string) $this->option('user'))->first();

            if ($user === null) {
                $this->error('لا لاعب بهذا المعرّف العام.');

                return self::FAILURE;
            }

            $userId = (int) $user->id;
        }

        try {
            $r = $service->reconcile(max(1, (int) $this->option('limit')), (bool) $this->option('fix'), $userId);
        } catch (ReputationConfigException $e) {
            $this->error('إعداد السمعة غير صالح: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('لقطات مفحوصة: %d — منحرفة: %d — صيغة قديمة: %d — درجات خارج المدى: %d — مستخدمون بلا لقطة: %d', $r['checked'], $r['drifted'], $r['outdated_formula'], $r['out_of_range'], $r['missing_subjects']));

        foreach (array_slice($r['drifted_user_ids'], 0, 20) as $id) {
            $this->line('  منحرفة: user#'.$id);
        }

        if ($r['fixed'] && $r['drifted'] > 0) {
            $rankings->flush();
            $this->info('أُعيد حساب '.$r['drifted'].' لقطة.');
        }

        $clean = $r['drifted'] === 0 && $r['out_of_range'] === 0 && $r['outdated_formula'] === 0 && $r['missing_subjects'] === 0;

        if ($clean) {
            $this->info('اللقطات مطابقة للسجلات الأصلية.');

            return self::SUCCESS;
        }

        $this->info($r['fixed'] ? 'تم الإصلاح (المتبقي: صيغة قديمة/غير مكتشَف يُعالَج بـ reputation:recalculate).' : 'لم يُعدَّل شيء. شغّل مع --fix أو reputation:recalculate.');

        return $r['fixed'] ? self::SUCCESS : self::FAILURE;
    }
}
