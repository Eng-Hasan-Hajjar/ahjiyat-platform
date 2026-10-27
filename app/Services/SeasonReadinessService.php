<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SeasonContentItem;
use App\Services\Seasons\SeasonException;
use App\Services\Seasons\SeasonPhaseResolver;
use App\Services\Seasons\SeasonTheme;
use Illuminate\Support\Facades\Storage;

/**
 * Generic تماماً - صفر فحص خاص بأصيل. تفحص بنية Campaign الأساسية (Stages/Gates/Steps موجودة)، صحّة روابط Puzzle، عدم وجود Technical
 * Placeholder حاجب، ووجود ملفات الوسائط المُشار إليها فعلياً بالتخزين.
 *
 * E42: report() يفصل **الحاجب** (blockers: يمنع النشر) عن **التحذير** (warnings: لا يمنعه، مثل غياب مراحل أو صورة غلاف أو مسار). check() يبقى بنفس
 * الشكل القديم تمامًا (issues = الحاجب فقط)، فكل من يستعمله (بوابة النشر في Filament وشارة الجدول) يرى القاعدة الموحَّدة ذاتها.
 * لا Workflow Engine - فقط قوائم بسيطة + خلاصة جاهز/غير جاهز. قراءة فقط: لا كتابة ولا استعلامات N+1 على بيانات المستخدمين.
 */
class SeasonReadinessService
{
    /** @return array{ready: bool, issues: string[]} */
    public function check(Season $season): array
    {
        $report = $this->report($season);

        return ['ready' => $report['ready'], 'issues' => $report['blockers']];
    }

    public function isReadyToPublish(Season $season): bool
    {
        return $this->report($season)['ready'];
    }

    /** @return array{ready: bool, blockers: string[], warnings: string[], issues: string[]} */
    public function report(Season $season): array
    {
        $blockers = $this->campaignIssues($season);
        $warnings = [];

        if ($season->campaign !== null) {
            $this->scheduleChecks($season, $blockers, $warnings);
            $this->contentChecks($season, $warnings);
        }

        $this->themeChecks($season, $blockers);
        $this->brandingChecks($season, $warnings);

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings, 'issues' => $blockers];
    }

    /** @return string[] */
    protected function campaignIssues(Season $season): array
    {
        $issues = [];
        $campaign = $season->campaign;

        if ($campaign === null) {
            return ['لا توجد حملة مرتبطة بهذا الموسم.'];
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

        return $issues;
    }

    /**
     * @param  string[]  $blockers
     * @param  string[]  $warnings
     */
    protected function scheduleChecks(Season $season, array &$blockers, array &$warnings): void
    {
        $lifecycle = app(SeasonLifecycleService::class);
        $start = $lifecycle->windowStart($season);
        $end = $lifecycle->windowEnd($season);

        if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
            $blockers[] = 'نافذة الموسم غير صالحة: النهاية ليست بعد البداية.';
        }

        if ($season->announcement_at !== null && $start !== null && $season->announcement_at->greaterThan($start)) {
            $blockers[] = 'موعد إعلان الموسم يتأخر عن بدايته.';
        }

        if ($end === null) {
            $warnings[] = 'الموسم بلا تاريخ نهاية: سيبقى مفتوحًا حتى تُحدَّد نهايته أو يُؤرشف.';
        }

        $phases = $season->relationLoaded('phases') ? $season->phases : $season->phases()->get();
        $ordered = SeasonPhaseResolver::ordered($phases);

        if ($ordered->isEmpty()) {
            $warnings[] = 'لا توجد مراحل للموسم: سيُعرض موسمًا بلا جدول فصول.';
        }

        foreach ($ordered as $i => $phase) {
            if (($start !== null && $phase->starts_at->lt($start)) || ($end !== null && $phase->ends_at->gt($end))) {
                $blockers[] = "المرحلة \"{$phase->name}\" تقع خارج نافذة الموسم.";
            }

            $next = $ordered->get($i + 1);

            if ($next !== null && SeasonPhaseResolver::overlaps($phase->starts_at, $phase->ends_at, $next->starts_at, $next->ends_at)) {
                $blockers[] = "المرحلتان \"{$phase->name}\" و\"{$next->name}\" متداخلتان.";
            }
        }

        if ($season->starts_at !== null && ! $season->campaign->is_active) {
            $warnings[] = 'الحملة الأساسية موقوفة: سيبدأ الموسم لكن لن يستطيع اللاعبون التقدّم فيها قبل تفعيلها.';
        }
    }

    /** @param string[] $warnings */
    protected function contentChecks(Season $season, array &$warnings): void
    {
        $items = $season->relationLoaded('contentItems') ? $season->contentItems : $season->contentItems()->get();
        $items->loadMissing(['competitiveEvent:id,title,status', 'missionPool:id,name,is_active']);

        foreach ($items as $item) {
            if ($item->content_type === SeasonContentItem::TYPE_COMPETITIVE_EVENT && $item->competitiveEvent !== null) {
                if ($item->competitiveEvent->status === CompetitiveEvent::STATUS_DRAFT) {
                    $warnings[] = "المنافسة \"{$item->competitiveEvent->title}\" ما زالت مسودة ولن تظهر للجمهور قبل نشرها.";
                } elseif ($item->competitiveEvent->status === CompetitiveEvent::STATUS_CANCELLED) {
                    $warnings[] = "المنافسة \"{$item->competitiveEvent->title}\" ملغاة.";
                }
            }

            if ($item->content_type === SeasonContentItem::TYPE_MISSION_POOL && $item->missionPool !== null && ! $item->missionPool->is_active) {
                $warnings[] = "مجموعة المهام \"{$item->missionPool->name}\" غير مفعّلة ولن تُسند مهام منها.";
            }
        }

        if ($season->ahjiyat_pass_id !== null) {
            $pass = $season->pass;

            if ($pass !== null && ! $pass->isPubliclyVisible()) {
                $warnings[] = "المسار المرتبط \"{$pass->name}\" غير ظاهر للجمهور بعد.";
            }
        }
    }

    /** @param string[] $blockers */
    protected function themeChecks(Season $season, array &$blockers): void
    {
        try {
            SeasonTheme::validatedSeason(is_array($season->theme_config) ? $season->theme_config : null);
        } catch (SeasonException $e) {
            $blockers[] = 'إعدادات ثيم الموسم غير صالحة: '.$e->getMessage();
        }
    }

    /** @param string[] $warnings */
    protected function brandingChecks(Season $season, array &$warnings): void
    {
        foreach (['banner_image' => 'صورة الغلاف', 'logo_image' => 'الشعار'] as $column => $label) {
            $path = $season->{$column};

            if ($path === null || $path === '') {
                $warnings[] = "لم تُحدَّد {$label}: سيُستعمل الشكل الافتراضي.";
            } elseif (! Storage::disk('public')->exists($path)) {
                $warnings[] = "ملف {$label} المشار إليه غير موجود بالتخزين.";
            }
        }
    }
}
