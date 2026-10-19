<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E33 (E) - تحليلات الاكتساب. **صدق لا دقة وهمية**:
 *  - كل المقاييس «دفعة» (cohort): إحالات أُسنِدت خلال الفترة، ثم ما آلت إليه. فنسبة التحويل = مؤهَّلة ÷ مُسنَدة لنفس الدفعة (لا تقسم أعدادًا من فترات مختلفة).
 *  - النقرات **تقريبية** (فريدة بالساعة، عدّاد تراكمي على الرموز لا يتجزأ بالفترة) ولا تدخل في أي نسبة تحويل؛ المشاركات من عدّادات كاش قصيرة العمر.
 *  - لا بيانات خاصة: لا بريد ولا IP؛ المحيلون الأوائل يُرتَّبون بعدد **المؤهَّلة** فقط (لا بالنقرات ولا بالتسجيلات ولا بالمبالغ) وللمشرفين فقط.
 *  - تجميعات بسيطة بفهارس (جدول مقروء بلا حلقات N+1)، مخزَّنة بالكاش قصير الأمد؛ تحليلات اللاعب الشخصية محصورة بمستخدمها.
 */
class ReferralAnalytics
{
    /**
     * مجاميع الحالات كنص SQL **ثابت** (لا استيفاء متغيرات في SQL خام: يطابق تدقيق E26 W5). يطابقه اختبار AF10-64 مع UserReferral::STATUSES.
     */
    public const STATUS_SUMS = "sum(case when status = 'pending' then 1 else 0 end) as pending, sum(case when status = 'pending_review' then 1 else 0 end) as pending_review, sum(case when status = 'qualified' then 1 else 0 end) as qualified, sum(case when status = 'rewarded' then 1 else 0 end) as rewarded, sum(case when status = 'disqualified' then 1 else 0 end) as disqualified";

    public function __construct(protected ReferralShareStats $shares) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:overview:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $from = now()->subDays($days)->startOfDay();
            $q = fn () => UserReferral::query()->where('created_at', '>=', $from);

            $totals = $q()->selectRaw(self::STATUS_SUMS.', count(*) as attributed, '
                .'sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) as referrer_gems, '
                .'sum(case when referred_granted_at is not null then snap_referred_gems else 0 end) as referred_gems')->first();

            $verified = (int) DB::table('user_referrals')->join('users', 'users.id', '=', 'user_referrals.referred_user_id')
                ->where('user_referrals.created_at', '>=', $from)->whereNotNull('users.email_verified_at')->count();

            $attributed = (int) ($totals->attributed ?? 0);
            $qualified = (int) ($totals->qualified ?? 0) + (int) ($totals->rewarded ?? 0);

            return [
                'days' => $days,
                'attributed' => $attributed,
                'verified' => $verified,
                'qualified' => $qualified,
                'pending' => (int) ($totals->pending ?? 0),
                'under_review' => (int) ($totals->pending_review ?? 0),
                'disqualified' => (int) ($totals->disqualified ?? 0),
                'rewarded' => (int) ($totals->rewarded ?? 0),
                'reward_cost_gems' => (int) ($totals->referrer_gems ?? 0) + (int) ($totals->referred_gems ?? 0),
                'conversion_pct' => $attributed > 0 ? round($qualified / $attributed * 100, 1) : null,
                'clicks_approx_lifetime' => (int) ReferralCode::query()->sum('clicks_count'),
                'shares_7d' => $this->shares->lastDays(7),
            ];
        });
    }

    /**
     * أداء الحملات (للدفعة نفسها). صف «بلا حملة» للإحالات التي لا حملة لها.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:campaigns:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $rows = UserReferral::query()->where('created_at', '>=', now()->subDays($days)->startOfDay())
                ->selectRaw('referral_campaign_id, count(*) as attributed, '.self::STATUS_SUMS.', '
                    .'sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) + sum(case when referred_granted_at is not null then snap_referred_gems else 0 end) as cost')
                ->groupBy('referral_campaign_id')->get();

            $names = ReferralCampaign::query()->whereIn('id', $rows->pluck('referral_campaign_id')->filter()->all())->pluck('name', 'id');

            return $rows->map(fn ($r) => [
                'campaign_id' => $r->referral_campaign_id !== null ? (int) $r->referral_campaign_id : null,
                'name' => $r->referral_campaign_id === null ? 'بلا حملة' : (string) ($names[$r->referral_campaign_id] ?? 'حملة محذوفة'),
                'attributed' => (int) $r->attributed,
                'qualified' => (int) $r->qualified + (int) $r->rewarded,
                'rewarded' => (int) $r->rewarded,
                'cost_gems' => (int) $r->cost,
            ])->sortByDesc('qualified')->values()->all();
        });
    }

    /**
     * أوائل المحيلين: بعدد المؤهَّلة (qualified/rewarded) فقط. للمشرفين؛ لا قائمة صدارة علنية.
     *
     * @return list<array{user_id: int, name: string, qualified: int}>
     */
    public function topReferrers(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::remember("referrals:analytics:top:{$days}", max(10, (int) config('referrals.analytics.cache_seconds')), function () use ($days) {
            $rows = UserReferral::query()->whereIn('status', UserReferral::QUALIFIED_STATUSES)->whereNotNull('referrer_user_id')
                ->where('qualified_at', '>=', now()->subDays($days)->startOfDay())
                ->selectRaw('referrer_user_id, count(*) as qualified')->groupBy('referrer_user_id')
                ->orderByDesc('qualified')->orderBy('referrer_user_id')->limit(max(1, (int) config('referrals.analytics.top_referrers')))->get();

            $names = User::query()->whereIn('id', $rows->pluck('referrer_user_id')->all())->pluck('name', 'id');

            return $rows->map(fn ($r) => ['user_id' => (int) $r->referrer_user_id, 'name' => (string) ($names[$r->referrer_user_id] ?? '—'), 'qualified' => (int) $r->qualified])->all();
        });
    }

    /**
     * أرقام اللاعب الشخصية (مجاميع فقط، بلا أي بيانات عن المُحالين).
     *
     * @return array<string, int>
     */
    public function forReferrer(User $user): array
    {
        $t = UserReferral::query()->where('referrer_user_id', $user->id)
            ->selectRaw('count(*) as total, '.self::STATUS_SUMS.', sum(case when referrer_granted_at is not null then snap_referrer_gems else 0 end) as gems')
            ->first();

        $code = ReferralCode::query()->where('active_for_user_id', $user->id)->first(['clicks_count', 'shares_count']);

        return [
            'total' => (int) ($t->total ?? 0),
            'pending' => (int) ($t->pending ?? 0) + (int) ($t->pending_review ?? 0),
            'qualified' => (int) ($t->qualified ?? 0) + (int) ($t->rewarded ?? 0),
            'rewarded' => (int) ($t->rewarded ?? 0),
            'gems_earned' => (int) ($t->gems ?? 0),
            'clicks_approx' => (int) ($code->clicks_count ?? 0),
            'shares' => (int) ($code->shares_count ?? 0),
        ];
    }

    public function forget(): void
    {
        foreach ([7, 30, 90, 365] as $d) {
            foreach (['overview', 'campaigns', 'top'] as $k) {
                Cache::forget("referrals:analytics:{$k}:{$d}");
            }
        }
    }
}
