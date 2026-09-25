<?php

namespace App\Services\Store;

use App\Models\StoreItem;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserEntitlement;

class EntitlementService
{
    public function grant(User $user, StoreItem $item, ?StorePurchase $purchase = null): UserEntitlement
    {
        if (blank($item->entitlement_key)) {
            throw new \RuntimeException('هذا العنصر لا يملك مفتاح امتياز (entitlement_key) مُعرَّفاً.');
        }

        $expiresAt = $item->entitlement_duration_days !== null
            ? now()->addDays($item->entitlement_duration_days)
            : null;

        return UserEntitlement::create([
            'user_id' => $user->id,
            'store_item_id' => $item->id,
            'store_purchase_id' => $purchase?->id,
            'key' => $item->entitlement_key,
            'starts_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    public function revoke(UserEntitlement $entitlement, User $actor, string $reason): void
    {
        if ($entitlement->revoked_at !== null) {
            return;
        }

        $entitlement->update([
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revocation_reason' => $reason,
        ]);
    }

    public function hasActive(User $user, string $key): bool
    {
        return $user->entitlements()
            ->where('key', $key)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}