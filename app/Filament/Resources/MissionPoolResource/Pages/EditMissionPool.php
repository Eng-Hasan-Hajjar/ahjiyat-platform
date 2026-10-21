<?php

namespace App\Filament\Resources\MissionPoolResource\Pages;

use App\Filament\Resources\MissionPoolResource;
use App\Services\LiveOps\LiveOpsException;
use App\Services\LiveOps\MissionPoolService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditMissionPool extends EditRecord
{
    protected static string $resource = MissionPoolResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(MissionPoolService::class)->update(auth()->user(), $record, $data);
        } catch (LiveOpsException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
