<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use Illuminate\Support\Facades\DB;

/**
 * E30 - تحليلات الرعاية الإدارية: أرقام مجمَّعة بتجميعات قاعدة بيانات (لا تحميل صفوف)، مخزَّنة مدة قصيرة مع إبطال تلقائي بأي تغيير مالي (SponsorshipCache::bust).
 * الكاش للعرض فقط (ليس سلطة): التمويل/المستويات/التعزيز تقرأ دائمًا من قاعدة البيانات. لا هويات لاعبين هنا ولا بيانات دفع.
 */
class SponsorshipAnalytics
{
    /**
     * @return array{confirmed_minor: int, refunded_minor: int, confirmed_count: int, pending_count: int, refunded_count: int, cancelled_count: int, supporters: int, active_programs: int, boost_programs: int, locked_plans: int, bonus_total: int, bonus_count: int, top_program: ?array{title: string, funding_minor: int}, currency: string}
     */
    public function summary(): array
    {
        return SponsorshipCache::remember('analytics', [], (int) config('sponsorship.analytics.cache_ttl', 60), function () {
            $by = DB::table('sponsorship_contributions')->groupBy('status')
                ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(accounting_amount_minor), 0) as total')->get()->keyBy('status');

            $top = DB::table('sponsorship_contributions as c')->join('sponsorship_programs as p', 'p.id', '=', 'c.sponsorship_program_id')
                ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->groupBy('p.id', 'p.title')
                ->selectRaw('p.title as title, SUM(c.accounting_amount_minor) as funding')->orderByDesc('funding')->orderBy('p.id')->first();

            $bonus = DB::table('sponsorship_reward_bonuses')->selectRaw('COALESCE(SUM(bonus_amount), 0) as total, COUNT(*) as n')->first();

            return [
                'confirmed_minor' => (int) ($by->get(SponsorshipContribution::STATUS_CONFIRMED)->total ?? 0),
                'refunded_minor' => (int) ($by->get(SponsorshipContribution::STATUS_REFUNDED)->total ?? 0),
                'confirmed_count' => (int) ($by->get(SponsorshipContribution::STATUS_CONFIRMED)->n ?? 0),
                'pending_count' => (int) ($by->get(SponsorshipContribution::STATUS_PENDING)->n ?? 0),
                'refunded_count' => (int) ($by->get(SponsorshipContribution::STATUS_REFUNDED)->n ?? 0),
                'cancelled_count' => (int) ($by->get(SponsorshipContribution::STATUS_CANCELLED)->n ?? 0),
                'supporters' => SupporterProfile::query()->where('status', SupporterProfile::STATUS_ACTIVE)->count(),
                'active_programs' => SponsorshipProgram::query()->where('status', SponsorshipProgram::STATUS_ACTIVE)->count(),
                'boost_programs' => SponsorshipProgram::query()->where('status', SponsorshipProgram::STATUS_ACTIVE)->where('reward_boost_enabled', true)->count(),
                'locked_plans' => (int) DB::table('sponsorship_event_reward_plans')->count(),
                'bonus_total' => (int) ($bonus->total ?? 0),
                'bonus_count' => (int) ($bonus->n ?? 0),
                'top_program' => $top ? ['title' => (string) $top->title, 'funding_minor' => (int) $top->funding] : null,
                'currency' => SponsorshipMoney::accountingCurrency(),
            ];
        });
    }
}
