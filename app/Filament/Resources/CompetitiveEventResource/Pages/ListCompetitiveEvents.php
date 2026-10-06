<?php

namespace App\Filament\Resources\CompetitiveEventResource\Pages;

use App\Filament\Resources\CompetitiveEventResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCompetitiveEvents extends ListRecords
{
    protected static string $resource = CompetitiveEventResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
