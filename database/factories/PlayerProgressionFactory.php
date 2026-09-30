<?php

namespace Database\Factories;

use App\Models\PlayerProgression;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayerProgressionFactory extends Factory
{
    protected $model = PlayerProgression::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total_xp' => 0,
            'current_level' => 1,
        ];
    }
}