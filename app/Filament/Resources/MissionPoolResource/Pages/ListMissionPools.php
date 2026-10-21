<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMissionPools extends ListRecords
{
    protected static string $resource = MissionPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
