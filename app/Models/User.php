<?php

namespace App\Models;

use App\GameEngine\Support\AttemptContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail, FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_MEMBERS = 'members';
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_MEMBERS, self::VISIBILITY_PUBLIC];

    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_visibility',
    ];

    protected $attributes = [
        'profile_visibility' => self::VISIBILITY_PRIVATE,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->public_id ??= (string) \Illuminate\Support\Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_frozen' => 'boolean',
            'last_seen_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('admin.access');
    }

    public function wallets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        $currencyId = app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency()->id;

        return $this->hasOne(Wallet::class)->where('currency_id', $currencyId);
    }

    public function gemTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GemTransaction::class);
    }

    public function currencyTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CurrencyTransaction::class);
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

    public function deviceSightings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeviceSighting::class);
    }

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Session::class);
    }

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

    public function frozenBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'frozen_by');
    }

    public function storePurchases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StorePurchase::class);
    }

    public function inventoryItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserInventoryItem::class);
    }

    public function inventoryTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function cosmeticLoadouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserCosmeticLoadout::class);
    }

    public function isProfilePublic(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_PUBLIC;
    }

    public function isProfileMembersOnly(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_MEMBERS;
    }

    public function isProfilePrivate(): bool
    {
        return $this->profile_visibility === self::VISIBILITY_PRIVATE;
    }

    public function profileViewableBy(?User $viewer): bool
    {
        if ($viewer && $viewer->id === $this->id) {
            return true;
        }

        return match ($this->profile_visibility) {
            self::VISIBILITY_PUBLIC => true,
            self::VISIBILITY_MEMBERS => $viewer !== null,
            default => false,
        };
    }

    public function playerProgression(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PlayerProgression::class);
    }

    public function xpTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(XpTransaction::class);
    }

    public function levelUnlocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserLevelUnlock::class);
    }

    public function achievementProgress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserAchievementProgress::class);
    }
}