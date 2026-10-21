<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionPoolService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMissionPool extends CreateRecord
{
    protected static string $resource = MissionPoolResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(MissionPoolService::class)->create(auth()->user(), $data);
        } catch (LiveOpsException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
