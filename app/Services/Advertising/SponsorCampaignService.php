<?php

namespace App\Services\Advertising;

use App\Exceptions\AdInvariantViolation;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * إدارة حالة ومراجعة الحملة - منفصلة عن AdServingService. نموذج حالة واحد
 * (status وحدها)، وحارس إعادة المراجعة على مستوى النطاق (Domain).
 */
class SponsorCampaignService
{
    /** حقول حساسة بالحملة - تغييرها على حملة مُعتمَدة يعيدها لمراجعة معلَّقة. */
    protected const SENSITIVE_CAMPAIGN_FIELDS = ['sponsor_name'];

    /** حقول حساسة بالمادة المعروضة - نفس الأثر. */
    protected const SENSITIVE_CREATIVE_FIELDS = ['title', 'body', 'cta_label', 'destination_url', 'image_path', 'alt_text'];

    public function __construct(protected OperationalAuditService $audit) {}

    public function createDraft(array $data, User $creator): SponsorCampaign
    {
        return SponsorCampaign::create([
            ...$data,
            'status' => SponsorCampaign::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    public function submitForReview(SponsorCampaign $campaign): void
    {
        $this->assertTransition($campaign, [SponsorCampaign::STATUS_DRAFT, SponsorCampaign::STATUS_REJECTED], SponsorCampaign::STATUS_PENDING_REVIEW);

        if ($campaign->activeCreatives()->doesntExist()) {
            throw new AdInvariantViolation('لا يمكن إرسال حملة للمراجعة بلا مادة إعلانية واحدة نشطة على الأقل.');
        }

        $campaign->update(['status' => SponsorCampaign::STATUS_PENDING_REVIEW]);
    }

    /** تحقُّق خادم-جانبي صريح - لا حماية Filament-only. */
    public function approve(SponsorCampaign $campaign, User $reviewer): void
    {
        $this->assertTransition($campaign, [SponsorCampaign::STATUS_PENDING_REVIEW], SponsorCampaign::STATUS_APPROVED);

        foreach ($campaign->creatives as $creative) {
            UrlSafetyGuard::assertSafe($creative->destination_url);
        }

        $campaign->update([
            'status' => SponsorCampaign::STATUS_APPROVED,
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
            'review_note' => null,
        ]);

        $this->audit->log('ads.campaign.approved', $campaign, ['reviewer_id' => $reviewer->id], $reviewer);
    }

    public function reject(SponsorCampaign $campaign, User $reviewer, ?string $note = null): void
    {
        $this->assertTransition($campaign, [SponsorCampaign::STATUS_PENDING_REVIEW], SponsorCampaign::STATUS_REJECTED);

        $campaign->update(['status' => SponsorCampaign::STATUS_REJECTED, 'review_note' => $note]);

        $this->audit->log('ads.campaign.rejected', $campaign, ['reviewer_id' => $reviewer->id, 'note' => $note], $reviewer);
    }

    /** عملياتي بحت - لا يمسّ المحتوى، فلا إعادة مراجعة. */
    public function pause(SponsorCampaign $campaign, User $actor): void
    {
        $this->assertTransition($campaign, [SponsorCampaign::STATUS_APPROVED], SponsorCampaign::STATUS_PAUSED);

        $campaign->update(['status' => SponsorCampaign::STATUS_PAUSED]);

        $this->audit->log('ads.campaign.paused', $campaign, [], $actor);
    }

    public function resume(SponsorCampaign $campaign, User $actor): void
    {
        $this->assertTransition($campaign, [SponsorCampaign::STATUS_PAUSED], SponsorCampaign::STATUS_APPROVED);

        $campaign->update(['status' => SponsorCampaign::STATUS_APPROVED]);

        $this->audit->log('ads.campaign.resumed', $campaign, [], $actor);
    }

    /** تغيير جوهري بالمحتوى المُعتمَد يعيد الحالة لمراجعة معلَّقة تلقائيًا من طبقة Domain. */
    public function updateCampaignMeta(SponsorCampaign $campaign, array $data, User $actor): void
    {
        DB::transaction(function () use ($campaign, $data, $actor) {
            $wasApproved = $campaign->status === SponsorCampaign::STATUS_APPROVED;
            $campaign->fill($data);
            $sensitiveChanged = collect(self::SENSITIVE_CAMPAIGN_FIELDS)->some(fn ($f) => $campaign->isDirty($f));

            if ($wasApproved && $sensitiveChanged) {
                $campaign->status = SponsorCampaign::STATUS_PENDING_REVIEW;
                $campaign->approved_by = null;
                $campaign->approved_at = null;
            }

            $campaign->save();

            if ($wasApproved && $sensitiveChanged) {
                $this->audit->log('ads.campaign.sensitive_edit_reset_review', $campaign, [], $actor);
            }
        });
    }

    public function updateCreative(SponsorCreative $creative, array $data, User $actor): void
    {
        if (array_key_exists('destination_url', $data)) {
            UrlSafetyGuard::assertSafe($data['destination_url']);
        }

        DB::transaction(function () use ($creative, $data, $actor) {
            $campaign = $creative->campaign;
            $wasApproved = $campaign->status === SponsorCampaign::STATUS_APPROVED;

            $creative->fill($data);
            $sensitiveChanged = collect(self::SENSITIVE_CREATIVE_FIELDS)->some(fn ($f) => $creative->isDirty($f));
            $creative->save();

            if ($wasApproved && $sensitiveChanged) {
                $campaign->update([
                    'status' => SponsorCampaign::STATUS_PENDING_REVIEW,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
                $this->audit->log('ads.campaign.sensitive_edit_reset_review', $campaign, ['creative_id' => $creative->id], $actor);
            }
        });
    }

    protected function assertTransition(SponsorCampaign $campaign, array $allowedFrom, string $to): void
    {
        if (! in_array($campaign->status, $allowedFrom, true)) {
            throw new AdInvariantViolation("لا يمكن الانتقال من حالة '{$campaign->status}' إلى '{$to}'.");
        }
    }
}