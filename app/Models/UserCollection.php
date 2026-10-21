<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E29 - مجموعة شخصية؛ E35 - تتطوّر إلى private|unlisted|public دون محرك ثانٍ.
 *
 * المعرّف العام ULID. **الافتراضي والقيمة المرحَّلة لكل الصفوف القديمة: private**، ومسارات المالك (E29) تُحلّ دائمًا باستعلام محصور بالمالك (غير المالك = 404) كما كانت.
 * القراءة العامة (المشاهد ≠ المالك) لا تمرّ إلا عبر App\Services\Collections\CollectionAccess (ظهور + حظر + أهلية المالك + الإخفاء الإشرافي).
 * العنوان والوصف نص عادي (وسوم HTML مرفوضة عند الإدخال وتُهرَّب عند العرض). تكتبها خدمات المجموعات وحدها (لا تعيين جماعي).
 */
class UserCollection extends Model
{
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_UNLISTED, self::VISIBILITY_PUBLIC];

    /** تسمية قصيرة + شرح بالعربية (يعرضهما المالك عند الاختيار). */
    public const VISIBILITY_LABELS = [
        self::VISIBILITY_PRIVATE => ['label' => 'خاصة', 'hint' => 'أنت فقط'],
        self::VISIBILITY_UNLISTED => ['label' => 'غير مدرجة', 'hint' => 'من لديه الرابط فقط'],
        self::VISIBILITY_PUBLIC => ['label' => 'عامة', 'hint' => 'يمكن أن تظهر في البحث والاكتشاف'],
    ];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (UserCollection $collection) {
            $collection->public_id ??= (string) Str::ulid();
            $collection->visibility ??= self::VISIBILITY_PRIVATE;
        });
    }

    protected function casts(): array
    {
        return [
            'first_published_at' => 'datetime',
            'moderation_hidden_at' => 'datetime',
            'is_moderation_hidden' => 'boolean',
            'saves_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(UserCollectionItem::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(UserCollectionSave::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(UserCollectionReport::class);
    }

    public function moderationHiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderation_hidden_by');
    }

    public function isPrivate(): bool
    {
        return ($this->visibility ?? self::VISIBILITY_PRIVATE) === self::VISIBILITY_PRIVATE;
    }

    public function isUnlisted(): bool
    {
        return $this->visibility === self::VISIBILITY_UNLISTED;
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    /** غير خاصة (unlisted أو public): تُقرأ عبر الرابط العام. */
    public function isShared(): bool
    {
        return ! $this->isPrivate();
    }

    public static function visibilityLabel(string $visibility): string
    {
        return self::VISIBILITY_LABELS[$visibility]['label'] ?? self::VISIBILITY_LABELS[self::VISIBILITY_PRIVATE]['label'];
    }

    public static function visibilityHint(string $visibility): string
    {
        return self::VISIBILITY_LABELS[$visibility]['hint'] ?? self::VISIBILITY_LABELS[self::VISIBILITY_PRIVATE]['hint'];
    }
}
