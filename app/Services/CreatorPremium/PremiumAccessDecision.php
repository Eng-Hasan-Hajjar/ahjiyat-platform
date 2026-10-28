<?php

namespace App\Services\CreatorPremium;

/** E43 - قرار وصول (قيمة غير قابلة للتغيير). basis للمسموح: free | creator | staff | owner | grandfathered ؛ reason للمرفوض: login_required | premium_locked | preview_only. */
final class PremiumAccessDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $basis,
        public readonly bool $premium,
    ) {}

    public static function allow(string $basis, bool $premium = true): self
    {
        return new self(true, $basis, $premium);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason, true);
    }

    /** الأحجية مجانية (لا قفل). */
    public function isFree(): bool
    {
        return $this->allowed && $this->basis === CreatorPremiumAccess::BASIS_FREE;
    }

    /** المشاهد يستعرض بحق غير الشراء (مبدع/مشرف) — لتنبيهه بأنها معاينة. */
    public function isPreview(): bool
    {
        return $this->allowed && in_array($this->basis, [CreatorPremiumAccess::BASIS_CREATOR, CreatorPremiumAccess::BASIS_STAFF], true);
    }
}
