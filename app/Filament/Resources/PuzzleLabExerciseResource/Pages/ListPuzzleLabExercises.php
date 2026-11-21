<?php

namespace App\Filament\Resources\PuzzleLabExerciseResource\Pages;

use App\Filament\Resources\PuzzleLabExerciseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPuzzleLabExercises extends ListRecords
{
    protected static string $resource = PuzzleLabExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('تمرين جديد')];
    }
}
