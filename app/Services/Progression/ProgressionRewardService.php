<?php

namespace App\Services\Progression;

use App\Models\Achievement;
use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\LevelDefinition;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Store\EntitlementService;
use App\Services\Store\InventoryService;
use Illuminate\Database\Eloquent\Model;

class ProgressionRewardService
{
    public function __construct(
        protected CurrencyWalletService $wallets,
        protected InventoryService $inventory,
        protected EntitlementService $entitlements,
    ) {}

    public function grantAchievementRewards(Achievement $achievement, User $user, Model $reference): void
    {
        $this->grantCurrencyIfConfigured(
            $achievement->reward_currency_id,
            $achievement->reward_currency_amount,
            $user,
            "achievement:{$achievement->internal_key}",
            $reference,
        );

        $this->grantItemIfConfigured(
            $achievement->reward_store_item_id,
            $achievement->reward_item_quantity,
            $user,
            "achievement:{$achievement->internal_key}",
            $reference,
        );
    }

    public function grantLevelRewards(LevelDefinition $level, User $user, Model $reference): void
    {
        $this->grantCurrencyIfConfigured(
            $level->reward_currency_id,
            $level->reward_currency_amount,
            $user,
            "level:{$level->level_number}",
            $reference,
        );

        $this->grantItemIfConfigured(
            $level->reward_store_item_id,
            $level->reward_item_quantity,
            $user,
            "level:{$level->level_number}",
            $reference,
        );
    }

    protected function grantCurrencyIfConfigured(?int $currencyId, ?int $amount, User $user, string $reason, Model $reference): void
    {
        if ($currencyId === null || blank($amount) || $amount <= 0) {
            return;
        }

        $currency = Currency::find($currencyId);

        if ($currency === null) {
            return;
        }

        $this->wallets->creditAvailable($user, $currency, $amount, $reason, $reference, CurrencyTransaction::TYPE_PROGRESSION_REWARD);
    }

    protected function grantItemIfConfigured(?int $storeItemId, ?int $quantity, User $user, string $reason, Model $reference): void
    {
        if ($storeItemId === null) {
            return;
        }

        $item = StoreItem::find($storeItemId);

        if ($item === null) {
            return;
        }

        match ($item->fulfillment_type) {
            StoreItem::FULFILLMENT_INVENTORY => $this->inventory->grant($user, $item, $quantity ?? 1, $reason, $reference),
            StoreItem::FULFILLMENT_ENTITLEMENT => $this->entitlements->grant($user, $item),
            default => throw new \RuntimeException('لا يمكن منح عنصر يدوي التسليم كمكافأة تلقائية.'),
        };
    }
}