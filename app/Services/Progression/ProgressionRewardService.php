<?php

namespace App\Services\Progression;

use App\Models\Achievement;
use App\Models\Currency;
use App\Models\LevelDefinition;
use App\Models\QuestDefinition;
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

    /**
     * E13 (بند 9): توسيع لا استبدال - إعادة استخدام كامل لنفس الحارسَين أدناه، بنفس نمط الإنجاز/المستوى حرفيًا.
     * E13.1 (بند 12/14): معامل idempotency key اختياري إضافي - دفاع ثانٍ
     * بجانب قفل الصف بـQuestService، بلا كسر استدعاءات الإنجاز/المستوى
     * (لا تُمرِّره، فتبقى null، سلوكها الحالي دون أي تغيير).
     */
    public function grantQuestRewards(QuestDefinition $quest, User $user, Model $reference, ?string $currencyIdempotencyKey = null): void
    {
        $this->grantCurrencyIfConfigured(
            $quest->reward_currency_id,
            $quest->reward_currency_amount,
            $user,
            "quest:{$quest->internal_key}",
            $reference,
            $currencyIdempotencyKey,
        );

        $this->grantItemIfConfigured(
            $quest->reward_store_item_id,
            $quest->reward_item_quantity,
            $user,
            "quest:{$quest->internal_key}",
            $reference,
        );
    }

    protected function grantCurrencyIfConfigured(?int $currencyId, ?int $amount, User $user, string $reason, Model $reference, ?string $idempotencyKey = null): void
    {
        if ($currencyId === null || blank($amount) || $amount <= 0) {
            return;
        }

        $currency = Currency::find($currencyId);

        if ($currency === null) {
            return;
        }

        $this->wallets->creditPending($user, $currency, $amount, $reason, $reference, $idempotencyKey);
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