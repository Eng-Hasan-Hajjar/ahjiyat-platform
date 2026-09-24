<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\CurrencyPack;
use Illuminate\Database\Seeder;

class EconomySeeder extends Seeder
{
    public function run(): void
    {
        Currency::updateOrCreate(
            ['internal_key' => 'ramadan-2027-demo'],
            [
                'code' => 'RMD', 'name' => 'هلالات رمضان (تجريبي)', 'type' => Currency::TYPE_EVENT,
                'is_active' => true, 'is_earnable' => true, 'is_spendable' => true,
                'is_purchasable' => false, 'is_redeemable' => false, 'is_system' => false,
                'sort_order' => 10,
            ]
        );

        CurrencyPack::updateOrCreate(
            ['sku' => 'premium-pack-small-demo'],
            [
                'currency_id' => Currency::where('internal_key', 'platform-premium')->value('id'),
                'name' => 'حزمة صغيرة (تجريبي)', 'base_amount' => 500, 'bonus_amount' => 0,
                'price_minor' => 499, 'fiat_currency' => 'USD', 'is_active' => true, 'sort_order' => 1,
            ]
        );

        CurrencyPack::updateOrCreate(
            ['sku' => 'premium-pack-large-demo'],
            [
                'currency_id' => Currency::where('internal_key', 'platform-premium')->value('id'),
                'name' => 'حزمة كبيرة + مكافأة (تجريبي)', 'base_amount' => 2000, 'bonus_amount' => 300,
                'price_minor' => 1499, 'fiat_currency' => 'USD', 'is_active' => true, 'sort_order' => 2,
            ]
        );
    }
}