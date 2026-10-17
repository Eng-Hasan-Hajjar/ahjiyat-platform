<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSponsorshipProgram extends CreateRecord
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SponsorshipProgramService::class)->create(auth()->user(), $data);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }
    }
}
