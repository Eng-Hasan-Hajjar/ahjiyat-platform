<?php

namespace App\Filament\Resources\SponsorCampaignResource\Pages;

use App\Filament\Resources\SponsorCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSponsorCampaigns extends ListRecords
{
    protected static string $resource = SponsorCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
