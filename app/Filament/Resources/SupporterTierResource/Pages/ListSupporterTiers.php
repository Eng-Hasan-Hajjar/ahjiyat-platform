<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupporterTiers extends ListRecords
{
    protected static string $resource = SupporterTierResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
