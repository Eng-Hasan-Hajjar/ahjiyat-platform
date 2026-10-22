<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E36 - بلاغ عن تعليق. سبب من قائمة مغلقة (بلا نص حر من المبلِّغ). body_snapshot نسخة النص لحظة البلاغ: دليل **للإشراف فقط** لا يظهر في أي واجهة عامة،
 * ويبقى كما هو إن عدّل صاحب التعليق نصّه لاحقًا أو حذفه. بلاغ مفتوح واحد لكل (تعليق، مبلِّغ): open_marker=1 عند الفتح وNULL عند الإغلاق (قيد unique بالقاعدة).
 */
class DiscussionCommentReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DISMISSED, self::STATUS_ACTIONED];

    /** أسباب البلاغ نفسها أسباب الإخفاء المهيكلة (قائمة مغلقة واحدة). */
    public const REASONS = [
        'spam' => 'محتوى مزعج',
        'harassment' => 'إساءة أو مضايقة',
        'hate_or_abuse' => 'كراهية أو إساءة',
        'inappropriate' => 'محتوى غير لائق',
        'misleading' => 'مضلِّل',
        'copyright' => 'انتهاك حقوق نشر',
        'other' => 'سبب آخر',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(DiscussionComment::class, 'discussion_comment_id');
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
