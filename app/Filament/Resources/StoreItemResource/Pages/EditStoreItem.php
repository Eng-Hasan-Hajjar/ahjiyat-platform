<?php

namespace App\Filament\Resources\StoreItemResource\Pages;

use App\Filament\Resources\StoreItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStoreItem extends EditRecord
{
    protected static string $resource = StoreItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn () => ! $this->record->isProtected())];
    }
}