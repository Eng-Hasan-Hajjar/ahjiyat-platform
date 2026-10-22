<?php

namespace App\Services\Reputation;

use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E37 - تحليلات السمعة للإدارة (reputation.view): أرقام **مجمَّعة** مخزَّنة مؤقتًا (admin.analytics_cache_seconds). لا هويات ولا أسماء ولا درجات فردية:
 * أعداد اللقطات والمؤهَّلين لكل مسار، توزيع جودة المبدعين المؤهَّلين بشرائح، اللقطات القديمة/بصيغة سابقة، المبدعون المختارون، الاعتراف المفتوح لكل تعريف، ومستخدمون بلا لقطة (بسقف).
 */
class ReputationAnalytics
{
    public const MISSING_CAP = 500;

    public function __construct(protected ReputationSettings $settings, protected CommunityReputationService $service) {}

    /** @return array<string, mixed> */
    public function admin(): array
    {
        return CachedSnapshot::remember('reputation.analytics.admin', $this->settings->int('admin.analytics_cache_seconds', 300), fn () => $this->compute());
    }

    /** @return array<string, mixed> */
    public function compute(): array
    {
        $cutoff = now()->subMinutes(max(1, $this->settings->int('refresh.max_age_minutes', 180)))->toDateTimeString();
        $scale = $this->settings->scale();

        $totals = CommunityReputationSnapshot::query()->selectRaw(
            'COUNT(*) as snapshots, '
            .'COALESCE(SUM(creator_eligible), 0) as creators, COALESCE(SUM(rising_eligible), 0) as rising, COALESCE(SUM(curator_eligible), 0) as curators, COALESCE(SUM(helpful_eligible), 0) as helpful, '
            .'COALESCE(SUM(CASE WHEN calculated_at IS NULL OR calculated_at < ? THEN 1 ELSE 0 END), 0) as stale, '
            .'COALESCE(SUM(CASE WHEN formula_version < ? THEN 1 ELSE 0 END), 0) as outdated, MAX(calculated_at) as last_calculated',
            [$cutoff, $this->settings->formulaVersion()]
        )->first();

        // توزيع جودة المبدعين المؤهَّلين: أربع شرائح ربعية من السلّم.
        $buckets = ['0-25%' => 0, '25-50%' => 0, '50-75%' => 0, '75-100%' => 0];

        foreach (CommunityReputationSnapshot::query()->where('creator_eligible', true)->pluck('creator_score') as $score) {
            $p = ((int) $score) / $scale;
            $buckets[$p < 0.25 ? '0-25%' : ($p < 0.5 ? '25-50%' : ($p < 0.75 ? '50-75%' : '75-100%'))]++;
        }

        $recognition = [];
        $keys = array_keys((array) config('reputation.recognition', []));

        if ($keys !== []) {
            foreach (DB::table('achievements as a')->leftJoin('user_achievement_progress as p', fn ($j) => $j->on('p.achievement_id', '=', 'a.id')->whereNotNull('p.unlocked_at'))
                ->whereIn('a.internal_key', $keys)->groupBy('a.internal_key', 'a.name')
                ->selectRaw('a.internal_key as k, a.name as n, COUNT(p.id) as c')->get() as $row) {
                $recognition[(string) $row->k] = ['name' => (string) $row->n, 'unlocked' => (int) $row->c];
            }
        }

        $missing = count($this->service->discoverMissing(self::MISSING_CAP));

        return [
            'generated_at' => now()->toDateTimeString(),
            'snapshots' => (int) ($totals->snapshots ?? 0),
            'eligible' => ['creators' => (int) $totals->creators, 'rising' => (int) $totals->rising, 'curators' => (int) $totals->curators, 'helpful' => (int) $totals->helpful],
            'stale' => (int) $totals->stale,
            'outdated_formula' => (int) $totals->outdated,
            'last_calculated' => $totals->last_calculated,
            'missing_subjects' => $missing,
            'missing_capped' => $missing >= self::MISSING_CAP,
            'creator_quality_distribution' => $buckets,
            'featured_active' => CommunityFeaturedCreator::query()->activeNow()->count(),
            'featured_max' => $this->settings->int('featured.max_active', 12),
            'recognition' => $recognition,
            'enabled' => $this->settings->enabled(),
            'formula_version' => $this->settings->formulaVersion(),
        ];
    }
}
