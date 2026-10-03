<?php

namespace App\Filament\Resources\SponsorCampaignResource\Pages;

use App\Filament\Resources\SponsorCampaignResource;
use App\Models\SponsorCampaign;
use App\Services\Advertising\SponsorCampaignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** E14 (بند 581): الإنشاء يمرّ عبر الخدمة - لا Model::create() مباشر من الصفحة. */
class CreateSponsorCampaign extends CreateRecord
{
    protected static string $resource = SponsorCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SponsorCampaignService::class)->createDraft($data, auth()->user());
    }
}
