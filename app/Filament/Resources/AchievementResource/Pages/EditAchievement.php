<?php

namespace App\Filament\Resources\AchievementResource\Pages;

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Resources\AchievementResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAchievement extends EditRecord
{
    protected static string $resource = AchievementResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn () => ! $this->record->isUsed())];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (StoreItemInvariantViolation $e) {
            Notification::make()->danger()->title('تعذَّر الحفظ')->body($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }
}