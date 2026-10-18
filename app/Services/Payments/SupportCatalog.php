<?php

namespace App\Services\Payments;

use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Services\Sponsorship\SponsorshipRewardService;
use App\Services\Sponsorship\SupportTotals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E32 - نموذج القراءة العام لصفحات الدعم (/support): البرامج النشطة الآن فقط + مجاميع التمويل المؤكَّد + التعزيز الحالي + الراعي الرئيسي، بعدد استعلامات ثابت (لا N+1 بعدد البرامج) وبسقف صفوف.
 * كله من قاعدة البيانات مباشرة (لا كاش) وكله مؤكَّد فقط: الطلبات المعلّقة/المرسَلة لا تظهر في أي رقم. الراعي المجهول لا يظهر أبدًا.
 */
class SupportCatalog
{
    public const LIMIT = 24;

    public function __construct(protected SupportTotals $totals) {}

    /** @return Collection<int, SponsorshipProgram> */
    public function programs(): Collection
    {
        return SponsorshipProgram::query()->activeNow()->orderByDesc('activated_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'description', 'target_type', 'target_id', 'status', 'starts_at', 'ends_at', 'goal_amount_minor', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'reward_boost_enabled', 'funding_step_minor', 'boost_per_step_bps', 'max_boost_bps']);
    }

    /**
     * @param  Collection<int, SponsorshipProgram>  $programs
     * @return array<int, array{funding: int, progress: ?int, boost_bps: int, main: ?array{name: string, slug: string}}>
     */
    public function cards(Collection $programs): array
    {
        $ids = $programs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $funding = $this->totals->programFundingMany($ids);
        $mains = $this->mainSponsors($ids);
        $out = [];

        foreach ($programs as $program) {
            $id = (int) $program->id;
            $total = $funding[$id] ?? 0;

            $out[$id] = [
                'funding' => $total,
                'progress' => $program->show_goal_progress && (int) $program->goal_amount_minor > 0 ? (int) min(100, intdiv($total * 100, (int) $program->goal_amount_minor)) : null,
                'boost_bps' => $program->reward_boost_enabled
                    ? SponsorshipRewardService::computeBps($total, (int) $program->funding_step_minor, (int) $program->boost_per_step_bps, (int) $program->max_boost_bps)
                    : 0,
                'main' => $mains[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * الراعي الرئيسي العلني لكل برنامج (استعلام واحد): ملف علني ونشط له مساهمة مؤكَّدة **غير مجهولة** بالبرنامج نفسه.
     *
     * @param  list<int>  $programIds
     * @return array<int, array{name: string, slug: string}>
     */
    protected function mainSponsors(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $rows = DB::table('sponsorship_program_roles as r')
            ->join('supporter_profiles as sp', 'sp.id', '=', 'r.supporter_profile_id')
            ->whereIn('r.sponsorship_program_id', $programIds)->where('r.role', SponsorshipProgramRole::ROLE_MAIN_SPONSOR)
            ->where('sp.status', 'active')->where('sp.is_public', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sponsorship_contributions as c')
                ->whereColumn('c.supporter_profile_id', 'r.supporter_profile_id')->whereColumn('c.sponsorship_program_id', 'r.sponsorship_program_id')
                ->where('c.status', 'confirmed')->where('c.is_anonymous', false))
            ->orderBy('r.id')->limit(self::LIMIT)
            ->get(['r.sponsorship_program_id as pid', 'sp.display_name as name', 'sp.slug as slug']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->pid] ??= ['name' => (string) $row->name, 'slug' => (string) $row->slug];
        }

        return $out;
    }
}
