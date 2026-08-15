<?php

namespace Database\Factories;

use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class PuzzleFactory extends Factory
{
    protected $model = Puzzle::class;

    public function definition(): array
    {
        return [
            'puzzle_category_id' => PuzzleCategory::factory(),
            'title' => fake()->sentence(3),
            'type' => 'text',
            'difficulty' => 'easy',
            'prompt' => fake()->sentence(10).'؟',
            'answer_raw' => 'الجواب الصحيح',
            'max_attempts' => 3,
            'gem_reward' => 5,
            'is_active' => true,
        ];
    }
}
