<?php

namespace App\Services\TrustSafety;

use Carbon\CarbonImmutable;

/**
 * E38 - صف قراءة فقط في الصندوق الموحَّد: **ليس نسخة** من البلاغ؛ يُبنى لحظة العرض من الجداول الأصلية. للمشرفين فقط.
 * إشارة النظام (systemSignal) ليست بلاغ مستخدم وليست مخالفة مؤكَّدة، وتُعرض بوسم «إشارة نظام» دائمًا.
 */
final class InboxItem
{
    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceLabel,
        public readonly int $sourceId,
        public readonly bool $systemSignal,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $subjectLabel,
        public readonly bool $subjectExists,
        public readonly ?int $subjectUserId,
        public readonly ?string $subjectUserName,
        public readonly ?string $excerpt,
        public readonly string $reasonCode,
        public readonly string $category,
        public readonly string $categoryLabel,
        public readonly string $priority,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly ?string $severity,
        public readonly ?CarbonImmutable $reportedAt,
        public readonly ?string $casePublicId,
        public readonly ?string $caseNumber,
        public readonly ?string $caseStatus,
        public readonly ?int $caseAssigneeId,
        public readonly ?string $caseAssigneeName,
    ) {}

    public function hasCase(): bool
    {
        return $this->casePublicId !== null;
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** مفتاح مرجع آمن للنماذج: لا يحمل اسم فئة، فقط (نوع من القائمة البيضاء، رقم). */
    public function ref(): string
    {
        return $this->sourceType.':'.$this->sourceId;
    }
}
