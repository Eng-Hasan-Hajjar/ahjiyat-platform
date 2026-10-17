<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use App\Models\SupporterTier;
use App\Services\OperationalAuditService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSupporterTier extends CreateRecord
{
    protected static string $resource = SupporterTierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $data['minimum_accounting_amount_minor'] = SponsorshipMoney::parse($data['minimum_decimal'], SponsorshipMoney::accountingCurrency());
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['minimum_decimal' => $e->getMessage()]);
        }

        unset($data['minimum_decimal']);
        $tier = SupporterTier::query()->create($data);
        app(OperationalAuditService::class)->log('supporter_tier_saved', $tier, ['slug' => $tier->slug, 'minimum' => $tier->minimum_accounting_amount_minor, 'active' => $tier->is_active], auth()->user());

        return $tier;
    }
}
