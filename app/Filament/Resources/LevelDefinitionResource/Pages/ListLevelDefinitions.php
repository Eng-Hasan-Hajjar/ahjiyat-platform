<?php

namespace App\Filament\Resources\LevelDefinitionResource\Pages;

use App\Filament\Resources\LevelDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLevelDefinitions extends ListRecords
{
    protected static string $resource = LevelDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}