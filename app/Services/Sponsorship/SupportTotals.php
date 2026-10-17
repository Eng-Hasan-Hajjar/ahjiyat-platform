<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use Illuminate\Support\Facades\DB;

/**
 * E30 - المجاميع المالية الصافية: SUM(المعادل المحاسبي) للمساهمات **المؤكَّدة فقط** (المستردة/الملغاة/المعلّقة لا تُحتسب). تقرأ قاعدة البيانات مباشرة دائمًا
 * (سلطة التمويل/المستوى/التعزيز)، وتستعمل فهارس sponsorship_contrib_*؛ لا كاش هنا.
 */
class SupportTotals
{
    public function net(int $supporterId): int
    {
        return (int) SponsorshipContribution::query()
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('supporter_profile_id', $supporterId)
            ->sum('accounting_amount_minor');
    }

    /** @param list<int> $supporterIds @return array<int, int> */
    public function netMany(array $supporterIds): array
    {
        if ($supporterIds === []) {
            return [];
        }

        return DB::table('sponsorship_contributions')
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->whereIn('supporter_profile_id', $supporterIds)
            ->groupBy('supporter_profile_id')
            ->selectRaw('supporter_profile_id as id, SUM(accounting_amount_minor) as total')
            ->pluck('total', 'id')->map(fn ($v) => (int) $v)->all();
    }

    public function programFunding(int $programId): int
    {
        return (int) SponsorshipContribution::query()
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('sponsorship_program_id', $programId)
            ->sum('accounting_amount_minor');
    }

    /** @param list<int> $programIds @return array<int, int> */
    public function programFundingMany(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        return DB::table('sponsorship_contributions')
            ->where('status', SponsorshipContribution::STATUS_CONFIRMED)->whereIn('sponsorship_program_id', $programIds)
            ->groupBy('sponsorship_program_id')
            ->selectRaw('sponsorship_program_id as id, SUM(accounting_amount_minor) as total')
            ->pluck('total', 'id')->map(fn ($v) => (int) $v)->all();
    }

    public function confirmedCount(int $programId): int
    {
        return SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('sponsorship_program_id', $programId)->count();
    }

    /** هل للداعم مساهمة مؤكَّدة بهذا البرنامج (شرط الأدوار). */
    public function hasConfirmed(int $supporterId, int $programId): bool
    {
        return SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)
            ->where('supporter_profile_id', $supporterId)->where('sponsorship_program_id', $programId)->exists();
    }
}
