<?php

namespace App\Services\Advertising\Providers;

/**
 * E14 (بند 41/585): القيمة المُعادة الموحَّدة من AdServingService - الواجهة
 * (<x-ad-slot>) لا تعرف شيئًا عن Direct أو Google، تتعامل فقط مع هذا الشكل.
 * hasAd=false يعني ببساطة "لا تعرض شيئًا" بكل الحالات (معطَّل، لا حملة
 * مؤهَّلة، فشل مزوِّد خارجي...) - التمييز الداخلي لا يصل للواجهة إطلاقًا.
 */
class AdRenderResult
{
    private function __construct(
        public readonly bool $hasAd,
        public readonly string $providerType = 'none',
        public readonly ?int $placementId = null,
        public readonly ?int $campaignId = null,
        public readonly ?int $creativeId = null,
        public readonly ?string $title = null,
        public readonly ?string $body = null,
        public readonly ?string $ctaLabel = null,
        public readonly ?string $imagePath = null,
        public readonly ?string $altText = null,
        public readonly ?string $clickUrl = null,
        public readonly ?string $externalSlotId = null,
    ) {}

    public static function none(): self
    {
        return new self(hasAd: false);
    }

    public static function direct(
        int $placementId, int $campaignId, int $creativeId,
        string $title, ?string $body, ?string $ctaLabel,
        ?string $imagePath, ?string $altText, string $clickUrl,
    ): self {
        return new self(
            hasAd: true, providerType: 'direct',
            placementId: $placementId, campaignId: $campaignId, creativeId: $creativeId,
            title: $title, body: $body, ctaLabel: $ctaLabel,
            imagePath: $imagePath, altText: $altText, clickUrl: $clickUrl,
        );
    }

    public static function external(int $placementId, string $externalSlotId): self
    {
        return new self(hasAd: true, providerType: 'external', placementId: $placementId, externalSlotId: $externalSlotId);
    }
}
