<?php

namespace Database\Factories;

use App\Models\StoreItem;
use App\Models\User;
use App\Models\UserCosmeticLoadout;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserCosmeticLoadoutFactory extends Factory
{
    protected $model = UserCosmeticLoadout::class;

    public function definition(): array
    {
        $item = StoreItem::factory()->cosmeticAvatar()->create();

        return [
            'user_id' => User::factory(),
            'slot' => $item->cosmetic_slot,
            'store_item_id' => $item->id,
            'equipped_at' => now(),
        ];
    }
}