<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\Season;
use Illuminate\Support\Facades\Storage;

/**
 * Generic تماماً - صفر فحص خاص بأصيل. تفحص فقط بنية Campaign الأساسية
 * (Stages/Gates/Steps موجودة)، صحّة روابط Puzzle، عدم وجود Technical
 * Placeholder حاجب، ووجود ملفات الوسائط المُشار إليها فعلياً بالتخزين.
 * لا Workflow Engine - فقط قائمة مشاكل بسيطة + خلاصة جاهز/غير جاهز.
 */
class SeasonReadinessService
{
    /** @return array{ready: bool, issues: string[]} */
    public function check(Season $season): array
    {
        $issues = [];
        $campaign = $season->campaign;

        if ($campaign === null) {
            return ['ready' => false, 'issues' => ['لا توجد حملة مرتبطة بهذا الموسم.']];
        }

        $campaign->loadMissing('stages.gates.steps.puzzle');

        if ($campaign->stages->isEmpty()) {
            $issues[] = 'الحملة لا تحتوي أي مرحلة (Stage).';
        }

        foreach ($campaign->stages as $stage) {
            if ($stage->gates->isEmpty()) {
                $issues[] = "المرحلة \"{$stage->title}\" لا تحتوي أي بوابة (Gate).";

                continue;
            }

            foreach ($stage->gates as $gate) {
                if ($gate->steps->isEmpty()) {
                    $issues[] = "البوابة \"{$gate->title}\" لا تحتوي أي خطوة (Step).";

                    continue;
                }

                foreach ($gate->steps as $step) {
                    if ($step->kind === CampaignStep::KIND_PUZZLE && $step->puzzle === null) {
                        $issues[] = "الخطوة \"{$step->title}\" من نوع أحجية لكن لا تملك أحجية مرتبطة فعلياً.";
                    }

                    if ($step->contentStatus() === CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING) {
                        $issues[] = "الخطوة \"{$step->title}\" بانتظار قرار تقني نهائي من العميل قبل النشر العام.";
                    }

                    $mediaPath = $step->content['media_path'] ?? null;

                    if ($mediaPath !== null && ! Storage::disk('public')->exists($mediaPath)) {
                        $issues[] = "الخطوة \"{$step->title}\" تشير إلى ملف وسائط غير موجود فعلياً بالتخزين.";
                    }
                }
            }
        }

        return ['ready' => $issues === [], 'issues' => $issues];
    }

    public function isReadyToPublish(Season $season): bool
    {
        return $this->check($season)['ready'];
    }
}