<?php

namespace App\Filament\Resources\CampaignStageResource\Pages;

use App\Filament\Resources\CampaignStageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCampaignStage extends EditRecord
{
    protected static string $resource = CampaignStageResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}