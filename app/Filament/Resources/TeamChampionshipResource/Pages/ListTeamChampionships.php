<?php

namespace App\Filament\Resources\TeamChampionshipResource\Pages;

use App\Filament\Resources\TeamChampionshipResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTeamChampionships extends ListRecords
{
    protected static string $resource = TeamChampionshipResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
