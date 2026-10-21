<?php

namespace App\Services\LiveOps\Calendar;

use Illuminate\Support\Carbon;

/**
 * E34 - عنصر تقويم موحَّد (DTO للقراءة فقط). لا يخزّن بيانات مصدره ولا يكرّرها: هو إسقاط لحدث موجود (موسم/حملة/منافسة/بطولة/دعوة/رعاية).
 * الحالة والعدّ التنازلي مشتقان خادميًا من تواريخ حقيقية فقط؛ لا عدّاد بلا تاريخ فعلي ولا استعجال مصطنع.
 */
final class EventItem
{
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $title,
        public readonly ?Carbon $startsAt,
        public readonly ?Carbon $endsAt,
        public readonly string $status,
        public readonly string $url,
        public readonly string $badge,
        public readonly ?string $image = null,
        public readonly ?int $ref = null,
        public readonly ?int $campaignId = null,
    ) {}

    /** التاريخ الحقيقي الذي يُعدّ تنازليًا إليه (البدء للقادم، الانتهاء للجاري إن وُجد)، وإلا null (لا عدّاد). */
    public function countdownTo(): ?Carbon
    {
        return match ($this->status) {
            EventCalendarService::STATUS_UPCOMING => $this->startsAt,
            EventCalendarService::STATUS_LIVE, EventCalendarService::STATUS_ENDING_SOON => $this->endsAt,
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            EventCalendarService::STATUS_UPCOMING => 'قريبًا',
            EventCalendarService::STATUS_LIVE => 'جارٍ الآن',
            EventCalendarService::STATUS_ENDING_SOON => 'ينتهي قريبًا',
            default => 'انتهى',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'status' => $this->status,
            'url' => $this->url,
            'badge' => $this->badge,
            'image' => $this->image,
        ];
    }
}
