<?php

namespace App\Console\Commands;

use App\Models\UserReferral;
use App\Services\Referrals\ReferralQualificationService;
use App\Services\Referrals\ReferralRewardService;
use Illuminate\Console\Command;

class ProcessReferrals extends Command
{
    protected $signature = 'referrals:process {--chunk=200 : حجم دفعة القراءة}';

    protected $description = 'E33: يستكمل الإحالات المعلّقة المنتظرة لعمر الحساب (له إجراء رسمي مسجَّل) ويعيد تسليم مكافآت جانب فشل تسليمه. آمن للتكرار (Idempotent) ولا يؤهّل إلا ما استوفى كل الشروط.';

    public function handle(ReferralQualificationService $qualification, ReferralRewardService $rewards): int
    {
        if (! (bool) config('referrals.enabled')) {
            $this->info('الإحالات معطّلة (REFERRALS_ENABLED=false).');

            return self::SUCCESS;
        }

        $chunk = max(50, min(1000, (int) $this->option('chunk')));
        $qualified = 0;
        $held = 0;
        $delivered = 0;

        UserReferral::query()->where('status', UserReferral::STATUS_PENDING)->whereNotNull('action_at')->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($qualification, &$qualified, &$held) {
                foreach ($rows as $row) {
                    $after = $qualification->evaluate($row->id);

                    match ($after?->status) {
                        UserReferral::STATUS_QUALIFIED, UserReferral::STATUS_REWARDED => $qualified++,
                        UserReferral::STATUS_PENDING_REVIEW => $held++,
                        default => null,
                    };
                }
            });

        // إعادة تسليم جوانب لم تُسلَّم بعد (فشل سابق/محفظة مجمَّدة زال تجميدها). المفتاح الدلالي في الدفتر يمنع الازدواج.
        UserReferral::query()->where('status', UserReferral::STATUS_QUALIFIED)->where('reward_status', UserReferral::REWARD_PENDING)->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($rewards, &$delivered) {
                foreach ($rows as $row) {
                    $delivered += $rewards->deliver($row->id);
                }
            });

        $this->info("تأهّلت {$qualified} · علّقت للمراجعة {$held} · جوانب مكافآت سُلّمت {$delivered}.");

        return self::SUCCESS;
    }
}
