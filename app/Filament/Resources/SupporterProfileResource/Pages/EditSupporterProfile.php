<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSupporterProfile extends EditRecord
{
    protected static string $resource = SupporterProfileResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['logo_path']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $logo = SupporterProfileResource::extractLogo($data['logo'] ?? null);
        $remove = (bool) ($data['remove_logo'] ?? false);
        unset($data['logo'], $data['remove_logo']);

        try {
            return app(SupporterService::class)->update(auth()->user(), $record, $data, $logo, $remove);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['display_name' => $e->getMessage()]);
        }
    }
}
