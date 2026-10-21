<?php

namespace App\Filament\Resources\EditorialShelfResource\Pages;

use App\Filament\Resources\EditorialShelfResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEditorialShelves extends ListRecords
{
    protected static string $resource = EditorialShelfResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
