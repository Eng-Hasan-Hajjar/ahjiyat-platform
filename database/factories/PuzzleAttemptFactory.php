<?php

namespace Database\Factories;

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PuzzleAttemptFactory extends Factory
{
    protected $model = PuzzleAttempt::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'puzzle_id' => Puzzle::factory(),
            'attempt_number' => 1,
            'is_correct' => false,
            'used_hint' => false,
        ];
    }
}