<?php

namespace App\Filament\Resources\TeamChampionshipResource\Pages;

use App\Filament\Resources\TeamChampionshipResource;
use App\Services\Teams\TeamChampionshipService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** التعديل بخدمة المجال: المسودة كل الحقول، وبعد النشر العنوان والوصف والتمييز فقط (المواعيد والمعرّف مقفلة). */
class EditTeamChampionship extends EditRecord
{
    protected static string $resource = TeamChampionshipResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(TeamChampionshipService::class)->update(auth()->user(), $record, $data);
    }
}
