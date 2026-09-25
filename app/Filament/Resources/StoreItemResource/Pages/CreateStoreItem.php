<?php

namespace App\Filament\Resources\StoreItemResource\Pages;

use App\Filament\Resources\StoreItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStoreItem extends CreateRecord
{
    protected static string $resource = StoreItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}