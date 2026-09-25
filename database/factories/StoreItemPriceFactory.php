<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreItemPriceFactory extends Factory
{
    protected $model = StoreItemPrice::class;

    public function definition(): array
    {
        return [
            'store_item_id' => StoreItem::factory(),
            'currency_id' => fn () => app(CurrencyRegistry::class)->defaultEarnedCurrency()->id,
            'amount' => 100,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}