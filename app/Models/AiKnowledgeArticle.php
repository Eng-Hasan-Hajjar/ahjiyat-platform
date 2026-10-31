<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * E45 - مقال معرفة منقّح يدويًا وموجَّه للمستخدم (لا وثائق إدارية ولا تشغيلية). وحدها المقالات **المنشورة** تصل للاسترجاع.
 *
 * - الحالة/النسخة/البصمة/تواريخ النشر تكتبها KnowledgePublisher وحدها (ليست fillable): لا حقل حر يغيّر حالة النشر.
 * - audience مجموعة مغلقة (public | member). لا «admin» عمدًا: المساعد الموجَّه للعملاء لا يحتاج وثائق إدارية.
 * - source_reference مرجع إداري للتتبع فقط، لا يُرسَل للنموذج ولا يُعرَض للمستخدم.
 */
class AiKnowledgeArticle extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED];

    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_MEMBER = 'member';

    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_MEMBER];

    public const TYPE_CURATED = 'curated';

    public const TYPE_DISTILLED = 'distilled';

    public const SOURCE_TYPES = [self::TYPE_CURATED, self::TYPE_DISTILLED];

    /** مجالات مغلقة تُستعمل في المقالات والمقاييس وتقرير فجوات المعرفة. */
    public const AREAS = [
        'general' => 'عام',
        'getting_started' => 'البداية',
        'puzzles' => 'الأحجيات',
        'seasons' => 'المواسم',
        'campaigns' => 'الحملات',
        'competitions' => 'المنافسات',
        'teams' => 'الفرق',
        'pass' => 'مسار الأحجيات',
        'store' => 'المتجر',
        'creators' => 'المبدعون',
        'creator_series' => 'سلاسل المبدعين',
        'writer_league' => 'دوري الكتّاب',
        'supporters' => 'الداعمون',
        'referrals' => 'الدعوات',
        'achievements' => 'الإنجازات',
        'progression' => 'التقدّم',
        'account' => 'الحساب',
        'policies' => 'السياسات',
        'search' => 'البحث',
        'chat' => 'الدردشة',
        'notifications' => 'الإشعارات',
        'other' => 'أخرى',
    ];

    public const EDITORIAL_FIELDS = ['slug', 'locale', 'title', 'summary', 'body', 'audience', 'area', 'tags', 'source_label', 'canonical_path', 'source_type', 'source_reference'];

    /** فقط الحقول التحريرية؛ الحالة والنسخة والبصمة وتواريخ النشر لا تُملأ جماعيًا أبدًا. */
    protected $fillable = self::EDITORIAL_FIELDS;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $article): void {
            $article->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** المنشور فقط. هذا هو النطاق الوحيد المسموح به لأي قراءة تصل للنموذج أو للصفحات العامة. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** مقالات الجمهور المسموح له: الضيف يرى public فقط، والعضو public + member. */
    public function scopeForAudience(Builder $query, bool $member): Builder
    {
        return $query->whereIn('audience', $member ? [self::AUDIENCE_PUBLIC, self::AUDIENCE_MEMBER] : [self::AUDIENCE_PUBLIC]);
    }

    public function publicPath(): string
    {
        return '/help/'.$this->slug;
    }
}
