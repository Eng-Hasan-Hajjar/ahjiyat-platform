<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E36 - تعليق (جذر) أو ردّ (مستوى واحد فقط). نص عادي. المعرّف العام ULID. الحالة: visible | moderation_hidden؛ الحذف الناعم عبر deleted_at (الجسم يُفرَّغ).
 * لا يكتب العميل أي حقل: الخدمات وحدها تكتب (guarded + forceFill). "حيّ" = visible ∧ غير محذوف: وحده يُعرض نصّه ويُعدّ ويقبل رد/إعجاب/تعديل.
 */
class DiscussionComment extends Model
{
    public const STATUS_VISIBLE = 'visible';

    public const STATUS_HIDDEN = 'moderation_hidden';

    public const STATUSES = [self::STATUS_VISIBLE, self::STATUS_HIDDEN];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $comment) {
            $comment->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'moderated_at' => 'datetime',
            'likes_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(DiscussionThread::class, 'discussion_thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DiscussionCommentReport::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** حيّ = ظاهر وغير محذوف. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_VISIBLE)->whereNull($query->getModel()->getTable().'.deleted_at');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.parent_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_VISIBLE && $this->deleted_at === null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isHidden(): bool
    {
        return $this->status === self::STATUS_HIDDEN;
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }
}
