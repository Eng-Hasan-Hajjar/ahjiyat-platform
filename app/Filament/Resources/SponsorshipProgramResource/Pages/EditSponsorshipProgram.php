<?php

namespace App\Filament\Resources\SponsorshipProgramResource\Pages;

use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipProgramService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSponsorshipProgram extends EditRecord
{
    protected static string $resource = SponsorshipProgramResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['goal_amount'] = ! empty($data['goal_amount_minor']) ? SponsorshipProgramResource::decimal((int) $data['goal_amount_minor'], (string) $data['accounting_currency']) : null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(SponsorshipProgramService::class)->update(auth()->user(), $record, $data);
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }
    }
}
