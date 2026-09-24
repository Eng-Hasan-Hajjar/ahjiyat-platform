<?php

namespace App\Filament\Resources\CurrencyResource\Pages;

use App\Filament\Resources\CurrencyResource;
use App\Services\Economy\CurrencyRegistry;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn () => ! $this->record->isProtected())];
    }

    protected function afterSave(): void
    {
        app(CurrencyRegistry::class)->forgetCache($this->record->internal_key);
    }
}