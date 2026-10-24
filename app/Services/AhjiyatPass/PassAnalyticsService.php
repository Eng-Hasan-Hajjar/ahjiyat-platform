<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\DigitalPurchase;
use App\Models\PassPointGrant;
use App\Models\UserPassProgress;
use App\Models\UserPassRewardClaim;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E40 - تحليلات المسار للإدارة: **تجميعية فقط** (أعداد ونسب ومجاميع بالعملة). لا قائمة أفراد مرتّبة بالإنفاق ولا بيانات شخصية ولا مرجع تحويل.
 * الإيراد = مشتريات محقَّقة صافية من المستردة، إيراد منصة (لا علاقة له بأرباح المبدعين ولا بصندوق الرعاية). مخزَّنة مؤقتًا بمدة قصيرة (تجميع فقط).
 */
class PassAnalyticsService
{
    public function __construct(protected PassDefinitionCache $cache) {}

    /** @return array<string, mixed> */
    public function forPass(AhjiyatPass $pass): array
    {
        $key = 'ahjiyat_pass:analytics:'.$pass->getKey().':v'.$this->cache->version();

        return Cache::remember($key, max(10, (int) config('ahjiyat_pass.cache.analytics_seconds', 120)), fn () => $this->compute($pass));
    }

    /** @return array<string, mixed> */
    protected function compute(AhjiyatPass $pass): array
    {
        $track = $this->cache->build($pass);
        $players = UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->count();
        $active7 = UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->where('last_progress_at', '>=', now()->subDays(7))->count();
        $premium = UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->whereNotNull('premium_unlocked_at')->count();
        $atMax = $track->maxTier() > 0 ? UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->where('highest_tier', '>=', $track->maxTier())->count() : 0;
        $avgTier = (float) UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())->avg('highest_tier');

        $distribution = UserPassProgress::query()->where('ahjiyat_pass_id', $pass->getKey())
            ->selectRaw('highest_tier, COUNT(*) AS n')->groupBy('highest_tier')->orderBy('highest_tier')->pluck('n', 'highest_tier')
            ->map(fn ($n) => (int) $n)->all();

        $funnel = DigitalPurchase::query()->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->where('product_id', $pass->getKey())
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        $revenue = DigitalPurchase::query()->where('product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)->where('product_id', $pass->getKey())
            ->where('status', DigitalPurchase::STATUS_VERIFIED)
            ->selectRaw('currency, SUM(amount_minor) AS total, COUNT(*) AS n')->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'minor' => (int) $r->total, 'label' => SponsorshipMoney::format((int) $r->total, $r->currency), 'count' => (int) $r->n])->all();

        $refunded = (int) ($funnel[DigitalPurchase::STATUS_REFUNDED] ?? 0);

        $claims = UserPassRewardClaim::query()->where('ahjiyat_pass_id', $pass->getKey())->selectRaw('track, COUNT(*) AS n')->groupBy('track')->pluck('n', 'track')->map(fn ($n) => (int) $n)->all();
        $pointsGranted = (int) PassPointGrant::query()->where('ahjiyat_pass_id', $pass->getKey())->sum('points');

        $missions = PassPointGrant::query()->where('ahjiyat_pass_id', $pass->getKey())->whereNotNull('ahjiyat_pass_mission_id')
            ->selectRaw('ahjiyat_pass_mission_id, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users')->groupBy('ahjiyat_pass_mission_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->ahjiyat_pass_mission_id => ['completions' => (int) $r->n, 'players' => (int) $r->users]])->all();

        return [
            'players' => $players,
            'active_7d' => $active7,
            'premium_owners' => $premium,
            'conversion' => $players > 0 ? round($premium / $players * 100, 1) : 0.0,
            'avg_tier' => round($avgTier, 1),
            'at_max_tier' => $atMax,
            'completion_rate' => $players > 0 ? round($atMax / $players * 100, 1) : 0.0,
            'distribution' => $distribution,
            'funnel' => $funnel,
            'refunded' => $refunded,
            'revenue' => $revenue,
            'claims' => ['free' => (int) ($claims['free'] ?? 0), 'premium' => (int) ($claims['premium'] ?? 0)],
            'points_granted' => $pointsGranted,
            'missions' => $missions,
        ];
    }
}
