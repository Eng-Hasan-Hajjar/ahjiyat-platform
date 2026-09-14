<?php

namespace App\Filament\Resources\CampaignGateResource\Pages;

use App\Filament\Resources\CampaignGateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCampaignGate extends EditRecord
{
    protected static string $resource = CampaignGateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}