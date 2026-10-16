<?php

namespace App\Models;

use App\GameEngine\GameTypeRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Puzzle extends Model
{
    use HasFactory;

    /** E28: مصدر الأحجية حقيقة سيرفرية على الصف (لا من الرابط). 'origin' ليس بالـfillable: يُضبط عند الإنشاء فقط عبر forceFill من مُنشئ أحجيات المجتمع. */
    public const ORIGIN_OFFICIAL = 'official';

    public const ORIGIN_COMMUNITY = 'community';

     protected $fillable = [
        'puzzle_category_id', 'title', 'type', 'difficulty', 'prompt',
        'image_path', 'choices', 'answer_hash', 'answer_raw', 'hint', 'max_attempts',
        'time_limit_seconds', 'gem_reward', 'xp_reward', 'reward_currency_id', 'is_daily_puzzle',
        'daily_puzzle_date', 'is_active',
        'game_type', 'game_config', 'solution_data', 'renderer', 'validation_type', 'score_mode',
    ];

    protected $appends = [];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'game_config' => 'array',
            'solution_data' => 'array',
            'is_daily_puzzle' => 'boolean',
            'is_active' => 'boolean',
            'daily_puzzle_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Puzzle $puzzle) {
            // E28: أحجية المجتمع لقطة لنسخة معتمدة. لا تُعدَّل بعد إنشائها (سلامة المحاولات التاريخية) ولا يتبدّل مصدرها أبدًا،
            // وتُنشأ دائمًا بلا أي مكافأة/ربط رسمي/تفعيل رسمي مهما كان مصدر الكتابة (رفض افتراضي على مستوى النموذج).
            if ($puzzle->exists) {
                if ($puzzle->isDirty('origin')) {
                    throw new \LogicException('لا يمكن تغيير مصدر الأحجية.');
                }

                if ($puzzle->getOriginal('origin') === self::ORIGIN_COMMUNITY) {
                    throw new \LogicException('أحجية المجتمع المنشورة لقطة ثابتة ولا تُعدَّل؛ أنشئ نسخة جديدة بدلًا منها.');
                }
            } elseif ($puzzle->isCommunity()) {
                $puzzle->forceFill([
                    'is_active' => false,
                    'gem_reward' => 0,
                    'xp_reward' => 0,
                    'reward_currency_id' => null,
                    'is_daily_puzzle' => false,
                    'daily_puzzle_date' => null,
                    'time_limit_seconds' => null,
                ]);
            }

            app(GameTypeRegistry::class)->prepareForSave($puzzle);
        });

        static::deleting(function (Puzzle $puzzle) {
            if ($puzzle->isCommunity()) {
                throw new \LogicException('أحجية المجتمع جزء من سجل النسخ ولا تُحذف.');
            }
        });
    }

    public function isCommunity(): bool
    {
        return $this->getAttribute('origin') === self::ORIGIN_COMMUNITY;
    }

    /** الأحجيات الرسمية فقط (لوحة الإدارة والإحصاءات والمنتقيات). الصفوف القديمة رسمية بالافتراضي. */
    public function scopeOfficial($query)
    {
        return $query->where($query->getModel()->getTable().'.origin', self::ORIGIN_OFFICIAL);
    }

    public function setAnswerRawAttribute(?string $value): void
    {
        if (filled($value)) {
            $this->attributes['answer_hash'] = static::normalizeAndHash($value);
        }
    }

    public static function normalizeAndHash(string $answer): string
    {
        $normalized = trim(mb_strtolower($answer));
        $normalized = preg_replace('/[\x{064B}-\x{0652}]/u', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return hash('sha256', $normalized);
    }

    public function checkAnswer(string $submitted): bool
    {
        return hash_equals($this->answer_hash, static::normalizeAndHash($submitted));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PuzzleCategory::class, 'puzzle_category_id');
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PuzzleAttempt::class);
    }

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_puzzle');
    }

    public function campaignSteps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }
}