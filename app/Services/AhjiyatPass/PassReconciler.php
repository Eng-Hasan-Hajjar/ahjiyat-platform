<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\UserPassProgress;
use Illuminate\Support\Facades\DB;

/**
 * E40 - مطابقة سلامة المسار (للقراءة افتراضيًا؛ لا كتابة إلا بـ`--repair` وللعمودين المشتقّين pass_points/highest_tier فقط، من دفتر النقاط وعتبات المسار المقفولة).
 * لا تعدّل دفتر النقاط ولا المطالبات ولا المشتريات ولا تمنح ولا تسحب أي شيء: أي خلل مالي/ملكية يُبلَّغ فقط ويُراجَع يدويًا.
 * استعلامات تجميعية بلا N+1، ومقيَّدة بسقف نتائج (reconcile.max_findings) مع علم truncated.
 *
 * @phpstan-type Finding array{code: string, severity: string, subject: string, message: string}
 */
class PassReconciler
{
    public function __construct(protected PassDefinitionCache $cache) {}

    /** @return array{findings: list<array<string, string>>, counts: array<string, int>, repaired: int, truncated: bool} */
    public function run(bool $repair = false): array
    {
        $max = max(1, (int) config('ahjiyat_pass.reconcile.max_findings', 200));
        $findings = [];
        $counts = [];
        $repaired = 0;
        $truncated = false;

        $add = function (string $code, string $severity, string $subject, string $message) use (&$findings, &$counts, &$truncated, $max): void {
            $counts[$code] = ($counts[$code] ?? 0) + 1;

            if (count($findings) >= $max) {
                $truncated = true;

                return;
            }

            $findings[] = ['code' => $code, 'severity' => $severity, 'subject' => $subject, 'message' => $message];
        };

        // 1) pass_points ≠ مجموع الدفتر.
        $ledger = DB::table('pass_point_grants')->selectRaw('user_pass_progress_id, SUM(points) AS total')->groupBy('user_pass_progress_id');
        DB::table('user_pass_progress as p')
            ->leftJoinSub($ledger, 'g', 'g.user_pass_progress_id', '=', 'p.id')
            ->whereRaw('p.pass_points <> COALESCE(g.total, 0)')
            ->select('p.id', 'p.pass_points', DB::raw('COALESCE(g.total, 0) AS ledger_total'))
            ->orderBy('p.id')
            ->limit($max + 1)
            ->get()
            ->each(fn ($r) => $add('points_mismatch', 'warning', 'progress#'.$r->id, "نقاط المسار {$r->pass_points} ≠ مجموع الدفتر {$r->ledger_total}"));

        // 2) highest_tier ≠ الشريحة المشتقّة من النقاط (المسارات المنشورة فقط؛ عتباتها مقفولة).
        AhjiyatPass::query()->whereNotNull('locked_at')->orderBy('id')->each(function (AhjiyatPass $pass) use ($add, $repair, &$repaired): void {
            $track = $this->cache->build($pass);

            UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->orderBy('id')->chunkById(max(50, (int) config('ahjiyat_pass.reconcile.chunk', 500)), function ($rows) use ($track, $add, $repair, &$repaired): void {
                foreach ($rows as $row) {
                    $expected = $track->tierAt((int) $row->pass_points);

                    if ((int) $row->highest_tier !== $expected) {
                        $add('tier_mismatch', 'warning', 'progress#'.$row->id, "الشريحة {$row->highest_tier} ≠ المتوقعة {$expected} لنقاط {$row->pass_points}");

                        if ($repair) {
                            $row->forceFill(['highest_tier' => $expected])->save();
                            $repaired++;
                        }
                    }
                }
            });
        });

        if ($repair) {
            $repaired += $this->repairPoints();
        }

        // 3) ملكية المميز بلا شراء محقَّق، وشراء محقَّق بلا فتح.
        DB::table('user_pass_progress as p')
            ->whereNotNull('p.premium_unlocked_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('digital_purchases as d')->whereColumn('d.id', 'p.premium_purchase_id')->where('d.status', DigitalPurchase::STATUS_VERIFIED))
            ->select('p.id')->limit($max + 1)->get()
            ->each(fn ($r) => $add('premium_without_verified_purchase', 'critical', 'progress#'.$r->id, 'مميز مفتوح بلا شراء محقَّق مرتبط'));

        DB::table('digital_purchases as d')
            ->where('d.status', DigitalPurchase::STATUS_VERIFIED)
            ->where('d.product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('user_pass_progress as p')->whereColumn('p.premium_purchase_id', 'd.id')->whereNotNull('p.premium_unlocked_at'))
            ->select('d.id', 'd.reference_code')->limit($max + 1)->get()
            ->each(fn ($r) => $add('verified_purchase_not_unlocked', 'critical', 'purchase#'.$r->id, "شراء محقَّق {$r->reference_code} لم يفتح المميز"));

        // 4) مطالبات مميزة بلا شراء محقَّق/مسترد، ومطالبات فوق الشريحة، ومطالبات بلا منح مرتبط.
        DB::table('user_pass_reward_claims as c')
            ->where('c.track', 'premium')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('digital_purchases as d')
                ->whereColumn('d.user_id', 'c.user_id')->whereColumn('d.product_id', 'c.ahjiyat_pass_id')
                ->where('d.product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)
                ->whereIn('d.status', [DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED]))
            ->select('c.id')->limit($max + 1)->get()
            ->each(fn ($r) => $add('premium_claim_without_purchase', 'critical', 'claim#'.$r->id, 'مطالبة مميزة بلا شراء محقَّق/مسترد'));

        DB::table('user_pass_reward_claims as c')
            ->join('user_pass_progress as p', 'p.id', '=', 'c.user_pass_progress_id')
            ->whereColumn('c.tier_number', '>', 'p.highest_tier')
            ->select('c.id', 'c.tier_number', 'p.highest_tier')->limit($max + 1)->get()
            ->each(fn ($r) => $add('claim_above_tier', 'critical', 'claim#'.$r->id, "مطالبة شريحة {$r->tier_number} وأعلى شريحة مبلوغة {$r->highest_tier}"));

        DB::table('user_pass_reward_claims')->whereNull('inventory_transaction_id')->whereNull('user_entitlement_id')
            ->select('id')->limit($max + 1)->get()
            ->each(fn ($r) => $add('claim_unlinked', 'warning', 'claim#'.$r->id, 'مطالبة بلا حركة مخزون أو امتياز مرتبط'));

        // 5) نقاط خارج نافذة المسار.
        DB::table('pass_point_grants as g')
            ->join('user_quest_progress as q', 'q.id', '=', 'g.user_quest_progress_id')
            ->join('ahjiyat_passes as ps', 'ps.id', '=', 'g.ahjiyat_pass_id')
            ->where(fn ($w) => $w->whereColumn('q.completed_at', '<', 'ps.starts_at')->orWhereColumn('q.completed_at', '>=', 'ps.ends_at'))
            ->select('g.id')->limit($max + 1)->get()
            ->each(fn ($r) => $add('grant_outside_window', 'warning', 'grant#'.$r->id, 'نقاط من إكمال خارج نافذة المسار'));

        // 6) تعريف المسار.
        $now = now();
        $live = AhjiyatPass::query()->whereNotNull('locked_at')->whereIn('status', [AhjiyatPass::STATUS_SCHEDULED, AhjiyatPass::STATUS_ACTIVE])
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->get();

        if ($live->count() > 1) {
            $add('multiple_active', 'critical', 'passes', 'أكثر من مسار فعّال في الوقت نفسه: '.$live->pluck('id')->implode(','));
        }

        AhjiyatPass::query()->whereNotNull('locked_at')->whereNot('status', AhjiyatPass::STATUS_ARCHIVED)->orderBy('id')->each(function (AhjiyatPass $pass) use ($add): void {
            $track = $this->cache->build($pass);

            if ((int) $pass->total_tiers !== count($track->tiers)) {
                $add('tier_count_mismatch', 'warning', 'pass#'.$pass->id, "total_tiers {$pass->total_tiers} ≠ الفعلي ".count($track->tiers));
            }

            $maxPoints = array_sum(array_map(fn (array $m): int => (int) $m['points'] * (int) $m['max_completions'], $track->missions));
            $top = $track->tiers === [] ? 0 : (int) ($track->tiers[array_key_last($track->tiers)]['points'] ?? 0);

            if ($maxPoints < $top) {
                $add('unreachable_tiers', 'warning', 'pass#'.$pass->id, "أقصى نقاط ممكنة {$maxPoints} أقل من عتبة آخر شريحة {$top}");
            }

            if ($pass->isClaimWindowOpen()) {
                $itemIds = array_values(array_unique(array_map(fn (array $r): int => (int) $r['item_id'], $track->allRewards())));
                $bad = $itemIds === [] ? 0 : \App\Models\StoreItem::query()->whereIn('id', $itemIds)->where(fn ($q) => $q->where('is_active', false)->orWhere(fn ($e) => $e->whereNotNull('ends_at')->where('ends_at', '<=', now())))->count();

                if ($bad > 0) {
                    $add('reward_item_unavailable', 'warning', 'pass#'.$pass->id, "{$bad} عنصر مكافأة غير نشط أو منتهي");
                }

                $quests = array_keys($track->missions);
                $inactive = $quests === [] ? 0 : \App\Models\QuestDefinition::query()->whereIn('id', $quests)->where('is_active', false)->count();

                if ($inactive > 0) {
                    $add('mission_quest_inactive', 'info', 'pass#'.$pass->id, "{$inactive} مهمة مرتبطة معطَّلة");
                }
            }
        });

        // 7) الشراء: طلبات متأخرة عن الكنس، وإثبات مُعاد الاستخدام.
        $stale = DigitalPurchase::query()->where('status', DigitalPurchase::STATUS_AWAITING)->where('expires_at', '<', now()->subHour())->count();

        if ($stale > 0) {
            $add('stale_awaiting', 'info', 'purchases', "{$stale} طلب بانتظار الدفع تجاوز صلاحيته ولم يُنهَ بعد (شغّل ahjiyat-pass:process)");
        }

        DB::table('digital_purchases')->whereNotNull('proof_sha256')->select('proof_sha256', DB::raw('COUNT(*) AS n'))->groupBy('proof_sha256')->havingRaw('COUNT(*) > 1')->limit($max + 1)->get()
            ->each(fn ($r) => $add('proof_reused', 'warning', 'proof:'.substr((string) $r->proof_sha256, 0, 12), "إثبات واحد استُخدم في {$r->n} طلبات"));

        return ['findings' => $findings, 'counts' => $counts, 'repaired' => $repaired, 'truncated' => $truncated];
    }

    /** يصلح عمود pass_points المشتق فقط من الدفتر (لا يمسّ الدفتر). @return int عدد الصفوف المصلَحة */
    protected function repairPoints(): int
    {
        $ledger = DB::table('pass_point_grants')->selectRaw('user_pass_progress_id, SUM(points) AS total')->groupBy('user_pass_progress_id');
        $ids = DB::table('user_pass_progress as p')
            ->leftJoinSub($ledger, 'g', 'g.user_pass_progress_id', '=', 'p.id')
            ->whereRaw('p.pass_points <> COALESCE(g.total, 0)')
            ->orderBy('p.id')->limit(500)->pluck('p.id');

        $fixed = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$fixed): void {
                $row = UserPassProgress::query()->whereKey($id)->lockForUpdate()->first();

                if ($row === null) {
                    return;
                }

                $total = (int) DB::table('pass_point_grants')->where('user_pass_progress_id', $id)->sum('points');
                $pass = AhjiyatPass::query()->find($row->ahjiyat_pass_id);

                if ($pass === null || $total === (int) $row->pass_points) {
                    return;
                }

                $row->forceFill(['pass_points' => $total, 'highest_tier' => $this->cache->build($pass)->tierAt($total)])->save();
                $fixed++;
            });
        }

        return $fixed;
    }
}
