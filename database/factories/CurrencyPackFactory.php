<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyPackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => 'pack-'.$this->faker->unique()->numerify('####'),
            'currency_id' => Currency::factory()->premium(),
            'name' => 'حزمة '.$this->faker->word(),
            'base_amount' => 500,
            'bonus_amount' => 0,
            'price_minor' => 999,
            'fiat_currency' => 'USD',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}