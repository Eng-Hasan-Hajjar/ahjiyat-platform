<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * E38 - سجل إجراء تنفيذي أُجري عبر خدمة المجال الرسمية. التاريخ لا يُحذف ولا يُعدَّل إلا في الأعمدة المشتقة (active_marker, reversed_at, reversed_by).
 * reason داخلي للمشرفين فقط؛ public_note وحده يراه المستخدم المعني. هوية المنفِّذ لا تصل للمستخدم أبدًا ("فريق الإشراف").
 */
class ModerationAction extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'no_action' => 'لا إجراء',
        'warn_user' => 'تنبيه',
        'hide_content' => 'إخفاء محتوى',
        'restore_content' => 'استعادة محتوى',
        'lock_discussion' => 'قفل نقاش',
        'unlock_discussion' => 'فتح نقاش',
        'suspend_creator' => 'تعليق مبدع',
        'restore_creator' => 'استعادة مبدع',
        'freeze_user' => 'تجميد حساب',
        'unfreeze_user' => 'إلغاء تجميد حساب',
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $action->public_id ??= (string) Str::ulid();
        });

        static::updating(function (self $action): void {
            $illegal = array_diff(array_keys($action->getDirty()), ['active_marker', 'reversed_at', 'reversed_by']);

            if ($illegal !== []) {
                throw new LogicException('سجل الإجراء لا يقبل تعديل هذه الأعمدة.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('سجلات الإجراءات لا تُحذف.');
        });
    }

    protected function casts(): array
    {
        return ['user_visible' => 'boolean', 'appealable_until' => 'datetime', 'reversed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(ModerationAppeal::class, 'moderation_action_id');
    }
}
