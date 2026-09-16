<?php

namespace App\Filament\Resources\PuzzleResource\Pages;

use App\Filament\Resources\PuzzleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPuzzle extends EditRecord
{
    protected static string $resource = PuzzleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // معاينة عامة لأي أحجية (كلاسيكية أو أي game_type) - تفتح نفس صفحة
            // اللاعب الفعلية عبر المسار العام puzzles.show الموجود أصلاً. ليست
            // خاصة بـSpot Difference ولا تكرر أي Renderer.
            Actions\Action::make('preview')
                ->label('معاينة الأحجية')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn () => route('puzzles.show', $this->record))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }
}