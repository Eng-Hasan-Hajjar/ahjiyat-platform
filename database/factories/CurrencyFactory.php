<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyFactory extends Factory
{
    public function definition(): array
    {
        $key = 'test-'.$this->faker->unique()->slug(2);

        return [
            'internal_key' => $key,
            'code' => strtoupper($this->faker->lexify('???')),
            'name' => 'عملة اختبار '.$this->faker->word(),
            'type' => Currency::TYPE_EVENT,
            'is_active' => true,
            'is_earnable' => true,
            'is_spendable' => true,
            'is_purchasable' => false,
            'is_redeemable' => false,
            'is_system' => false,
            'sort_order' => 10,
        ];
    }

    public function premium(): static
    {
        return $this->state(fn () => [
            'type' => Currency::TYPE_PREMIUM,
            'is_earnable' => false,
            'is_spendable' => false,
            'is_purchasable' => true,
            'is_redeemable' => false,
        ]);
    }
}