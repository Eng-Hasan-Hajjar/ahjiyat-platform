<?php

namespace Database\Factories;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserInventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserInventoryItemFactory extends Factory
{
    protected $model = UserInventoryItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_item_id' => StoreItem::factory(),
            'quantity' => 1,
        ];
    }
}