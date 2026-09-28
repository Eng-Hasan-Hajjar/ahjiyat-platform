<?php

namespace App\Filament\Resources\StoreItemResource\Pages;

use App\Exceptions\StoreItemInvariantViolation;
use App\Filament\Resources\StoreItemResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStoreItem extends EditRecord
{
    protected static string $resource = StoreItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn () => ! $this->record->isProtected())];
    }

    /** E11.1: خرق قاعدة نطاق يظهر كإشعار مفهوم بدل صفحة خطأ - السلطة تبقى للحارس المركزي. */
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