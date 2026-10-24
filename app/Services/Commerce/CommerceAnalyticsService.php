<?php

namespace App\Services\Commerce;

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Support\Facades\DB;

/**
 * E41 - تحليلات التجارة الاستهلاكية للإدارة: **تجميعية فقط** (أعداد ونسب ومجاميع بالوحدة الصغرى).
 *  - **كل مبلغ مجمَّع بالعملة** (لا تحويل عملات ولا مجموع مختلط): سعر صرف وهمي يكذب على المالية.
 *  - لا قائمة أفراد ولا ترتيب «أكبر منفقين» ولا بريد/اسم/مرجع تحويل. أعداد فقط للمراجعات.
 *  - نافذة زمنية محدودة (commerce.analytics.window_days) وسقف صفوف لحساب الكمون؛ استعلامات مجمّعة بلا N+1، وبلا كاش لحالات الدفع.
 */
class CommerceAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(?int $days = null): array
    {
        $days = max(1, min((int) ($days ?? config('commerce.analytics.window_days', 30)), 365));
        $since = now()->subDays($days);

        // 1) قمع الطلبات حسب (مزوّد، حالة، عملة) لطلبات أُنشئت في النافذة.
        $rows = DigitalPurchase::query()->where('created_at', '>=', $since)
            ->selectRaw('provider, status, currency, COUNT(*) AS n, SUM(amount_minor) AS total')
            ->groupBy('provider', 'status', 'currency')->get();

        $funnel = [];
        $byProvider = [];

        foreach ($rows as $r) {
            $funnel[$r->status] = ($funnel[$r->status] ?? 0) + (int) $r->n;
            $p = &$byProvider[$r->provider];
            $p ??= ['created' => 0, 'verified' => 0, 'refunded' => 0, 'failed_or_cancelled' => 0, 'abandoned' => 0];
            $p['created'] += (int) $r->n;

            if ($r->status === DigitalPurchase::STATUS_VERIFIED) {
                $p['verified'] += (int) $r->n;
            } elseif ($r->status === DigitalPurchase::STATUS_REFUNDED) {
                $p['refunded'] += (int) $r->n;
            } elseif (in_array($r->status, [DigitalPurchase::STATUS_EXPIRED, DigitalPurchase::STATUS_CANCELLED], true)) {
                $p['abandoned'] += (int) $r->n;
            } elseif ($r->status === DigitalPurchase::STATUS_REJECTED) {
                $p['failed_or_cancelled'] += (int) $r->n;
            }
            unset($p);
        }

        // 2) فشل/إلغاء المزوّد (حالة المزوّد المبلَّغة) ونجاحه لطلبات المزوّد المستضاف.
        $providerStates = DigitalPurchase::query()->where('created_at', '>=', $since)->where('provider', '<>', 'manual')->whereNotNull('provider_status')
            ->selectRaw('provider, provider_status, COUNT(*) AS n')->groupBy('provider', 'provider_status')->get();

        foreach ($providerStates as $s) {
            $byProvider[$s->provider] ??= ['created' => 0, 'verified' => 0, 'refunded' => 0, 'failed_or_cancelled' => 0, 'abandoned' => 0];
            $byProvider[$s->provider]['states'][$s->provider_status] = (int) $s->n;
        }

        foreach ($byProvider as $key => &$p) {
            $paid = ($p['states']['paid'] ?? 0) + ($p['states']['refunded'] ?? 0);
            $failed = ($p['states']['failed'] ?? 0) + ($p['states']['cancelled'] ?? 0);
            $decided = $paid + $failed;
            $p['success_rate'] = $decided > 0 ? round($paid / $decided, 4) : null;
            $p['failure_rate'] = $decided > 0 ? round($failed / $decided, 4) : null;
            $p['conversion_rate'] = $p['created'] > 0 ? round(($p['verified'] + $p['refunded']) / $p['created'], 4) : null;
            $p['abandonment_rate'] = $p['created'] > 0 ? round($p['abandoned'] / $p['created'], 4) : null;
            $p['states'] ??= [];
        }
        unset($p);

        $created = array_sum($funnel);
        $verifiedEver = ($funnel[DigitalPurchase::STATUS_VERIFIED] ?? 0) + ($funnel[DigitalPurchase::STATUS_REFUNDED] ?? 0);

        // 3) مبيعات محقَّقة إجمالية (تشمل ما استُرد لاحقًا) واستردادات، لكل عملة.
        $gross = DigitalPurchase::query()->whereNotNull('verified_at')->where('verified_at', '>=', $since)
            ->selectRaw('currency, COUNT(*) AS n, SUM(amount_minor) AS total')->groupBy('currency')->get()->keyBy('currency');
        $refunds = DigitalPurchase::query()->where('status', DigitalPurchase::STATUS_REFUNDED)->whereNotNull('refunded_at')->where('refunded_at', '>=', $since)
            ->selectRaw('currency, COUNT(*) AS n, SUM(amount_minor) AS total')->groupBy('currency')->get()->keyBy('currency');

        $currencies = [];

        foreach ($gross->keys()->merge($refunds->keys())->unique()->sort()->values() as $currency) {
            $g = (int) ($gross[$currency]->total ?? 0);
            $r = (int) ($refunds[$currency]->total ?? 0);
            $currencies[$currency] = [
                'verified_count' => (int) ($gross[$currency]->n ?? 0),
                'gross_minor' => $g,
                'gross_label' => SponsorshipMoney::format($g, $currency),
                'refund_count' => (int) ($refunds[$currency]->n ?? 0),
                'refund_minor' => $r,
                'refund_label' => SponsorshipMoney::format($r, $currency),
                'net_minor' => $g - $r,
                'net_label' => SponsorshipMoney::format($g - $r, $currency),
            ];
        }

        // 4) متوسط كمون التحقق (ثوانٍ) لكل نوع (مستضاف/يدوي) — محدود بسقف صفوف حديثة.
        $latency = ['hosted' => [], 'manual' => []];
        DigitalPurchase::query()->whereNotNull('verified_at')->where('verified_at', '>=', $since)
            ->orderByDesc('id')->limit(5000)->get(['provider', 'created_at', 'verified_at'])
            ->each(function (DigitalPurchase $p) use (&$latency): void {
                $latency[$p->provider === 'manual' ? 'manual' : 'hosted'][] = max(0, $p->verified_at->diffInSeconds($p->created_at, true));
            });

        // 5) أعداد علامات المراجعة (تجميعي) + نتائج سجل المزوّد.
        $review = DigitalPurchase::query()->whereNotNull('review_flag')->where('review_flagged_at', '>=', $since)
            ->selectRaw('review_flag, COUNT(*) AS n')->groupBy('review_flag')->pluck('n', 'review_flag')->map(fn ($n) => (int) $n)->all();

        $ledger = CommerceProviderEvent::query()->where('received_at', '>=', $since)
            ->selectRaw('provider, processing_result, COUNT(*) AS n')->groupBy('provider', 'processing_result')->get();
        $ledgerOut = [];

        foreach ($ledger as $l) {
            $ledgerOut[$l->provider][$l->processing_result] = (int) $l->n;
        }

        $hostedCreated = array_sum(array_map(fn ($k, $v) => $k === 'manual' ? 0 : $v['created'], array_keys($byProvider), $byProvider));
        $manualCreated = $byProvider['manual']['created'] ?? 0;

        return [
            'window_days' => $days,
            'created' => $created,
            'conversion_rate' => $created > 0 ? round($verifiedEver / $created, 4) : null,
            'funnel' => $funnel,
            'currencies' => $currencies,
            'by_provider' => $byProvider,
            'mix' => ['hosted' => $hostedCreated, 'manual' => $manualCreated],
            'latency_seconds' => [
                'hosted' => $latency['hosted'] !== [] ? (int) round(array_sum($latency['hosted']) / count($latency['hosted'])) : null,
                'manual' => $latency['manual'] !== [] ? (int) round(array_sum($latency['manual']) / count($latency['manual'])) : null,
            ],
            'review_flags' => $review,
            'ledger' => $ledgerOut,
        ];
    }
}
