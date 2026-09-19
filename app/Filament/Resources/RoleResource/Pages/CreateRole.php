<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuthorizationAuditService;
use App\Services\AuthorizationSafetyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';
        unset($data['permissions_matrix']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $permissions = $this->data['permissions_matrix'] ?? [];
        $actor = Auth::user();

        if (! empty($permissions)) {
            app(AuthorizationSafetyService::class)->assertCanSyncRolePermissions($actor, $this->record, $permissions);
            $this->record->syncPermissions($permissions);
        }

        app(AuthorizationAuditService::class)->log('role_created', $this->record, [
            'name' => $this->record->name,
            'permissions' => $permissions,
        ]);
    }
}