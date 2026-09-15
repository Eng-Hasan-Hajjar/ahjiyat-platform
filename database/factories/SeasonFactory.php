<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'code' => 'S'.fake()->unique()->numberBetween(1, 99),
            'slug' => fake()->unique()->slug(),
            'is_published' => false,
            'is_featured' => false,
        ];
    }
}