<?php

namespace App\Models;

use App\GameEngine\Support\AttemptContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_frozen' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // Filament يستخدم هذه لتحديد من يقدر يدخل لوحة الإدارة
    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function gemTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GemTransaction::class);
    }

    public function puzzleAttempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PuzzleAttempt::class);
    }

    public function redemptionRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RedemptionRequest::class);
    }

    public function fraudFlags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FraudFlag::class);
    }

    /**
     * افتراضياً يفحص الحل المستقل (Standalone) فقط. تمرير Context (لاحقاً من
     * خطوة حملة/تحدٍّ راعٍ) يفحص الحل ضمن ذاك السياق حصراً - حل مستقل لا
     * يُعتبر أبداً حلاً لسياق آخر، والعكس صحيح.
     */
    public function hasSolvedPuzzle(Puzzle $puzzle, ?AttemptContext $context = null): bool
    {
        $context ??= AttemptContext::none();

        return $this->puzzleAttempts()
            ->where('puzzle_id', $puzzle->id)
            ->where('is_correct', true)
            ->where('context_type', $context->type)
            ->where('context_id', $context->id)
            ->exists();
    }



        public function campaignProgress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserCampaignProgress::class);
    }

    public function campaignQualifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignGateQualification::class);
    }

    public function createdCampaigns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Campaign::class, 'created_by');
    }



}