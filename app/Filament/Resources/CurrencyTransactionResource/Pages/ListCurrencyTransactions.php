<?php

namespace App\Filament\Resources\CurrencyTransactionResource\Pages;

use App\Filament\Resources\CurrencyTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListCurrencyTransactions extends ListRecords
{
    protected static string $resource = CurrencyTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}