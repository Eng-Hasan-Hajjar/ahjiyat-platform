<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الموسم الرسمي: غلاف موضوعي لحملة أساسية، وفي E42 **منسّق التجربة الموسمية** (مراحل، حملات إضافية، منافسات، مجموعات مهام، مسار اختياري، إعلانات).
 *
 * الحالة لا تُخزَّن: تُشتقّ في SeasonLifecycleService من تواريخ النافذة (starts_at/ends_at، والـNULL يتبع الحملة الأساسية) وعلم النشر وarchive_at.
 * went_live_at علامة «بدأ لأول مرة» الذرية (SeasonStarted مرة واحدة). الموسم لا يملك اقتصادًا ولا نقاطًا ولا لوحة صدارة.
 */
class Season extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const RESERVED_SLUGS = ['archive'];

    protected $fillable = [
        'campaign_id', 'code', 'slug', 'banner_image', 'logo_image',
        'grand_prize_description', 'theme_config', 'is_published', 'is_featured',
        'starts_at', 'ends_at', 'announcement_at', 'archive_at', 'ahjiyat_pass_id',
    ];

    protected function casts(): array
    {
        return [
            'theme_config' => 'array',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'went_live_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'announcement_at' => 'datetime',
            'archive_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Season $season) {
            if (in_array($season->slug, (array) config('seasons.reserved_slugs', self::RESERVED_SLUGS), true)) {
                throw new \InvalidArgumentException('هذا المعرّف محجوز لمسار بالموقع. اختر معرّفًا آخر.');
            }

            if ($season->starts_at !== null && $season->ends_at !== null && $season->ends_at->lessThanOrEqualTo($season->starts_at)) {
                throw new \InvalidArgumentException('نهاية الموسم يجب أن تكون بعد بدايته.');
            }

            if ($season->announcement_at !== null && $season->starts_at !== null && $season->announcement_at->greaterThan($season->starts_at)) {
                throw new \InvalidArgumentException('موعد الإعلان يجب ألا يتأخر عن بداية الموسم.');
            }

            if ($season->archive_at !== null && $season->ends_at !== null && $season->archive_at->lessThan($season->ends_at)) {
                throw new \InvalidArgumentException('الأرشفة لا تسبق نهاية الموسم.');
            }
        });

        // انتقال حقيقي: إنشاء موسم منشور، أو نشر موسم موجود، أو تغيّر نافذته/ظهوره. القرار الفعلي "هل صار مباشرًا؟" بـSeasonLifecycleService وحده.
        static::created(fn (Season $season) => \App\Services\SeasonLifecycleService::syncFromHook($season));

        static::updated(function (Season $season) {
            if ($season->wasChanged(['is_published', 'starts_at', 'ends_at', 'announcement_at', 'archive_at'])) {
                \App\Services\SeasonLifecycleService::syncFromHook($season);
            }
        });

        // تاريخ الموسم لا يضيع بالصدفة: ما بدأ فعلًا يُؤرشف ولا يُحذف. (حذف الحملة الأساسية يبقى cascade على مستوى قاعدة البيانات كما كان.)
        static::deleting(function (Season $season) {
            if ($season->went_live_at !== null) {
                throw new \LogicException('موسم بدأ فعلًا لا يُحذف. أرشِفه بدلًا من ذلك.');
            }
        });

        static::saved(fn () => \App\Services\Seasons\SeasonCache::flush());
        static::deleted(fn () => \App\Services\Seasons\SeasonCache::flush());
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(AhjiyatPass::class, 'ahjiyat_pass_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(SeasonPhase::class)->orderBy('starts_at')->orderBy('sort_order')->orderBy('id');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(SeasonContentItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(SeasonAnnouncement::class);
    }

    public function themePreset(): string
    {
        $preset = (string) ($this->theme_config['preset'] ?? 'generic');

        return in_array($preset, (array) config('seasons.theme.presets', ['generic']), true) ? $preset : 'generic';
    }

    public function heroTagline(): ?string
    {
        return $this->theme_config['hero_tagline'] ?? null;
    }

    // ───────────────────────── نطاقات الاستعلام (تعكس SeasonLifecycleService حرفيًا) ─────────────────────────

    /** منشور وبلغ موعد ظهوره (announcement_at). كل استعلام عام عن المواسم يمرّ من هنا. */
    public function scopePubliclyVisible(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where('seasons.is_published', true)
            ->where(fn ($w) => $w->whereNull('seasons.announcement_at')->orWhere('seasons.announcement_at', '<=', $now));
    }

    /**
     * المواسم المنتهية/المؤرشفة (مرآة SQL لحالة ended|archived المشتقّة). تضم join على الحملة وتختار نهاية/بداية فعلية.
     * الحملة الموقوفة (is_active=false) بموسم بلا نافذة صريحة «غير متاحة» لا «منتهية» (السلوك القديم).
     */
    public function scopeEnded(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.*')
            ->where(function ($w) use ($now) {
                $w->where('seasons.archive_at', '<=', $now)
                    ->orWhere(function ($e) use ($now) {
                        $e->where(fn ($x) => $x->whereNotNull('seasons.starts_at')->orWhereNotNull('seasons.ends_at')->orWhere('campaigns.is_active', true))
                            ->whereRaw('COALESCE(seasons.ends_at, campaigns.ends_at) < ?', [$now])
                            ->where(fn ($s) => $s->whereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) IS NULL')
                                ->orWhereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) <= ?', [$now]));
                    });
            });
    }

    /** نقيض scopeEnded ضمن المواسم المرئية: ما لم ينتهِ بعد (قادم/مباشر/غير متاح). يتطلب join على الحملة. */
    public function scopeNotEnded(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->select('seasons.*')
            ->where(fn ($w) => $w->whereNull('seasons.archive_at')->orWhere('seasons.archive_at', '>', $now))
            ->where(function ($w) use ($now) {
                $w->where(fn ($x) => $x->whereNull('seasons.starts_at')->whereNull('seasons.ends_at')->where('campaigns.is_active', false))
                    ->orWhereRaw('COALESCE(seasons.ends_at, campaigns.ends_at) IS NULL')
                    ->orWhereRaw('COALESCE(seasons.ends_at, campaigns.ends_at) >= ?', [$now])
                    ->orWhereRaw('COALESCE(seasons.starts_at, campaigns.starts_at) > ?', [$now]);
            });
    }
}
