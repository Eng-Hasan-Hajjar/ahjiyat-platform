<?php

namespace App\Models;

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

    public function hasSolvedPuzzle(Puzzle $puzzle): bool
    {
        return $this->puzzleAttempts()
            ->where('puzzle_id', $puzzle->id)
            ->where('is_correct', true)
            ->exists();
    }
}
