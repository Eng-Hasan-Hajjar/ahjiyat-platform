<?php

namespace App\Console\Commands;

use App\Models\CurrencyTransaction;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralRewardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E33 - مطابقة الإحالات مع دفتر المحفظة. **للقراءة فقط دائمًا** (لا تعديل ولا تسليم ولا خصم): تكشف الانحرافات وتُرجع رمز خروج 1 إن وُجدت.
 * الفحوص: إحالة ذاتية؛ تكرار منطقي لنفس المُحال؛ مؤهَّلة بلا وقت تأهيل؛ حالة/مكافأة غير متسقة؛ جانب مسلَّم بلا قيد دفتر؛ قيد دفتر بلا حالة مناسبة أو بمبلغ مغاير للقطة؛
 * عدم تطابق الحملة؛ رمز يخص غير المحيل. العينات معرّفات داخلية فقط (بلا بريد أو اسم).
 */
class ReconcileReferrals extends Command
{
    protected $signature = 'referrals:reconcile {--json : مخرجات JSON} {--chunk=500 : حجم دفعة القراءة}';

    protected $description = 'E33: يقارن الإحالات بدفتر المحفظة ويكشف الانحراف. قراءة فقط (لا يصلح شيئًا)؛ رمز الخروج 1 عند وجود انحراف.';

    public function handle(): int
    {
        $chunk = max(50, min(2000, (int) $this->option('chunk')));
        $found = [];
        $add = function (string $check, int $id) use (&$found) {
            $found[$check] ??= ['count' => 0, 'sample' => []];
            $found[$check]['count']++;

            if (count($found[$check]['sample']) < 10) {
                $found[$check]['sample'][] = $id;
            }
        };

        UserReferral::query()->whereColumn('referrer_user_id', 'referred_user_id')->orderBy('id')->pluck('id')->each(fn ($id) => $add('self_referral', (int) $id));

        DB::table('user_referrals')->select('referred_user_id', DB::raw('min(id) as id'), DB::raw('count(*) as c'))->groupBy('referred_user_id')->having('c', '>', 1)->get()
            ->each(fn ($r) => $add('duplicate_logical_referral', (int) $r->id));

        UserReferral::query()->whereIn('status', UserReferral::QUALIFIED_STATUSES)->whereNull('qualified_at')->pluck('id')->each(fn ($id) => $add('qualified_without_timestamp', (int) $id));

        UserReferral::query()->where(fn ($q) => $q->where('status', UserReferral::STATUS_REWARDED)->where('reward_status', '!=', UserReferral::REWARD_GRANTED))
            ->orWhere(fn ($q) => $q->where('status', UserReferral::STATUS_QUALIFIED)->where('reward_status', UserReferral::REWARD_NONE))
            ->pluck('id')->each(fn ($id) => $add('status_reward_inconsistent', (int) $id));

        UserReferral::query()->whereNotNull('snap_campaign_id')->whereNotNull('referral_campaign_id')->whereColumn('snap_campaign_id', '!=', 'referral_campaign_id')
            ->pluck('id')->each(fn ($id) => $add('campaign_mismatch', (int) $id));

        UserReferral::query()->where('reward_status', UserReferral::REWARD_PENDING)->whereNull('snap_campaign_id')->pluck('id')->each(fn ($id) => $add('campaign_mismatch', (int) $id));

        DB::table('user_referrals')->join('referral_codes', 'referral_codes.id', '=', 'user_referrals.referral_code_id')
            ->whereColumn('referral_codes.user_id', '!=', 'user_referrals.referrer_user_id')->pluck('user_referrals.id')->each(fn ($id) => $add('code_owner_mismatch', (int) $id));

        // جانب مسلَّم يلزمه قيد دفتر بالمفتاح الدلالي وبالمبلغ نفسه.
        UserReferral::query()->where(fn ($q) => $q->whereNotNull('referrer_granted_at')->orWhereNotNull('referred_granted_at'))->orderBy('id')->chunkById($chunk, function ($rows) use ($add) {
            $keys = [];

            foreach ($rows as $r) {
                foreach (ReferralRewardService::SIDES as $side => $cols) {
                    if ($r->{$cols['at']} !== null) {
                        $keys[ReferralRewardService::ledgerKey($r->id, $side)] = [$r->id, (int) $r->{$cols['gems']}];
                    }
                }
            }

            $ledger = CurrencyTransaction::query()->whereIn('idempotency_key', array_keys($keys))->pluck('amount', 'idempotency_key');

            foreach ($keys as $key => [$id, $gems]) {
                if (! $ledger->has($key)) {
                    $add('rewarded_without_ledger', $id);
                } elseif ((int) $ledger[$key] !== $gems) {
                    $add('ledger_amount_mismatch', $id);
                }
            }
        });

        // قيد دفتر لا تسنده حالة إحالة سليمة.
        CurrencyTransaction::query()->where('idempotency_key', 'like', 'referral-qualified:%')->orderBy('id')->chunkById($chunk, function ($txs) use ($add) {
            foreach ($txs as $tx) {
                [, $id, $side] = array_pad(explode(':', (string) $tx->idempotency_key), 3, null);
                $r = ctype_digit((string) $id) ? UserReferral::query()->find((int) $id) : null;
                $cols = ReferralRewardService::SIDES[$side] ?? null;

                if ($r === null || $cols === null || $r->{$cols['at']} === null) {
                    $add('ledger_without_referral_state', (int) $tx->id);
                }
            }
        });

        ksort($found);
        $ok = $found === [];

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'findings' => $found], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($ok) {
            $this->info('الإحالات مطابقة لدفتر المحفظة (قراءة فقط: لم يُعدَّل شيء).');
        } else {
            foreach ($found as $check => $f) {
                $this->warn(sprintf('%s: %d (عينة: %s)', $check, $f['count'], implode(', ', $f['sample'])));
            }
            $this->line('قراءة فقط: لم يُعدَّل شيء. صحّح المصدر يدويًا ثم أعد التشغيل.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
