<?php

namespace Database\Factories;

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StorePurchaseFactory extends Factory
{
    protected $model = StorePurchase::class;

    public function definition(): array
    {
        $item = StoreItem::factory()->create();
        $price = StoreItemPrice::factory()->create(['store_item_id' => $item->id]);

        return [
            'user_id' => User::factory(),
            'store_item_id' => $item->id,
            'store_item_price_id' => $price->id,
            'currency_id' => $price->currency_id,
            'price_amount' => $price->amount,
            'status' => StorePurchase::STATUS_FULFILLED,
            'request_key' => (string) Str::uuid(),
            'item_snapshot' => [
                'name' => $item->name, 'sku' => $item->sku, 'item_type' => $item->item_type,
                'fulfillment_type' => $item->fulfillment_type, 'grant_quantity' => $item->grant_quantity,
            ],
            'fulfillment_type' => $item->fulfillment_type,
        ];
    }
}