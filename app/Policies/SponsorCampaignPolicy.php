<?php

namespace App\Policies;

use App\Models\SponsorCampaign;
use App\Models\User;

/** E14: نفس نمط BasePermissionPolicy مع صلاحيتين إضافيتين مستقلتين تمامًا (بند 596/647). */
class SponsorCampaignPolicy extends BasePermissionPolicy
{
    protected string $prefix = 'ads.campaigns';

    public function review(User $user, SponsorCampaign $campaign): bool
    {
        return $user->can('ads.campaigns.review');
    }

    public function pause(User $user, SponsorCampaign $campaign): bool
    {
        return $user->can('ads.campaigns.pause');
    }
}
