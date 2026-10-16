<?php

namespace App\Filament\Resources\CreatorPuzzleRevisionResource\Pages;

use App\Filament\Resources\CreatorPuzzleRevisionResource;
use App\Filament\Widgets\CreatorEcosystemStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListCreatorPuzzleRevisions extends ListRecords
{
    protected static string $resource = CreatorPuzzleRevisionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [CreatorEcosystemStatsWidget::class];
    }
}
