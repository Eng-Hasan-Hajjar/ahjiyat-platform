<?php

namespace App\Filament\Resources\SponsorshipPaymentResource\Pages;

use App\Filament\Resources\SponsorshipPaymentResource;
use App\Filament\Widgets\PaymentStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipPayments extends ListRecords
{
    protected static string $resource = SponsorshipPaymentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [PaymentStatsWidget::class];
    }
}
