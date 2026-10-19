<?php

namespace App\Filament\Resources\ReferralCampaignResource\Pages;

use App\Filament\Resources\ReferralCampaignResource;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateReferralCampaign extends CreateRecord
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ReferralCampaignService::class)->create(auth()->user(), $data);
        } catch (ReferralException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
