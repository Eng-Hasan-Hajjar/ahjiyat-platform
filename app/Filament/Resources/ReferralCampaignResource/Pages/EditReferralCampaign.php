<?php

namespace App\Filament\Resources\ReferralCampaignResource\Pages;

use App\Filament\Resources\ReferralCampaignResource;
use App\Services\Referrals\ReferralCampaignService;
use App\Services\Referrals\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReferralCampaign extends EditRecord
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(ReferralCampaignService::class)->update(auth()->user(), $record, $data);
        } catch (ReferralException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
    }
}
