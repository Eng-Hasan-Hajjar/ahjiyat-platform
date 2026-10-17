<?php

namespace App\Http\Controllers\Sponsorship;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorDisplay;
use App\Services\Sponsorship\SponsorshipTargets;
use App\Services\Sponsorship\SupportTotals;
use App\Services\Sponsorship\SupporterLeaderboardService;
use App\Services\Sponsorship\SupporterTierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * E30 - صفحات الداعمين العامة (قراءة فقط). الظهور: ملف علني ونشط (غيره 404). لا مبالغ دقيقة إلا لبرنامج فعّل show_public_amounts؛ لا ملاحظات ولا مراجع ولا تفاصيل دفع؛
 * المجهولون «داعم مجهول» بلا ملف ولا رابط. كل القوائم بسقوف ثابتة، واللوحات من تجميع قاعدة البيانات (الكاش معرّفات فقط، والعلنية تُفحص حيًّا).
 */
class SupporterController extends Controller
{
    public function __construct(
        protected SupporterLeaderboardService $board,
        protected SupporterTierService $tiers,
        protected SponsorDisplay $display,
        protected SupportTotals $totals,
        protected SponsorshipTargets $targets,
    ) {}

    public function index(Request $request)
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $scope = in_array($request->query('scope'), SupporterLeaderboardService::SCOPES, true) ? (string) $request->query('scope') : SupporterLeaderboardService::SCOPE_ALL;
        $slug = $request->query('program');
        $program = null;

        if ($scope === SupporterLeaderboardService::SCOPE_PROGRAM) {
            $program = is_string($slug) ? SponsorshipProgram::query()->where('slug', $slug)->whereIn('status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
                ->first(['id', 'slug', 'title', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'goal_amount_minor', 'target_type', 'target_id']) : null;
            $scope = $program === null ? SupporterLeaderboardService::SCOPE_ALL : $scope;
        }

        $programs = SponsorshipProgram::query()->whereIn('status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->orderByDesc('activated_at')->orderByDesc('id')->limit(8)
            ->get(['id', 'slug', 'title', 'status', 'accounting_currency', 'show_public_amounts', 'show_goal_progress', 'goal_amount_minor', 'target_type', 'target_id']);

        $funding = $this->totals->programFundingMany($programs->pluck('id')->all());

        return view('supporters.index', [
            'scope' => $scope,
            'program' => $program,
            'leaderboard' => $this->board->top($scope, $program),
            'recent' => $this->board->recent(),
            'organizations' => $this->board->organizations(),
            'programs' => $programs,
            'funding' => $funding,
            'tiers' => $this->tiers->tiers(),
            'platformSponsors' => $this->display->forTarget(SponsorshipProgram::TARGET_PLATFORM),
            'targets' => $this->targets,
        ]);
    }

    public function show(SupporterProfile $supporter)
    {
        abort_unless($supporter->isPublic() && (bool) config('sponsorship.enabled', true), 404);

        // البرامج التي دعمها علنًا (مساهمات مؤكَّدة غير مجهولة فقط) مع المبلغ إن فعّل البرنامج ذلك. سقف 10.
        $programs = DB::table('sponsorship_contributions as c')
            ->join('sponsorship_programs as p', 'p.id', '=', 'c.sponsorship_program_id')
            ->where('c.supporter_profile_id', $supporter->id)->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false)
            ->whereIn('p.status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->groupBy('p.id', 'p.slug', 'p.title', 'p.show_public_amounts', 'p.accounting_currency')
            ->selectRaw('p.id, p.slug, p.title, p.show_public_amounts as show_amounts, p.accounting_currency as cur, SUM(c.accounting_amount_minor) as total, MIN(c.confirmed_at) as since')
            ->orderBy('since')->orderBy('p.id')->limit(10)->get();

        $since = $programs->min('since');

        return view('supporters.show', [
            'supporter' => $supporter,
            'tier' => $this->tiers->forSupporter($supporter),
            'programs' => $programs,
            'since' => $since,
            'card' => $this->display->card($supporter),
        ]);
    }
}
