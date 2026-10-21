<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E35 - بلاغ عن مجموعة عامة/غير مدرجة. سبب من قائمة مغلقة (بلا نص حر): لا وسيلة لحقن محتوى مسيء أو مضلِّل عبر نموذج البلاغ.
 * بلاغ مفتوح واحد لكل (مجموعة، مبلِّغ): open_marker=1 عند الفتح وNULL عند الإغلاق (قيد unique بقاعدة البيانات، اصطلاح E28). تكتبه CollectionReportService وحدها.
 */
class UserCollectionReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    public const REASONS = [
        'inappropriate' => 'محتوى غير لائق',
        'spam' => 'محتوى مزعج',
        'misleading' => 'مضلِّل',
        'copyright' => 'انتهاك حقوق نشر',
        'harassment' => 'إساءة أو مضايقة',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(UserCollection::class, 'user_collection_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? self::REASONS['other'];
    }
}
