<?php

namespace App\Filament\Resources\StorePurchaseResource\Pages;

use App\Filament\Resources\StorePurchaseResource;
use Filament\Resources\Pages\ListRecords;

class ListStorePurchases extends ListRecords
{
    protected static string $resource = StorePurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}