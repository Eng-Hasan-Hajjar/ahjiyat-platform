<?php

namespace Database\Factories;

use App\Models\LevelDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

class LevelDefinitionFactory extends Factory
{
    protected $model = LevelDefinition::class;

    public function definition(): array
    {
        $levelNumber = $this->faker->unique()->numberBetween(2, 100000);

        return [
            'level_number' => $levelNumber,
            'name' => "مستوى تجريبي {$levelNumber}",
            'xp_required_total' => $levelNumber * 100,
            'is_active' => true,
            'sort_order' => $levelNumber,
        ];
    }

    public function first(): static
    {
        return $this->state(fn () => ['level_number' => 1, 'name' => 'المستوى الأول', 'xp_required_total' => 0]);
    }
}