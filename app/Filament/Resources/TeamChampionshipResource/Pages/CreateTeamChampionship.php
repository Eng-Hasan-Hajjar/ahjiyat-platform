<?php

namespace App\Filament\Resources\TeamChampionshipResource\Pages;

use App\Filament\Resources\TeamChampionshipResource;
use App\Services\Teams\TeamChampionshipService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** الإنشاء بخدمة المجال (مسودة دائمًا؛ الحالة لا تأتي من النموذج). */
class CreateTeamChampionship extends CreateRecord
{
    protected static string $resource = TeamChampionshipResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TeamChampionshipService::class)->create(auth()->user(), $data);
    }
}
