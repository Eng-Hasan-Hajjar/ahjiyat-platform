<?php

namespace App\Filament\Resources\SponsorCampaignResource\Pages;

use App\Filament\Resources\SponsorCampaignResource;
use App\Services\Advertising\SponsorCampaignService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * E14 (بند 341/580): التحديث يمرّ عبر SponsorCampaignService - حارس إعادة
 * المراجعة عند تغيير sponsor_name على حملة مُعتمَدة يُفرَض هنا فعليًا من
 * طبقة Domain، لا Filament Callback سطحي.
 */
class EditSponsorCampaign extends EditRecord
{
    protected static string $resource = SponsorCampaignResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(SponsorCampaignService::class)->updateCampaignMeta($record, $data, auth()->user());

        return $record->fresh();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
