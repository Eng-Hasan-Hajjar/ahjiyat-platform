<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Currency extends Model
{
    use HasFactory;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_PREMIUM = 'premium';
    public const TYPE_EVENT = 'event';

    protected $fillable = [
        'internal_key', 'code', 'name', 'short_name', 'description', 'type',
        'icon_path', 'color', 'is_active', 'is_earnable', 'is_spendable',
        'is_purchasable', 'is_redeemable', 'is_system', 'scope_type', 'scope_id',
        'starts_at', 'ends_at', 'expires_at', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_earnable' => 'boolean',
            'is_spendable' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_redeemable' => 'boolean',
            'is_system' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CurrencyTransaction::class);
    }

    public function packs(): HasMany
    {
        return $this->hasMany(CurrencyPack::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scope(): ?Model
    {
        if ($this->scope_type === null || $this->scope_id === null) {
            return null;
        }

        return match ($this->scope_type) {
            'season' => Season::find($this->scope_id),
            'campaign' => Campaign::find($this->scope_id),
            default => null,
        };
    }

    public function isWithinValidityWindow(): bool
    {
        $now = now();

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function canEarn(): bool
    {
        return $this->is_active && $this->is_earnable && $this->isWithinValidityWindow() && ! $this->hasExpired();
    }

    public function canSpend(): bool
    {
        return $this->is_active && $this->is_spendable && $this->isWithinValidityWindow() && ! $this->hasExpired();
    }

    public function isProtected(): bool
    {
        return $this->is_system
            || $this->wallets()->exists()
            || $this->transactions()->exists()
            || $this->packs()->exists();
    }

    public function displayLabel(): string
    {
        return $this->name.($this->code ? " ({$this->code})" : '');
    }
}