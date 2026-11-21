<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * E47 - تمرين في «مختبر الأحجيات» (تدريب فقط).
 *
 * هذا النموذج **ليس أحجية**: لا يشترك مع `puzzles` بجدول ولا بمسار محاولات ولا بمكافآت. يحمل بيانات التأليف الخام لنوعين فقط (memory / sequence) بالشكل نفسه الذي
 * تستعمله أحجيات المحرك الرسمية (game_config.faces / game_config.items) كي تُستعمل محرّرات Filament والمدقّقات القائمة بلا تكرار.
 *
 * الحالات: draft (لا يراها إلا الطاقم بالمعاينة) ← published (ظاهر للاعبين) ← hidden (مسحوب، يعود للنشر عند الحاجة).
 * ثابت النموذج: التمرين الظاهر لا يُحفظ أبدًا بمحتوى غير صالح، أيًّا كان مصدر الكتابة (Filament أو Seeder أو tinker).
 */
class PuzzleLabExercise extends Model
{
    public const TYPE_MEMORY = 'memory';

    public const TYPE_SEQUENCE = 'sequence';

    /** النوعان الوحيدان في هذه الدفعة (لا نوع ثالث). */
    public const TYPES = [
        self::TYPE_MEMORY => 'بطاقات الذاكرة',
        self::TYPE_SEQUENCE => 'ترتيب التسلسل',
    ];

    /** وصف قصير لكل نوع تدريب (يظهر في صفحة المختبر). */
    public const TYPE_BLURBS = [
        self::TYPE_MEMORY => 'اقلب بطاقتين في كل مرة وتذكّر مواضع الأوجه حتى تطابق كل الأزواج.',
        self::TYPE_SEQUENCE => 'رتّب العناصر بالترتيب الصحيح مستعينًا بأزرار التحريك لأعلى ولأسفل.',
    ];

    /** مفردات الصعوبة نفسها لأحجيات المنصة (تعمل معها x-difficulty-badge). */
    public const LEVELS = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_PUBLISHED => 'ظاهر للاعبين',
        self::STATUS_HIDDEN => 'مخفي',
    ];

    public const MIN_ENTRIES = 3;

    public const MAX_ENTRIES = 8;

    /** طول وجه البطاقة (يجب أن يتّسع داخل بطاقة على شاشة جوال ضيقة). */
    public const MAX_FACE_LENGTH = 14;

    public const MAX_ITEM_LENGTH = 80;

    /** status/published_at/created_by/updated_by ليست fillable: تُضبط بإجراءات النشر والإخفاء وصفحات الإدارة فقط. */
    protected $fillable = [
        'slug', 'game_type', 'level', 'sort_order', 'title', 'summary', 'instructions', 'wrong_note', 'success_explanation', 'game_config',
    ];

    protected function casts(): array
    {
        return [
            'game_config' => 'array',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // ملاحظة: حدث saving يسبق creating في Eloquent، فيُولَّد الرابط هنا قبل التحقق منه.
        static::saving(function (PuzzleLabExercise $exercise) {
            if (! $exercise->exists && blank($exercise->slug)) {
                do {
                    $slug = 'lab-'.Str::lower(Str::random(8));
                } while (static::query()->where('slug', $slug)->exists());

                $exercise->slug = $slug;
            }

            if ($exercise->exists && $exercise->isDirty('slug')) {
                throw new \LogicException('لا يتغيّر رابط التمرين (slug) بعد إنشائه.');
            }

            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $exercise->slug) || strlen((string) $exercise->slug) < 3 || $exercise->slug === 'preview') {
                throw new \InvalidArgumentException('رابط التمرين (slug) غير صالح.');
            }

            if ($exercise->status === self::STATUS_PUBLISHED && ($problems = $exercise->contentProblems()) !== []) {
                throw new \DomainException('لا يمكن حفظ تمرين ظاهر للاعبين بمحتوى غير صالح: '.implode(' | ', $problems));
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_PUBLISHED);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->game_type] ?? (string) $this->game_type;
    }

    public function typeBlurb(): string
    {
        return self::TYPE_BLURBS[$this->game_type] ?? '';
    }

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? (string) $this->level;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** يظهر للاعب فقط إن كان منشورًا ومحتواه صالحًا (دفاع ثانٍ لو كُتب صف بطريقة لا تمرّ بالنموذج). */
    public function isPlayable(): bool
    {
        return $this->isPublished() && $this->contentProblems() === [];
    }

    /** @return list<string> أوجه بطاقات الذاكرة بعد التنظيف (بلا فراغات زائدة ولا عناصر فارغة). */
    public function faces(): array
    {
        return $this->cleanList((array) ($this->game_config['faces'] ?? []));
    }

    /** @return list<string> عناصر التسلسل بالترتيب الصحيح (كما كتبه المحرّر من الأعلى للأسفل). */
    public function items(): array
    {
        return $this->cleanList((array) ($this->game_config['items'] ?? []));
    }

    /** عدد عناصر المحتوى الفعلية بحسب النوع (أزواج الذاكرة أو عناصر التسلسل). */
    public function entryCount(): int
    {
        return $this->game_type === self::TYPE_MEMORY ? count($this->faces()) : count($this->items());
    }

    /**
     * أسباب عدم صلاحية التمرين للنشر (رسائل عربية للمحرّر). قائمة فارغة = صالح.
     *
     * @return list<string>
     */
    public function contentProblems(): array
    {
        $problems = [];

        if (blank(trim((string) $this->title))) {
            $problems[] = 'اكتب عنوان التمرين.';
        }

        if (! array_key_exists((string) $this->game_type, self::TYPES)) {
            $problems[] = 'اختر نوع التمرين.';
        }

        if (! array_key_exists((string) $this->level, self::LEVELS)) {
            $problems[] = 'اختر مستوى التمرين.';
        }

        if (blank(trim((string) $this->summary))) {
            $problems[] = 'اكتب وصفًا قصيرًا يظهر في قائمة التمارين.';
        }

        if (blank(trim((string) $this->instructions))) {
            $problems[] = 'اكتب تعليمات التمرين.';
        }

        if (blank(trim((string) $this->wrong_note))) {
            $problems[] = 'اكتب الملاحظة التعليمية التي تظهر بعد المحاولة الخاطئة.';
        }

        if ($this->game_type === self::TYPE_MEMORY) {
            $problems = array_merge($problems, $this->listProblems($this->faces(), 'أزواج الذاكرة', self::MAX_FACE_LENGTH));

            // محرّك الذاكرة القائم يحذف القيمة "0" وحدها عند التطبيع (array_filter)، فنمنعها هنا بدل أن تضيع بصمت.
            if (in_array('0', $this->faces(), true)) {
                $problems[] = 'أزواج الذاكرة: لا تستعمل الرقم 0 وحده؛ اكتب 10 أو «صفر».';
            }
        } elseif ($this->game_type === self::TYPE_SEQUENCE) {
            $problems = array_merge($problems, $this->listProblems($this->items(), 'عناصر التسلسل', self::MAX_ITEM_LENGTH));
        }

        return $problems;
    }

    /** ينشر التمرين بعد التحقق من المحتوى. @throws \DomainException */
    public function publish(): void
    {
        if (($problems = $this->contentProblems()) !== []) {
            throw new \DomainException('لا يمكن نشر التمرين: '.implode(' | ', $problems));
        }

        $this->forceFill(['status' => self::STATUS_PUBLISHED, 'published_at' => $this->published_at ?? now()])->save();
    }

    public function hide(): void
    {
        $this->forceFill(['status' => self::STATUS_HIDDEN])->save();
    }

    /** @param  list<mixed>  $list @return list<string> */
    private function cleanList(array $list): array
    {
        $clean = [];

        foreach ($list as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim((string) preg_replace('/\s+/u', ' ', $value));

            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    /** @param  list<string>  $list @return list<string> */
    private function listProblems(array $list, string $label, int $maxLength): array
    {
        $problems = [];

        if (count($list) < self::MIN_ENTRIES || count($list) > self::MAX_ENTRIES) {
            $problems[] = sprintf('%s: من %d إلى %d عناصر (الحالي %d).', $label, self::MIN_ENTRIES, self::MAX_ENTRIES, count($list));
        }

        if (count(array_unique(array_map(fn (string $v) => mb_strtolower($v), $list))) !== count($list)) {
            $problems[] = $label.': لا يُسمح بتكرار العنصر نفسه.';
        }

        foreach ($list as $value) {
            if (mb_strlen($value) > $maxLength) {
                $problems[] = sprintf('%s: «%s» أطول من %d حرفًا.', $label, mb_substr($value, 0, 12).'…', $maxLength);
            }
        }

        return $problems;
    }
}
