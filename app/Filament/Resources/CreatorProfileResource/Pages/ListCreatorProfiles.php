<?php

namespace App\Filament\Resources\CreatorProfileResource\Pages;

use App\Filament\Resources\CreatorProfileResource;
use App\Filament\Widgets\CreatorEcosystemStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListCreatorProfiles extends ListRecords
{
    protected static string $resource = CreatorProfileResource::class;

    protected function getHeaderWidgets(): array
    {
        return [CreatorEcosystemStatsWidget::class];
    }
}
