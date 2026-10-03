<?php

namespace Database\Factories;

use App\Models\AdPlacement;
use App\Services\Advertising\AdPlacementRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdPlacementFactory extends Factory
{
    protected $model = AdPlacement::class;

    public function definition(): array
    {
        return [
            'internal_key' => AdPlacementRegistry::HOME_INLINE_PRIMARY.'_'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => 'موضع تجريبي',
            'description' => 'وصف تجريبي.',
            'surface' => 'home',
            'position' => 'inline',
            'is_active' => true,
            'desktop_enabled' => true,
            'mobile_enabled' => true,
            'max_ads_per_render' => 1,
            'sort_order' => 0,
        ];
    }

    public function known(string $internalKey): static
    {
        $def = AdPlacementRegistry::definitionFor($internalKey);

        return $this->state(fn () => [
            'internal_key' => $internalKey,
            'name' => $def['name'],
            'surface' => $def['surface'],
            'position' => $def['position'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
