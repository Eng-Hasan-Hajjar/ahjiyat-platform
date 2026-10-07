<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * رسالة دردشة نصية (E21). كل الحقول بالخدمات فقط (guarded=*): المرسل والغرفة والإخفاء لا يأتون من طلب. لا حذف فعلي: deleted_at (حذف المرسل) و hidden_at (إخفاء إشرافي)،
 * والنص يبقى. حارس: لا تغيير للمرسل أو الغرفة بعد الإنشاء، ولا تعديل لنص رسالة محذوفة/مخفية.
 */
class ChatMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (ChatMessage $m) {
            if ($m->isDirty(['chat_thread_id', 'sender_id']) && $m->getOriginal('sender_id') !== null) {
                throw new \InvalidArgumentException('المرسل والغرفة ثابتان.');
            }

            if ($m->isDirty('body') && ($m->getOriginal('deleted_at') !== null || $m->getOriginal('hidden_at') !== null)) {
                throw new \InvalidArgumentException('لا تعديل لرسالة محذوفة أو مخفية.');
            }
        });

        static::deleting(fn () => throw new \InvalidArgumentException('لا حذف فعلي للرسائل: استعمل الحذف الناعم أو الإخفاء.'));
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ChatMessageReport::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isNormal(): bool
    {
        return ! $this->isDeleted() && ! $this->isHidden();
    }
}
