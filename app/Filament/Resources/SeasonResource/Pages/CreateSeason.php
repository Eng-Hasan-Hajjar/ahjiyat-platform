<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use App\Models\Campaign;
use App\Models\Season;
use App\Services\SeasonReadinessService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSeason extends CreateRecord
{
    protected static string $resource = SeasonResource::class;

    /**
     * Publish Safety: لا نسمح بحفظ is_published=true إذا كانت الجاهزية غير
     * مكتملة (Blocking Technical Placeholder، بنية ناقصة...) - نُعيدها false
     * ونُنبّه Admin بدل حجب الحفظ بالكامل (Admin Preview يبقى يعمل دائماً).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['is_published'] ?? false) && ($data['campaign_id'] ?? null)) {
            $tempSeason = new Season($data);
            $tempSeason->setRelation('campaign', Campaign::find($data['campaign_id']));

            $readiness = app(SeasonReadinessService::class)->check($tempSeason);

            if (! $readiness['ready']) {
                $data['is_published'] = false;

                Notification::make()
                    ->warning()
                    ->title('لم يُنشَر الموسم بعد')
                    ->body('توجد عناصر تحتاج استكمالاً قبل النشر العام: '.implode(' • ', $readiness['issues']))
                    ->persistent()
                    ->send();
            }
        }

        return $data;
    }
}