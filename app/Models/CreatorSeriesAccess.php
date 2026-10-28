<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * E43 - وصول مدفوع إلى سلسلة مبدع: **السلطة** على ملكية السلسلة المميزة. يُنشأ/يُعاد تفعيله/يُسحب من معالج منتج E41 حصرًا (CreatorSeriesAccessProductHandler) داخل معاملة التحقق/الاسترداد.
 *
 * - unique(user_id, creator_series_id): صف واحد لكل (مشترٍ، سلسلة). الاسترداد يضع revoked_at (لا حذف)؛ إعادة الشراء بعد الاسترداد تعيد تفعيل الصف بمصدر الشراء الجديد وتزيد grant_count.
 * - لا معاينة مبدع ولا إشراف هنا: تلك تُحسب من سياسة الوصول دون صفوف.
 * - $guarded=['*']. لا حذف أبدًا.
 */
class CreatorSeriesAccess extends Model
{
    public const REVOKE_REFUND = 'refund';

    protected $table = 'creator_series_accesses';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'grant_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $access): void {
            $access->public_id ??= (string) Str::ulid();
        });

        static::updating(function (self $access): void {
            foreach (['public_id', 'user_id', 'creator_series_id'] as $immutable) {
                if ($access->isDirty($immutable)) {
                    throw new LogicException("العمود {$immutable} ثابت.");
                }
            }

            // مصدر الشراء يتغير فقط في إعادة تفعيل صف مسحوب (بعد استرداد) بشراء جديد.
            if ($access->isDirty('source_digital_purchase_id')) {
                $regrant = $access->getOriginal('revoked_at') !== null && $access->revoked_at === null && (int) $access->grant_count > (int) $access->getOriginal('grant_count');

                if (! $regrant) {
                    throw new LogicException('مصدر الشراء لا يتغير إلا عند إعادة تفعيل وصول مسحوب.');
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('سجلات الوصول المدفوع لا تُحذف.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CreatorSeries::class, 'creator_series_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(DigitalPurchase::class, 'source_digital_purchase_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
