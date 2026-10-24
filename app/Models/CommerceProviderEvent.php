<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * E41 - سجل أحداث المزوّد (إضافة فقط). حاجز idempotency الأول لـwebhook: unique(provider, provider_event_id).
 * **لا حمولة خام**: بصمة SHA-256 فقط للتشخيص. لا تعديل ولا حذف (الاستثناءات تُرفع هنا وتُغطّيها اختبارات). $guarded=['*']: الكتابة من CommerceEventProcessor حصرًا.
 */
class CommerceProviderEvent extends Model
{
    public const RESULT_APPLIED = 'applied';

    public const RESULT_IGNORED = 'ignored';

    public const RESULT_NEEDS_REVIEW = 'needs_review';

    public const RESULT_REJECTED = 'rejected';

    /** نتيجة لا تُخزَّن (القيد الفريد يمنعها) وتُرجَع للمتصل فقط. */
    public const RESULT_DUPLICATE = 'duplicate';

    public const STORED_RESULTS = [self::RESULT_APPLIED, self::RESULT_IGNORED, self::RESULT_NEEDS_REVIEW, self::RESULT_REJECTED];

    public $timestamps = false;

    protected $table = 'commerce_provider_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->received_at ??= now();
        });

        static::updating(function (): void {
            throw new LogicException('سجل أحداث المزوّد للإضافة فقط.');
        });

        static::deleting(function (): void {
            throw new LogicException('سجل أحداث المزوّد لا يُحذف.');
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(DigitalPurchase::class, 'digital_purchase_id');
    }
}
