<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E28 - العمل الإبداعي: هوية ثابتة (public_id) + لقطة النشر الحالية + الإخفاء/الأرشفة. المحتوى نفسه بالنسخ (CreatorPuzzleRevision).
 *
 * is_visible مشتقّة آليًا عند كل حفظ = (منشور) ∧ (غير مخفي) ∧ (غير مؤرشف): مصدر واحد للحقيقة تقرؤه كل الأسطح العامة (البحث/الاكتشاف/الصفحات)
 * بشرط واحد مفهرس بلا ضمّ. لا تُكتب يدويًا أبدًا. مصالحة التعليق/التجميد تضبط hidden_* عبر CreatorVisibilityService.
 */
class CreatorPuzzle extends Model
{
    public const HIDDEN_MODERATION = 'moderation';

    public const HIDDEN_CREATOR_SUSPENDED = 'creator_suspended';

    public const HIDDEN_ACCOUNT_FROZEN = 'account_frozen';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (CreatorPuzzle $work) {
            $work->public_id ??= (string) Str::ulid();
        });

        static::saving(function (CreatorPuzzle $work) {
            $work->is_visible = $work->published_puzzle_id !== null
                && $work->hidden_at === null
                && $work->archived_at === null;
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'first_published_at' => 'datetime',
            'archived_at' => 'datetime',
            'hidden_at' => 'datetime',
            'is_visible' => 'boolean',
            'plays_count' => 'integer',
            'ratings_count' => 'integer',
            'ratings_sum' => 'integer',
            'favorites_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(CreatorProfile::class, 'creator_profile_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CreatorPuzzleRevision::class);
    }

    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'latest_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CreatorPuzzleRevision::class, 'published_revision_id');
    }

    public function publishedPuzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class, 'published_puzzle_id');
    }

    public function publishedCategory(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'published_category_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommunityPuzzleReport::class);
    }

    /** E29: مصدر الحقيقة للتقييمات (العدّادات المجمَّعة بأعمدة العمل مشتقّة منها). */
    public function ratings(): HasMany
    {
        return $this->hasMany(CommunityPuzzleRating::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CommunityPuzzleFavorite::class);
    }

    /** متوسط التقييم بخانة عشرية واحدة، أو null بلا تقييمات. يقرأ العدّادين المجمَّعين فقط (بلا AVG/COUNT بكل بطاقة). */
    public function averageRating(): ?float
    {
        $count = (int) $this->getAttribute('ratings_count');

        return $count > 0 ? round(((int) $this->getAttribute('ratings_sum')) / $count, 1) : null;
    }

    public function scopeVisible($query)
    {
        return $query->where($query->getModel()->getTable().'.is_visible', true);
    }

    /**
     * E43: يضمّن عمود premium_lock_since (أقدم تفعيل لعرض مميز قافل لهذا العمل، أو NULL = حرّ) في الاستعلام نفسه بلا استعلام إضافي لكل عمل.
     * مصدر القفل الوحيد: PremiumLockQuery. لا يغيّر الظهور (scopeVisible يبقى سلطة الظهور).
     */
    public function scopeWithPremiumLock($query)
    {
        return $query->addSelect(['premium_lock_since' => \App\Services\CreatorPremium\PremiumLockQuery::lockSince($query->getModel()->getTable().'.id')]);
    }

    public function isPublished(): bool
    {
        return $this->published_puzzle_id !== null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
