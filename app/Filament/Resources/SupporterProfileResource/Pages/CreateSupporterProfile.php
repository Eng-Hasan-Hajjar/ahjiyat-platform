<?php

namespace App\Filament\Resources\SupporterProfileResource\Pages;

use App\Filament\Resources\SupporterProfileResource;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SupporterService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSupporterProfile extends CreateRecord
{
    protected static string $resource = SupporterProfileResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $logo = SupporterProfileResource::extractLogo($data['logo'] ?? null);
        unset($data['logo'], $data['remove_logo']);

        try {
            return app(SupporterService::class)->create(auth()->user(), $data, $logo);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['display_name' => $e->getMessage()]);
        }
    }
}
