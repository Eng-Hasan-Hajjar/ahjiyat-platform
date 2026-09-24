<?php

namespace App\Filament\Resources\CurrencyPackResource\Pages;

use App\Filament\Resources\CurrencyPackResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCurrencyPack extends EditRecord
{
    protected static string $resource = CurrencyPackResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}