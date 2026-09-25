<?php

namespace App\Filament\Resources\StorePurchaseResource\Pages;

use App\Filament\Resources\StorePurchaseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStorePurchase extends ViewRecord
{
    protected static string $resource = StorePurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}