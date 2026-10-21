<?php

namespace App\Filament\Resources\UserCollectionResource\Pages;

use App\Filament\Resources\UserCollectionResource;
use Filament\Resources\Pages\ListRecords;

class ListUserCollections extends ListRecords
{
    protected static string $resource = UserCollectionResource::class;
}
