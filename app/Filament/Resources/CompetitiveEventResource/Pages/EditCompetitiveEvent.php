<?php

namespace App\Filament\Resources\CompetitiveEventResource\Pages;

use App\Filament\Resources\CompetitiveEventResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCompetitiveEvent extends EditRecord
{
    protected static string $resource = CompetitiveEventResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
