<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AuthorizationAuditService;
use App\Services\AuthorizationSafetyService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['direct_permissions_matrix'] = $this->record->getDirectPermissions()->pluck('name')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['direct_permissions_matrix']);

        return $data;
    }

    protected function afterSave(): void
    {
        $actor = Auth::user();
        $newDirect = $this->data['direct_permissions_matrix'] ?? [];
        $beforeDirect = $this->record->getDirectPermissions()->pluck('name')->all();

        app(AuthorizationSafetyService::class)->assertCanModifyUserAuthorization($actor, $this->record);

        if (! app(AuthorizationSafetyService::class)->isSuperAdmin($actor)) {
            foreach ($newDirect as $permissionName) {
                abort_unless($actor->can($permissionName), 403, "لا يمكنك منح صلاحية (\"{$permissionName}\") لا تملكها أنت نفسك.");
            }
        }

        $this->record->syncPermissions($newDirect);

        $added = array_values(array_diff($newDirect, $beforeDirect));
        $removed = array_values(array_diff($beforeDirect, $newDirect));

        if ($added || $removed) {
            app(AuthorizationAuditService::class)->log('user_direct_permissions_updated', $this->record, [
                'added_permissions' => $added,
                'removed_permissions' => $removed,
            ]);
        }
    }
}