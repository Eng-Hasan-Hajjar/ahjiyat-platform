<?php

namespace App\Services\Economy;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

class CurrencyRegistry
{
    public function defaultEarnedCurrency(): Currency
    {
        return $this->findByKeyOrFail(config('economy.default_earned_currency_key'));
    }

    public function primaryPremiumCurrency(): ?Currency
    {
        $key = config('economy.primary_premium_currency_key');

        return $key ? $this->findByKey($key) : null;
    }

    public function findByKey(string $internalKey): ?Currency
    {
        return Cache::remember(
            "economy:currency:{$internalKey}",
            now()->addMinutes(10),
            fn () => Currency::where('internal_key', $internalKey)->first()
        );
    }

    protected function findByKeyOrFail(string $internalKey): Currency
    {
        $currency = $this->findByKey($internalKey);

        if ($currency === null) {
            throw new \RuntimeException("العملة الأساسية بالمفتاح [{$internalKey}] غير موجودة - راجع Migrations الاقتصاد.");
        }

        return $currency;
    }

    public function forgetCache(string $internalKey): void
    {
        Cache::forget("economy:currency:{$internalKey}");
    }
}