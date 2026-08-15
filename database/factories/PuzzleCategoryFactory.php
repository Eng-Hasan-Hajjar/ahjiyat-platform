<?php

namespace Database\Factories;

use App\Models\PuzzleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class PuzzleCategoryFactory extends Factory
{
    protected $model = PuzzleCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'is_active' => true,
        ];
    }
}
