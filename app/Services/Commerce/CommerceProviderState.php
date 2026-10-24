<?php

namespace App\Services\Commerce;

/**
 * E41 - الحالة **المطبَّعة** لدفعة المزوّد. نصوص المزوّد الخاصة لا تتسرب إلى منطق المجال: كل محوّل (adapter) يترجمها إلى هذه المجموعة الصغيرة فقط.
 * الرتبة تحدد التقدّم: provider_status يصعد ولا يهبط (pending < failed|cancelled < paid < refunded)، فلا يُرجعها حدث متأخر/قديم.
 */
enum CommerceProviderState: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Paid = 'paid';
    case Refunded = 'refunded';
    /** حدث/حالة لا يعرفها المحوّل: لا أثر مالي أبدًا (يُسجَّل للتشخيص فقط). */
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Pending => 1,
            self::Failed, self::Cancelled => 2,
            self::Paid => 3,
            self::Refunded => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد المعالجة',
            self::Failed => 'فشلت العملية',
            self::Cancelled => 'أُلغيت عند المزوّد',
            self::Paid => 'مدفوع',
            self::Refunded => 'مسترد',
            self::Unknown => 'غير معروفة',
        };
    }

    public function isTerminalFailure(): bool
    {
        return $this === self::Failed || $this === self::Cancelled;
    }
}
