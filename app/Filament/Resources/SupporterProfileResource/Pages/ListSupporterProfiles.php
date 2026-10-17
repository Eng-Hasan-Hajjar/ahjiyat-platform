<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupporterProfiles extends ListRecords
{
    protected static string $resource = SupporterProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
