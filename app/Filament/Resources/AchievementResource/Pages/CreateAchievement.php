<?php

namespace App\Filament\Resources\AchievementResource\Pages;

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Resources\AchievementResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAchievement extends CreateRecord
{
    protected static string $resource = AchievementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (StoreItemInvariantViolation $e) {
            Notification::make()->danger()->title('تعذَّر الحفظ')->body($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }
}