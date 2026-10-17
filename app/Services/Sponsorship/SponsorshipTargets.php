<?php

namespace App\Services\Sponsorship;

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\Season;
use App\Models\SponsorshipProgram;

/**
 * E30 - أهداف الرعاية: قائمة بيضاء مغلقة (platform / season / campaign / competitive_event) كلها محتوى **رسمي**. أحجيات المجتمع وسلاسل المبدعين وملفات المبدعين
 * ليست أهدافًا أبدًا (لا نوع لها هنا فيُرفض أي هدف خارج القائمة). الهدف يجب أن يكون موجودًا؛ المنصة بلا معرّف.
 */
class SponsorshipTargets
{
    /** @throws SponsorshipException */
    public function validate(string $type, ?int $id): void
    {
        if (! in_array($type, SponsorshipProgram::TARGETS, true)) {
            throw new SponsorshipException('نوع هدف الرعاية غير مسموح (المسموح: المنصة، موسم، حملة، منافسة).');
        }

        if ($type === SponsorshipProgram::TARGET_PLATFORM) {
            if ($id !== null) {
                throw new SponsorshipException('رعاية المنصة لا تحمل معرّف هدف.');
            }

            return;
        }

        if ($id === null || $id <= 0) {
            throw new SponsorshipException('اختر الهدف.');
        }

        $exists = match ($type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->whereKey($id)->exists(),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->whereKey($id)->exists(),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->whereKey($id)->where('status', '!=', CompetitiveEvent::STATUS_CANCELLED)->exists(),
        };

        if (! $exists) {
            throw new SponsorshipException('الهدف غير موجود أو غير صالح للرعاية.');
        }
    }

    public function typeLabel(string $type): string
    {
        return [
            SponsorshipProgram::TARGET_PLATFORM => 'المنصة',
            SponsorshipProgram::TARGET_SEASON => 'موسم',
            SponsorshipProgram::TARGET_CAMPAIGN => 'حملة',
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => 'منافسة',
        ][$type] ?? $type;
    }

    public function label(SponsorshipProgram $program): string
    {
        $name = match ($program->target_type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')->where('seasons.id', $program->target_id)->value('campaigns.title'),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->whereKey($program->target_id)->value('title'),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->whereKey($program->target_id)->value('title'),
            default => null,
        };

        return $this->typeLabel((string) $program->target_type).($name ? ': '.$name : '');
    }

    /** رابط صفحة الهدف العامة (إن وُجدت). */
    public function url(SponsorshipProgram $program): ?string
    {
        return match ($program->target_type) {
            SponsorshipProgram::TARGET_SEASON => ($slug = Season::query()->whereKey($program->target_id)->value('slug')) ? route('seasons.show', $slug) : null,
            SponsorshipProgram::TARGET_CAMPAIGN => ($slug = Campaign::query()->whereKey($program->target_id)->value('slug')) ? route('campaigns.show', $slug) : null,
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => ($slug = CompetitiveEvent::query()->whereKey($program->target_id)->value('slug')) ? route('competitions.show', $slug) : null,
            default => null,
        };
    }

    /** @return array<int, string> خيارات الهدف لنوع معيّن (للنماذج الإدارية). مقيَّدة العدد. */
    public function options(string $type): array
    {
        return match ($type) {
            SponsorshipProgram::TARGET_SEASON => Season::query()->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')->orderByDesc('seasons.id')->limit(100)->pluck('campaigns.title', 'seasons.id')->all(),
            SponsorshipProgram::TARGET_CAMPAIGN => Campaign::query()->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            SponsorshipProgram::TARGET_COMPETITIVE_EVENT => CompetitiveEvent::query()->where('status', '!=', CompetitiveEvent::STATUS_CANCELLED)->orderByDesc('id')->limit(100)->pluck('title', 'id')->all(),
            default => [],
        };
    }
}
