<?php

namespace Database\Factories;

use App\Models\StoreItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreItemFactory extends Factory
{
    protected $model = StoreItem::class;

    public function definition(): array
    {
        $name = 'عنصر '.$this->faker->unique()->word();

        return [
            'sku' => 'ITEM-'.strtoupper($this->faker->unique()->bothify('####??')),
            'slug' => str()->slug($name).'-'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'is_active' => true,
            'is_featured' => false,
            'grant_quantity' => 1,
            'sort_order' => 0,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn () => ['item_type' => StoreItem::TYPE_PHYSICAL, 'fulfillment_type' => StoreItem::FULFILLMENT_MANUAL]);
    }

    public function entitlement(string $key = null): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_ACCESS,
            'fulfillment_type' => StoreItem::FULFILLMENT_ENTITLEMENT,
            'entitlement_key' => $key ?? 'test.entitlement.'.$this->faker->unique()->word(),
        ]);
    }

    public function cosmeticAvatar(): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_AVATAR,
        ]);
    }

    public function cosmeticFrame(): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_FRAME,
        ]);
    }

    public function cosmeticBadge(): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_BADGE,
        ]);
    }

    public function cosmeticTitle(?string $text = null, ?string $color = null): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_TITLE,
            'cosmetic_text' => $text ?? 'المحقق',
            'cosmetic_color' => $color,
        ]);
    }

    public function cosmeticBackground(): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => StoreItem::SLOT_BACKGROUND,
        ]);
    }

    public function legacyCosmeticWithoutSlot(): static
    {
        return $this->state(fn () => [
            'item_type' => StoreItem::TYPE_COSMETIC,
            'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY,
            'cosmetic_slot' => null,
        ]);
    }
}