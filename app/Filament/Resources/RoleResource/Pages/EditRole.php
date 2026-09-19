<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuthorizationAuditService;
use App\Services\AuthorizationSafetyService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions_matrix'] = $this->record->permissions()->pluck('name')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['permissions_matrix']);

        return $data;
    }

    protected function afterSave(): void
    {
        $newPermissions = $this->data['permissions_matrix'] ?? [];
        $actor = Auth::user();
        $before = $this->record->permissions()->pluck('name')->all();

        if ($this->record->name !== 'super-admin') {
            app(AuthorizationSafetyService::class)->assertCanSyncRolePermissions($actor, $this->record, $newPermissions);
            $this->record->syncPermissions($newPermissions);
        }

        $added = array_values(array_diff($newPermissions, $before));
        $removed = array_values(array_diff($before, $newPermissions));

        if ($added || $removed) {
            app(AuthorizationAuditService::class)->log('role_permissions_updated', $this->record, [
                'added_permissions' => $added,
                'removed_permissions' => $removed,
            ]);
        }
    }
}