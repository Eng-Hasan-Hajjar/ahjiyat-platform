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
 * (status وحدها). إبطال الاعتماد عند تغيير المحتوى يعيش بالنموذج نفسه
 * (AdInvariantGuard) فلا يتجاوزه أي مسار؛ الخدمة هنا للانتقالات والتدقيق
 * وإعادة فحص الثوابت من قاعدة البيانات لحظة التنفيذ (لا من حالة نموذج قديمة).
 *
 * قرار Resume (موثَّق): يُعيد فحص نفس ثوابت الاعتماد (موضع + مادة نشطة + روابط
 * آمنة + جدولة صحيحة) لكنه لا يشترط أن تكون الحملة داخل نافذتها الزمنية الآن؛
 * الجدولة المستقبلية مسموحة، والعرض الفعلي يبقى مشروطًا بـnow() وقت العرض.
 */
class SponsorCampaignService
{
    public function __construct(protected OperationalAuditService $audit) {}

    public function createDraft(array $data, User $creator): SponsorCampaign
    {
        $campaign = SponsorCampaign::create([
            ...$data,
            'status' => SponsorCampaign::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        $this->audit->log('ads.campaign.created', $campaign, [], $creator);

        return $campaign;
    }

    public function submitForReview(SponsorCampaign $campaign, ?User $actor = null): void
    {
        $campaign->refresh();

        $this->assertTransition($campaign, [SponsorCampaign::STATUS_DRAFT, SponsorCampaign::STATUS_REJECTED], SponsorCampaign::STATUS_PENDING_REVIEW);
        $this->assertApprovable($campaign);

        $campaign->update(['status' => SponsorCampaign::STATUS_PENDING_REVIEW]);

        $this->audit->log('ads.campaign.submitted', $campaign, [], $actor);
    }

    /** إعادة فحص كاملة من DB لحظة الاعتماد - لا اعتماد على حالة نموذج/نموذج واجهة قديمة. */
    public function approve(SponsorCampaign $campaign, User $reviewer): void
    {
        DB::transaction(function () use ($campaign, $reviewer) {
            $locked = SponsorCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            $this->assertTransition($locked, [SponsorCampaign::STATUS_PENDING_REVIEW], SponsorCampaign::STATUS_APPROVED);
            $this->assertApprovable($locked);

            $locked->update([
                'status' => SponsorCampaign::STATUS_APPROVED,
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'review_note' => null,
            ]);

            $this->audit->log('ads.campaign.approved', $locked, ['reviewer_id' => $reviewer->id], $reviewer);
        });

        $campaign->refresh();
    }

    public function reject(SponsorCampaign $campaign, User $reviewer, ?string $note = null): void
    {
        $campaign->refresh();

        $this->assertTransition($campaign, [SponsorCampaign::STATUS_PENDING_REVIEW], SponsorCampaign::STATUS_REJECTED);

        $campaign->update(['status' => SponsorCampaign::STATUS_REJECTED, 'review_note' => $note]);

        $this->audit->log('ads.campaign.rejected', $campaign, ['reviewer_id' => $reviewer->id, 'note' => $note], $reviewer);
    }

    /** عملياتي بحت - لا يمسّ المحتوى، فلا إعادة مراجعة. */
    public function pause(SponsorCampaign $campaign, User $actor): void
    {
        $campaign->refresh();

        $this->assertTransition($campaign, [SponsorCampaign::STATUS_APPROVED], SponsorCampaign::STATUS_PAUSED);

        $campaign->update(['status' => SponsorCampaign::STATUS_PAUSED]);

        $this->audit->log('ads.campaign.paused', $campaign, [], $actor);
    }

    /**
     * لا يُستأنَف إلا من paused. أي تغيير بالمحتوى أثناء الإيقاف يكون قد نقل الحملة
     * فعلًا إلى pending_review (AdInvariantGuard) فلا يصل الاستئناف لحالة paused أصلًا.
     */
    public function resume(SponsorCampaign $campaign, User $actor): void
    {
        DB::transaction(function () use ($campaign, $actor) {
            $locked = SponsorCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            $this->assertTransition($locked, [SponsorCampaign::STATUS_PAUSED], SponsorCampaign::STATUS_APPROVED);
            $this->assertApprovable($locked);

            $locked->update(['status' => SponsorCampaign::STATUS_APPROVED]);

            $this->audit->log('ads.campaign.resumed', $locked, [], $actor);
        });

        $campaign->refresh();
    }

    /** المشكلات المانعة للاعتماد/الإرسال/الاستئناف، من قاعدة البيانات مباشرة. */
    public function approvalProblems(SponsorCampaign $campaign): array
    {
        $fresh = SponsorCampaign::query()->find($campaign->getKey());

        if ($fresh === null) {
            return ['الحملة غير موجودة.'];
        }

        $problems = [];

        if (trim((string) $fresh->sponsor_name) === '') {
            $problems[] = 'اسم الراعي مطلوب.';
        }

        if (trim((string) $fresh->campaign_name) === '') {
            $problems[] = 'اسم الحملة مطلوب.';
        }

        if ($fresh->starts_at !== null && $fresh->ends_at !== null && $fresh->starts_at->gt($fresh->ends_at)) {
            $problems[] = 'جدولة غير صالحة: تاريخ البداية بعد تاريخ النهاية.';
        }

        $placements = $fresh->placements()->get();

        if ($placements->isEmpty()) {
            $problems[] = 'يجب ربط الحملة بموضع عرض واحد على الأقل.';
        }

        foreach ($placements as $placement) {
            if (! AdPlacementRegistry::isKnown($placement->internal_key)) {
                $problems[] = "الموضع '{$placement->internal_key}' غير معروف بسجلّ المواضع.";
            }
        }

        $creatives = $fresh->creatives()->get();

        if ($creatives->where('is_active', true)->isEmpty()) {
            $problems[] = 'يجب وجود مادة إعلانية نشطة واحدة على الأقل.';
        }

        foreach ($creatives as $creative) {
            if (! UrlSafetyGuard::isSafe($creative->destination_url)) {
                $problems[] = 'رابط الوجهة غير آمن أو ليس HTTPS صالحًا.';
                break;
            }
        }

        return array_values(array_unique($problems));
    }

    /** تغييرات الحملة. إبطال الاعتماد عند تغيير هوية الراعي يتم بالنموذج (AdInvariantGuard). */
    public function updateCampaignMeta(SponsorCampaign $campaign, array $data, User $actor): void
    {
        DB::transaction(function () use ($campaign, $data, $actor) {
            $campaign->fill($data);
            $changed = array_keys($campaign->getDirty());
            $campaign->save();

            if ($changed !== []) {
                $this->audit->log('ads.campaign.updated', $campaign, ['changed_fields' => $changed], $actor);
            }
        });

        $campaign->refresh();
    }

    /** المسار الوحيد المعتمد لإضافة مادة: الحارس يتحقق من الرابط ويُبطل الاعتماد السابق عند اللزوم. */
    public function createCreative(SponsorCampaign $campaign, array $data, User $actor): SponsorCreative
    {
        $creative = DB::transaction(function () use ($campaign, $data, $actor) {
            $creative = $campaign->creatives()->create($data);

            $this->audit->log('ads.creative.created', $creative, ['campaign_id' => $campaign->getKey()], $actor);

            return $creative;
        });

        $campaign->refresh();

        return $creative;
    }

    public function updateCreative(SponsorCreative $creative, array $data, User $actor): void
    {
        DB::transaction(function () use ($creative, $data, $actor) {
            $creative->fill($data);
            $changed = array_keys($creative->getDirty());
            $creative->save();

            if ($changed !== []) {
                $this->audit->log('ads.creative.updated', $creative, ['changed_fields' => $changed], $actor);
            }
        });
    }

    public function deleteCreative(SponsorCreative $creative, User $actor): void
    {
        DB::transaction(function () use ($creative, $actor) {
            $campaignId = $creative->sponsor_campaign_id;
            $creativeId = $creative->getKey();

            $creative->delete();

            $this->audit->log('ads.creative.deleted', null, ['creative_id' => $creativeId, 'campaign_id' => $campaignId], $actor);
        });
    }

    protected function assertApprovable(SponsorCampaign $campaign): void
    {
        $problems = $this->approvalProblems($campaign);

        if ($problems !== []) {
            throw new AdInvariantViolation(implode(' ', $problems));
        }
    }

    protected function assertTransition(SponsorCampaign $campaign, array $allowedFrom, string $to): void
    {
        if (! in_array($campaign->status, $allowedFrom, true)) {
            throw new AdInvariantViolation("لا يمكن الانتقال من حالة '{$campaign->status}' إلى '{$to}'.");
        }
    }
}
