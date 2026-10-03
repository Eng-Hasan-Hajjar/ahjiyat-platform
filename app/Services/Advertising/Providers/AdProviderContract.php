<?php

namespace App\Services\Advertising\Providers;

/** E14 (بند 589): عقد موحَّد لكل مُزوِّد - Direct والخارجي كلاهما يُطبِّقانه بالتساوي. */
interface AdProviderContract
{
    public function isConfigured(): bool;

    public function selectFor(string $placementInternalKey): AdRenderResult;
}
