<?php

namespace App\Filament\Resources\StoreItemResource\Pages;

use App\Filament\Resources\StoreItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStoreItems extends ListRecords
{
    protected static string $resource = StoreItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}