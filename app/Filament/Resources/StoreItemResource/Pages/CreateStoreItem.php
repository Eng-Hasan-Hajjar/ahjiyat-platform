<?php

namespace App\Filament\Resources\StoreItemResource\Pages;

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Resources\StoreItemResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStoreItem extends CreateRecord
{
    protected static string $resource = StoreItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** E11.1: خرق قاعدة نطاق يظهر كإشعار مفهوم بدل صفحة خطأ - السلطة تبقى للحارس المركزي. */
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