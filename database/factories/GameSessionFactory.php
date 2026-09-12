<?php

namespace Database\Factories;

use App\Models\GameSession;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionFactory extends Factory
{
    protected $model = GameSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'puzzle_id' => Puzzle::factory(),
            'context_type' => null,
            'context_id' => null,
            'status' => GameSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => null,
            'completed_at' => null,
            'server_state' => ['found_indices' => []],
        ];
    }
}