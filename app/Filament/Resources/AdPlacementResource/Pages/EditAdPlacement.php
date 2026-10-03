<?php

namespace App\Filament\Resources\AdPlacementResource\Pages;

use App\Filament\Resources\AdPlacementResource;
use Filament\Resources\Pages\EditRecord;

class EditAdPlacement extends EditRecord
{
    protected static string $resource = AdPlacementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
