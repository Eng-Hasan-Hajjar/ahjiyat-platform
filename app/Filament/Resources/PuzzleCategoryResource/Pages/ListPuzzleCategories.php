<?php

namespace App\Filament\Resources\PuzzleCategoryResource\Pages;

use App\Filament\Resources\PuzzleCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPuzzleCategories extends ListRecords
{
    protected static string $resource = PuzzleCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
