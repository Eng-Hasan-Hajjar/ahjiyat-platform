<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupportPointEntry;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterStat;
use App\Models\SponsorshipPayment;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E44 - مطابقة تقدير الداعمين: **دفتر النقاط هو السلطة** والإحصاءات مشتقّة منه، والبطاقة تتبع حالة دفعها E32.
 *
 * - inspect(): قراءة فقط (استعلامات مجمَّعة، بلا استعلام لكل صف) ⇒ قائمة نتائج {code, ...}؛ فارغة = متسق. يستعملها أمر seasonal-recognition:reconcile (بلا خيار إصلاح) وصفحة الإدارة.
 * - إجراءات صريحة وموثَّقة (لا تعمل ضمنيًا): rebuildStats (إعادة اشتقاق المجاميع من الدفتر قبل الإنهاء)، activatePendingCards (إعادة تفعيل بطاقات دفعها محقَّق)،
 *   backfillStorePurchase (منح شراء متجر منفَّذ فاته المنح - ومنها حالة الغموض - إلى موسم يحدده المشرف صراحةً).
 * لا شيء منها يلمس دفتر النقاط بتعديل/حذف (إلحاق فقط) ولا يمسّ المال.
 */
class SeasonSupporterReconciler
{
    public const AUDIT_PERMISSION = 'seasonal-recognition.audit';

    public const MANAGE_PERMISSION = 'seasonal-recognition.manage';

    public function __construct(
        protected SeasonSupportPointService $points,
        protected SeasonSupporterCardService $cards,
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonalRecognitionBroadcaster $broadcaster,
        protected OperationalAuditService $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function inspect(Season $season, ?int $limit = null): array
    {
        $limit ??= max(1, (int) config('seasonal_recognition.limits.reconcile_default_limit', 5000));
        $seasonId = (int) $season->id;
        $findings = [];

        // 1) إحصاءات المستخدمين مقابل الدفتر (الإجمالي والعلني).
        $rows = DB::table('season_supporter_stats as s')
            ->leftJoin('season_support_point_entries as e', function ($join) {
                $join->on('e.season_id', '=', 's.season_id')->on('e.user_id', '=', 's.user_id');
            })
            ->where('s.season_id', $seasonId)
            ->groupBy('s.id', 's.user_id', 's.points_total', 's.public_points_total')
            ->havingRaw('s.points_total <> COALESCE(SUM(e.points_delta), 0) OR s.public_points_total <> COALESCE(SUM(CASE WHEN e.is_public = 1 THEN e.points_delta ELSE 0 END), 0) OR s.points_total < 0')
            ->select('s.user_id', 's.points_total', 's.public_points_total')
            ->selectRaw('COALESCE(SUM(e.points_delta), 0) as ledger_total, COALESCE(SUM(CASE WHEN e.is_public = 1 THEN e.points_delta ELSE 0 END), 0) as ledger_public')
            ->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = [
                'code' => (int) $r->points_total < 0 ? 'negative_total' : 'stat_points_mismatch',
                'user_id' => (int) $r->user_id,
                'stored' => (int) $r->points_total, 'ledger' => (int) $r->ledger_total,
                'stored_public' => (int) $r->public_points_total, 'ledger_public' => (int) $r->ledger_public,
            ];
        }

        // 2) قيود بلا صف إحصاء.
        $rows = DB::table('season_support_point_entries as e')
            ->leftJoin('season_supporter_stats as s', function ($join) {
                $join->on('s.season_id', '=', 'e.season_id')->on('s.user_id', '=', 'e.user_id');
            })
            ->where('e.season_id', $seasonId)->whereNull('s.id')->select('e.id', 'e.user_id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'entry_without_stat', 'entry_id' => (int) $r->id, 'user_id' => (int) $r->user_id];
        }

        // 3) عكس لا يطابق أصله (القيمة/الموسم/المستخدم/الخصوصية).
        $rows = DB::table('season_support_point_entries as r')
            ->join('season_support_point_entries as g', 'g.id', '=', 'r.reverses_entry_id')
            ->where('r.season_id', $seasonId)
            ->where(function ($q) {
                $q->whereRaw('r.points_delta <> -g.points_delta')->orWhereColumn('r.user_id', '<>', 'g.user_id')->orWhereColumn('r.season_id', '<>', 'g.season_id')->orWhereColumn('r.is_public', '<>', 'g.is_public');
            })->select('r.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'reversal_mismatch', 'entry_id' => (int) $r->id];
        }

        // 4) بطاقات: نشطة بلا منح، مستردة بلا عكس، معلّقة ودفعها محقَّق (تفعيل فائت)، ودفع لا يطابق اللقطة.
        $rows = DB::table('season_supporter_cards as c')
            ->leftJoin('season_support_point_entries as g', function ($join) {
                $join->on('g.source_id', '=', 'c.id')->where('g.source_type', '=', SeasonSupportPointEntry::SOURCE_SUPPORTER_CARD)->where('g.reason', '=', SeasonSupportPointEntry::REASON_GRANT);
            })
            ->where('c.season_id', $seasonId)->where('c.status', SeasonSupporterCard::STATUS_ACTIVE)->where('c.support_points', '>', 0)->whereNull('g.id')
            ->select('c.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'card_active_without_grant', 'card_id' => (int) $r->id];
        }

        $rows = DB::table('season_supporter_cards as c')
            ->join('season_support_point_entries as g', function ($join) {
                $join->on('g.source_id', '=', 'c.id')->where('g.source_type', '=', SeasonSupportPointEntry::SOURCE_SUPPORTER_CARD)->where('g.reason', '=', SeasonSupportPointEntry::REASON_GRANT);
            })
            ->leftJoin('season_support_point_entries as r', 'r.reverses_entry_id', '=', 'g.id')
            ->where('c.season_id', $seasonId)->where('c.status', SeasonSupporterCard::STATUS_REFUNDED)->whereNull('r.id')
            ->select('c.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'card_refunded_not_reversed', 'card_id' => (int) $r->id];
        }

        $rows = DB::table('season_supporter_cards as c')
            ->join('sponsorship_payments as p', 'p.id', '=', 'c.sponsorship_payment_id')
            ->where('c.season_id', $seasonId)->where('c.status', SeasonSupporterCard::STATUS_PENDING)->where('p.status', SponsorshipPayment::STATUS_VERIFIED)
            ->select('c.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'card_pending_payment_verified', 'card_id' => (int) $r->id];
        }

        $rows = DB::table('season_supporter_cards as c')
            ->join('sponsorship_payments as p', 'p.id', '=', 'c.sponsorship_payment_id')
            ->where('c.season_id', $seasonId)
            ->where(function ($q) {
                $q->whereColumn('p.amount_minor', '<>', 'c.price_minor')->orWhereColumn('p.currency', '<>', 'c.currency')->orWhereColumn('p.user_id', '<>', 'c.user_id');
            })->select('c.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'card_payment_mismatch', 'card_id' => (int) $r->id];
        }

        // 5) مشتريات المتجر ضمن نافذة الموسم: منفَّذة بلقطة نقاط لكن بلا منح (منها حالة الغموض)، ومستردة بمنح لم يُعكس.
        $start = $this->settings->seasonStart($season);
        $end = $this->settings->seasonEnd($season);

        $missing = DB::table('store_purchases as p')
            ->leftJoin('season_support_point_entries as g', function ($join) {
                $join->on('g.source_id', '=', 'p.id')->where('g.source_type', '=', SeasonSupportPointEntry::SOURCE_STORE_PURCHASE)->where('g.reason', '=', SeasonSupportPointEntry::REASON_GRANT);
            })
            ->where('p.status', StorePurchase::STATUS_FULFILLED)->where('p.support_points_snapshot', '>', 0)->whereNull('g.id')
            ->when($start !== null, fn ($q) => $q->where('p.fulfilled_at', '>=', $start))
            ->when($end !== null, fn ($q) => $q->where('p.fulfilled_at', '<=', $end))
            ->select('p.id')->limit($limit)->get();

        foreach ($missing as $r) {
            $findings[] = ['code' => 'store_purchase_without_grant', 'purchase_id' => (int) $r->id];
        }

        $rows = DB::table('store_purchases as p')
            ->join('season_support_point_entries as g', function ($join) {
                $join->on('g.source_id', '=', 'p.id')->where('g.source_type', '=', SeasonSupportPointEntry::SOURCE_STORE_PURCHASE)->where('g.reason', '=', SeasonSupportPointEntry::REASON_GRANT);
            })
            ->leftJoin('season_support_point_entries as r', 'r.reverses_entry_id', '=', 'g.id')
            ->where('g.season_id', $seasonId)->where('p.status', StorePurchase::STATUS_REFUNDED)->whereNull('r.id')
            ->select('p.id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'store_purchase_refunded_not_reversed', 'purchase_id' => (int) $r->id];
        }

        // 6) لقطة نهائية ناقصة.
        $setting = $this->settings->settingFor($season);

        // صف إحصاء بلا ختم إنهاء **وله قيود** = لقطة ناقصة. صف فارغ بلا أي قيد (أنشأه سباق مع الإنهاء) لا يحمل ترتيبًا ولا نقاطًا فلا يُعدّ مخالفة.
        if ($setting?->supporters_finalized_at !== null && SeasonSupporterStat::query()->where('season_id', $seasonId)->whereNull('finalized_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('season_support_point_entries as e')->whereColumn('e.season_id', 'season_supporter_stats.season_id')->whereColumn('e.user_id', 'season_supporter_stats.user_id'))
            ->exists()) {
            $findings[] = ['code' => 'supporters_snapshot_incomplete'];
        }

        return array_slice($findings, 0, $limit);
    }

    /**
     * إعادة اشتقاق مجاميع الإحصاء من الدفتر (قبل الإنهاء فقط). كل مستخدم بمعاملة قصيرة تقفل صفه.
     *
     * @return int عدد الصفوف المصحَّحة
     *
     * @throws SeasonalRecognitionException
     */
    public function rebuildStats(Season $season, User $actor): int
    {
        $this->authorize($actor, self::AUDIT_PERMISSION);

        if ($this->points->supportersFinalized((int) $season->id)) {
            throw new SeasonalRecognitionException('finalized', 'انتهى ترتيب الداعمين؛ لا تُعاد مجاميعه.');
        }

        $users = collect($this->inspect($season))->whereIn('code', ['stat_points_mismatch', 'negative_total'])->pluck('user_id')->unique()->values();
        $fixed = 0;

        foreach ($users as $userId) {
            $fixed += DB::transaction(function () use ($season, $userId): int {
                $stat = $this->points->lockStat((int) $season->id, (int) $userId);

                $sums = DB::table('season_support_point_entries')->where('season_id', $season->id)->where('user_id', $userId)
                    ->selectRaw('COALESCE(SUM(points_delta), 0) as t, COALESCE(SUM(CASE WHEN is_public = 1 THEN points_delta ELSE 0 END), 0) as p')->first();

                if ((int) $stat->points_total === (int) $sums->t && (int) $stat->public_points_total === (int) $sums->p) {
                    return 0;
                }

                $stat->forceFill(['points_total' => (int) $sums->t, 'public_points_total' => (int) $sums->p])->save();

                return 1;
            });
        }

        if ($fixed > 0) {
            $this->audit->log('seasonal_supporters_stats_rebuilt', $season, ['season' => $season->slug, 'rows' => $fixed], $actor);
            SeasonalRecognitionCache::bump((int) $season->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
            $this->broadcaster->supportersUpdated((int) $season->id);
        }

        return $fixed;
    }

    /**
     * يعيد تفعيل بطاقات الموسم المعلّقة ودفعها محقَّق (متكرر الأمان). @return int عدد البطاقات المفعَّلة الآن
     *
     * @throws SeasonalRecognitionException
     */
    public function activatePendingCards(Season $season, User $actor): int
    {
        $this->authorize($actor, self::MANAGE_PERMISSION);

        $activated = 0;

        SeasonSupporterCard::query()->where('season_id', $season->id)->where('status', SeasonSupporterCard::STATUS_PENDING)->orderBy('id')->pluck('sponsorship_payment_id')
            ->each(function ($paymentId) use (&$activated): void {
                if ($this->cards->activateForPayment((int) $paymentId) !== null) {
                    $activated++;
                }
            });

        if ($activated > 0) {
            $this->audit->log('seasonal_supporter_cards_reactivated', $season, ['season' => $season->slug, 'cards' => $activated], $actor);
        }

        return $activated;
    }

    /**
     * منح نقاط شراء متجر منفَّذ فاته المنح (غموض/فشل) إلى **موسم يحدده المشرف صراحةً**. يشترط: منفَّذ، لقطته > 0، لا منح سابق، والموسم لم يُنهِ لقطته.
     *
     * @throws SeasonalRecognitionException
     */
    public function backfillStorePurchase(Season $season, StorePurchase $purchase, User $actor): bool
    {
        $this->authorize($actor, self::MANAGE_PERMISSION);

        $locked = StorePurchase::query()->find($purchase->id);
        $snapshot = (int) $locked?->getAttribute('support_points_snapshot');

        if ($locked === null || $locked->status !== StorePurchase::STATUS_FULFILLED || $snapshot <= 0) {
            throw new SeasonalRecognitionException('not_grantable', 'هذا الشراء لا يستوجب نقاط دعم.');
        }

        $entry = $this->points->grant((int) $season->id, (int) $locked->user_id, SeasonSupportPointEntry::SOURCE_STORE_PURCHASE, (int) $locked->id, $snapshot, true);

        if ($entry === null) {
            return false;
        }

        $this->audit->log('seasonal_support_backfilled', $locked, ['season' => $season->slug, 'store_purchase_id' => $locked->id, 'points' => $snapshot], $actor);
        SeasonalRecognitionCache::bump((int) $season->id, SeasonalRecognitionCache::SCOPE_SUPPORTERS);
        $this->broadcaster->supportersUpdated((int) $season->id);

        return true;
    }

    /** @throws SeasonalRecognitionException */
    protected function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك الصلاحية المطلوبة لهذا الإجراء.');
        }
    }
}
