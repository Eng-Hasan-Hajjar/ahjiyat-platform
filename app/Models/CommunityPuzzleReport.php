<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E28 - بلاغ عن أحجية مجتمع منشورة. بلاغ مفتوح واحد لكل مستخدم/عمل: open_marker=1 عند الفتح وNULL عند الإغلاق (قيد unique بقاعدة البيانات).
 * لا تعيين جماعي: تكتبه CommunityReportService.
 */
class CommunityPuzzleReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    public const REASONS = [
        'inappropriate' => 'محتوى غير لائق',
        'copyright' => 'انتهاك حقوق نشر',
        'misleading' => 'مضلِّل',
        'broken' => 'الأحجية لا تعمل/خطأ بالحل',
        'spam' => 'محتوى مزعج',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzle::class, 'creator_puzzle_id');
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
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
