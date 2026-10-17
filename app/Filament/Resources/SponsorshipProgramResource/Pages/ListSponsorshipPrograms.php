<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Filament\Widgets\SponsorshipStatsWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipPrograms extends ListRecords
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [SponsorshipStatsWidget::class];
    }
}
