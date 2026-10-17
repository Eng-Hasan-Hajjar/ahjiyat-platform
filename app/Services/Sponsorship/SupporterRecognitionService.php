<?php

namespace App\Services\Sponsorship;

use App\Models\SponsorshipContribution;
use App\Models\SupporterProfile;
use App\Models\User;

/**
 * E30 - تقدير الداعم بجانب هوية اللاعب (شارة «داعم · المستوى» على ملفه العام). قرار تصميم: **شارة مخصصة مشتقّة** وليست عنصرًا من متجر الهوية/Loadout (لا تلمس نظام التجهيز ولا يمكن شراؤها).
 * تُعرض فقط إذا: ملف الداعم المرتبط بالحساب علني ونشط ∧ له مستوى ∧ له دعم مؤكَّد **غير مجهول** واحد على الأقل (من كل دعمه مجهول لا تُنسب له شارة علنًا).
 * المستوى مشتقّ من صافي المؤكَّد (يتبع الاسترداد فورًا). استعلامان صغيران فقط (الملف ثم مجمَّع واحد)؛ لا بيانات مالية تُعرض.
 */
class SupporterRecognitionService
{
    public function __construct(protected SupporterTierService $tiers) {}

    /** @return array{name: string, slug: string, tier: string, badge_key: string}|null */
    public function forUser(User $user): ?array
    {
        if (! (bool) config('sponsorship.enabled', true)) {
            return null;
        }

        $profile = SupporterProfile::query()->public()->where('user_id', $user->id)->first(['id', 'display_name', 'slug']);

        if ($profile === null) {
            return null;
        }

        $agg = SponsorshipContribution::query()->where('status', SponsorshipContribution::STATUS_CONFIRMED)->where('supporter_profile_id', $profile->id)
            ->selectRaw('COALESCE(SUM(accounting_amount_minor), 0) as total, COALESCE(SUM(CASE WHEN is_anonymous = 0 THEN 1 ELSE 0 END), 0) as named')->first();

        if ((int) ($agg->named ?? 0) <= 0) {
            return null;
        }

        $tier = $this->tiers->forAmount((int) ($agg->total ?? 0));

        return $tier === null ? null : ['name' => $profile->display_name, 'slug' => $profile->slug, 'tier' => $tier->name, 'badge_key' => $tier->badge_key];
    }
}
