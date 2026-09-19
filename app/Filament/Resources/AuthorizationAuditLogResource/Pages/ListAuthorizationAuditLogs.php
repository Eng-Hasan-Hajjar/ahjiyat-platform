<?php

namespace App\Filament\Resources\AuthorizationAuditLogResource\Pages;

use App\Filament\Resources\AuthorizationAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthorizationAuditLogs extends ListRecords
{
    protected static string $resource = AuthorizationAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}