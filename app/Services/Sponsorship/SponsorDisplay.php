<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SponsorshipProgram;
use App\Models\SponsorshipProgramRole;
use App\Models\SupporterProfile;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;

/**
 * E30 - عرض الرعاة على صفحات الموسم/الحملة/المنافسة (و«المنصة»): بيانات **منظَّمة** (اسم، شعار، رابط آمن، نوع) لا HTML حرًّا، ولا علاقة لها بمحرّك إعلانات E14.
 *
 * يظهر راعٍ فقط إذا: دوره sponsor/main_sponsor ∧ برنامجه active/closed ∧ له مساهمة مؤكَّدة **غير مجهولة** بالبرنامج (راعٍ كل مساهماته مجهولة لا يُنسب علنًا) ∧ ملفه علني الآن.
 * الكاش يحمل معرّفات وأدوارًا فقط؛ العلنية تُفحص حيًّا. أي فشل هنا يُلتقط ويُعاد «لا رعاة» (الصفحات الرسمية لا تتعطل بالرعاية).
 */
class SponsorDisplay
{
    public function __construct(protected SupporterLogoService $logos) {}

    /**
     * @return array{main: ?array<string, mixed>, supporting: list<array<string, mixed>>, any: bool}
     */
    public function forTarget(string $type, ?int $id = null): array
    {
        $empty = ['main' => null, 'supporting' => [], 'any' => false];

        if (! in_array($type, SponsorshipProgram::TARGETS, true) || ! (bool) config('sponsorship.enabled', true)) {
            return $empty;
        }

        try {
            $limit = 1 + (int) config('sponsorship.public.supporting_sponsors_limit', 8);
            $roles = SponsorshipCache::remember('display', [$type, $id ?? 0], (int) config('sponsorship.public.display_ttl', 60), fn () => $this->roles($type, $id, $limit));

            if ($roles === []) {
                return $empty;
            }

            $profiles = SupporterProfile::query()->public()->whereIn('id', array_column($roles, 'sid'))
                ->get(['id', 'type', 'display_name', 'slug', 'logo_path', 'website_url'])->keyBy('id');

            $main = null;
            $supporting = [];

            foreach ($roles as $row) {
                $profile = $profiles->get($row['sid']);

                if ($profile === null) {
                    continue;
                }

                $card = $this->card($profile, $row['role'] === SponsorshipProgramRole::ROLE_MAIN_SPONSOR);

                if ($card['is_main'] && $main === null) {
                    $main = $card;
                } elseif (count($supporting) < $limit - 1) {
                    $supporting[] = $card;
                }
            }

            return ['main' => $main, 'supporting' => $supporting, 'any' => $main !== null || $supporting !== []];
        } catch (\Throwable $e) {
            report($e);

            return $empty;
        }
    }

    /** @return list<array{sid: int, role: string}> */
    protected function roles(string $type, ?int $id, int $limit): array
    {
        return DB::table('sponsorship_program_roles as r')
            ->join('sponsorship_programs as p', 'p.id', '=', 'r.sponsorship_program_id')
            ->where('p.target_type', $type)
            ->when($type !== SponsorshipProgram::TARGET_PLATFORM, fn ($q) => $q->where('p.target_id', $id))
            ->whereIn('p.status', [SponsorshipProgram::STATUS_ACTIVE, SponsorshipProgram::STATUS_CLOSED])
            ->whereIn('r.role', [SponsorshipProgramRole::ROLE_MAIN_SPONSOR, SponsorshipProgramRole::ROLE_SPONSOR])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sponsorship_contributions as c')
                ->whereColumn('c.supporter_profile_id', 'r.supporter_profile_id')->whereColumn('c.sponsorship_program_id', 'r.sponsorship_program_id')
                ->where('c.status', SponsorshipContribution::STATUS_CONFIRMED)->where('c.is_anonymous', false))
            ->orderByRaw("case when r.role = 'main_sponsor' then 0 else 1 end")->orderBy('r.id')
            ->limit($limit)->get(['r.supporter_profile_id as sid', 'r.role'])
            ->map(fn ($r) => ['sid' => (int) $r->sid, 'role' => (string) $r->role])->all();
    }

    /** @return array<string, mixed> */
    public function card(SupporterProfile $profile, bool $main = false): array
    {
        return [
            'name' => $profile->display_name,
            'slug' => $profile->slug,
            'type' => $profile->type,
            'logo_url' => $this->logos->url($profile->logo_path),
            'website' => SafeUrl::normalize($profile->website_url),
            'is_main' => $main,
        ];
    }
}
