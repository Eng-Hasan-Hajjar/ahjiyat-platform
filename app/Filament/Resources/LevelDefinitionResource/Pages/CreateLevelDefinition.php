<?php

namespace App\Filament\Resources\LevelDefinitionResource\Pages;

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Resources\LevelDefinitionResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLevelDefinition extends CreateRecord
{
    protected static string $resource = LevelDefinitionResource::class;

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