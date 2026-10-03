<?php

namespace App\Services\Advertising\Providers;

/** عقد موحَّد لكل مُزوِّد - Direct والخارجي كلاهما يُطبِّقانه بالتساوي. */
interface AdProviderContract
{
    public function isConfigured(): bool;

    public function selectFor(string $placementInternalKey): AdRenderResult;
}