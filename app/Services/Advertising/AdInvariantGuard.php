<?php

namespace App\Services\Advertising;

use App\Exceptions\AdInvariantViolation;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Models\SponsorCreative;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\Log;

/**
 * E14.1: ثوابت نطاق الإعلانات على مستوى النموذج (Eloquent events) - فلا يمكن
 * تجاوزها بـFilament ولا Tinker ولا Seeder ولا Controller مستقبلي. نفس نمط
 * QuestDefinitionInvariantGuard/StoreItemInvariantGuard بالمشروع (يُربَط من
 * booted() بكل نموذج).
 *
 * سلالة الاعتماد (approved + paused): paused ليست مسودة، هي حملة سبق اعتمادها
 * ويمكن استئنافها - فأي تغيير بمحتواها يُبطِل الاعتماد السابق كالمعتمدة تمامًا.
 * الإبطال يتم قبل الحفظ (creating/updating/deleting) فاتجاه الفشل آمن دائمًا:
 * لو فشل الحفظ بعدها فالنتيجة حملة تحتاج مراجعة، لا مادة غير مُراجَعة معروضة.
 */
class AdInvariantGuard
{
    public const SENSITIVE_CREATIVE_FIELDS = ['title', 'body', 'cta_label', 'destination_url', 'image_path', 'alt_text'];

    public const SENSITIVE_CAMPAIGN_FIELDS = ['sponsor_name'];

    public static function isReviewedLineage(?string $status): bool
    {
        return in_array($status, [SponsorCampaign::STATUS_APPROVED, SponsorCampaign::STATUS_PAUSED], true);
    }

    // ===== SponsorCreative =====

    public static function creativeCreating(SponsorCreative $creative): void
    {
        UrlSafetyGuard::assertSafe($creative->destination_url);

        self::invalidateReview($creative->sponsor_campaign_id, 'creative_created');
    }

    public static function creativeUpdating(SponsorCreative $creative): void
    {
        if ($creative->isDirty('destination_url')) {
            UrlSafetyGuard::assertSafe($creative->destination_url);
        }

        if ($creative->isDirty('sponsor_campaign_id')) {
            self::invalidateReview($creative->getOriginal('sponsor_campaign_id'), 'creative_moved_out');
            self::invalidateReview($creative->sponsor_campaign_id, 'creative_moved_in');

            return;
        }

        if (array_intersect(self::SENSITIVE_CREATIVE_FIELDS, array_keys($creative->getDirty())) !== []) {
            self::invalidateReview($creative->sponsor_campaign_id, 'creative_content_changed');
        }
    }

    public static function creativeDeleting(SponsorCreative $creative): void
    {
        if ($creative->hasHistory()) {
            throw new AdInvariantViolation('لا يمكن حذف مادة إعلانية لها سجل تحليلات - عطِّلها (غير نشطة) بدل الحذف.');
        }

        // حذف مادة من حملة معتمَدة/مُوقَفة = تغيير بالمحتوى المُراجَع.
        self::invalidateReview($creative->sponsor_campaign_id, 'creative_deleted');
    }

    // ===== SponsorCampaign =====

    public static function campaignUpdating(SponsorCampaign $campaign): void
    {
        if (! self::isReviewedLineage($campaign->getOriginal('status'))) {
            return;
        }

        if (array_intersect(self::SENSITIVE_CAMPAIGN_FIELDS, array_keys($campaign->getDirty())) === []) {
            return;
        }

        $previous = $campaign->getOriginal('status');

        $campaign->status = SponsorCampaign::STATUS_PENDING_REVIEW;
        $campaign->approved_by = null;
        $campaign->approved_at = null;

        self::audit($campaign, 'sponsor_identity_changed', $previous);
    }

    public static function campaignDeleting(SponsorCampaign $campaign): void
    {
        if ($campaign->hasHistory()) {
            throw new AdInvariantViolation('لا يمكن حذف حملة لها سجل تحليلات - أوقفها بدل الحذف.');
        }

        if (! in_array($campaign->status, [SponsorCampaign::STATUS_DRAFT, SponsorCampaign::STATUS_REJECTED], true)) {
            throw new AdInvariantViolation('لا تُحذف إلا حملة بحالة مسودة أو مرفوضة وبلا سجل تحليلات.');
        }
    }

    // ===== AdPlacement =====

    public static function placementDeleting(AdPlacement $placement): void
    {
        if ($placement->hasHistory() || $placement->campaigns()->exists()) {
            throw new AdInvariantViolation('لا يمكن حذف موضع مُستخدَم أو له سجل تحليلات - عطِّله فقط.');
        }
    }

    // ===== داخلي =====

    protected static function invalidateReview(?int $campaignId, string $reason): void
    {
        if ($campaignId === null) {
            return;
        }

        $campaign = SponsorCampaign::query()->find($campaignId);

        if ($campaign === null || ! self::isReviewedLineage($campaign->status)) {
            return;
        }

        $previous = $campaign->status;

        $campaign->forceFill([
            'status' => SponsorCampaign::STATUS_PENDING_REVIEW,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        self::audit($campaign, $reason, $previous);
    }

    protected static function audit(SponsorCampaign $campaign, string $reason, ?string $previousStatus): void
    {
        try {
            app(OperationalAuditService::class)->log(
                'ads.campaign.sensitive_edit_reset_review',
                $campaign,
                ['reason' => $reason, 'previous_status' => $previousStatus],
            );
        } catch (\Throwable $e) {
            // فشل التدقيق لا يمنع إبطال الاعتماد (الأمان أولًا).
            Log::error('فشل تسجيل تدقيق إبطال اعتماد حملة', ['error' => $e->getMessage()]);
        }
    }
}
