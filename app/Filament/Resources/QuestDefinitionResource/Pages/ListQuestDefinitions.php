<?php

namespace App\Filament\Resources\QuestDefinitionResource\Pages;

use App\Filament\Resources\QuestDefinitionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQuestDefinitions extends ListRecords
{
    protected static string $resource = QuestDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
