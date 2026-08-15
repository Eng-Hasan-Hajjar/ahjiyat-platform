<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Puzzle extends Model
{
    protected $fillable = [
        'puzzle_category_id', 'title', 'type', 'difficulty', 'prompt',
        'image_path', 'choices', 'answer_hash', 'hint', 'max_attempts',
        'time_limit_seconds', 'gem_reward', 'is_daily_puzzle',
        'daily_puzzle_date', 'is_active',
    ];

    // answer_raw موديل مؤقت (مو عمود بقاعدة البيانات) يستخدم فقط لحظة الإنشاء/التعديل
    // من لوحة الإدارة عشان نحوله إلى answer_hash تلقائياً - شوف setAnswerRawAttribute تحت.
    protected $appends = [];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'is_daily_puzzle' => 'boolean',
            'is_active' => 'boolean',
            'daily_puzzle_date' => 'date',
        ];
    }

    public function setAnswerRawAttribute(?string $value): void
    {
        if (filled($value)) {
            $this->attributes['answer_hash'] = static::normalizeAndHash($value);
        }
    }

    public static function normalizeAndHash(string $answer): string
    {
        // نطبع الجواب (نشيل الفراغات والتشكيل ونوحد الحالة) قبل التجزئة
        // حتى ما يفشل المستخدم بسبب فراغ زائد أو حركة إعرابية.
        $normalized = trim(mb_strtolower($answer));
        $normalized = preg_replace('/[\x{064B}-\x{0652}]/u', '', $normalized); // إزالة التشكيل العربي
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

    public function attempts(): HasMany
    {
        return $this->hasMany(PuzzleAttempt::class);
    }

    public function challenges(): BelongsToMany
    {
        return $this->belongsToMany(Challenge::class, 'challenge_puzzle');
    }
}
