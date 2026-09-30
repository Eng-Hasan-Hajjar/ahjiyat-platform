<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\XpTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class XpTransactionFactory extends Factory
{
    protected $model = XpTransaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => 10,
            'type' => XpTransaction::TYPE_PUZZLE_SOLVE,
            'reason' => 'test',
            'created_at' => now(),
        ];
    }
}