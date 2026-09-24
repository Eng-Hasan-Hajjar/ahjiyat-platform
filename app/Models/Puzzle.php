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

    protected $fillable = [
        'puzzle_category_id', 'title', 'type', 'difficulty', 'prompt',
        'image_path', 'choices', 'answer_hash', 'answer_raw', 'hint', 'max_attempts',
        'time_limit_seconds', 'gem_reward', 'reward_currency_id', 'is_daily_puzzle',
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
            app(GameTypeRegistry::class)->prepareForSave($puzzle);
        });
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