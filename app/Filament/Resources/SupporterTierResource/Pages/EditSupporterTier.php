<?php

namespace App\Filament\Resources\SupporterTierResource\Pages;

use App\Filament\Resources\SupporterTierResource;
use App\Filament\Resources\SponsorshipProgramResource;
use App\Services\OperationalAuditService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSupporterTier extends EditRecord
{
    protected static string $resource = SupporterTierResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['minimum_decimal'] = SponsorshipProgramResource::decimal((int) $data['minimum_accounting_amount_minor'], SponsorshipMoney::accountingCurrency());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $data['minimum_accounting_amount_minor'] = SponsorshipMoney::parse($data['minimum_decimal'], SponsorshipMoney::accountingCurrency());
        } catch (SponsorshipException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw ValidationException::withMessages(['minimum_decimal' => $e->getMessage()]);
        }

        unset($data['minimum_decimal']);
        $before = $record->only(['name', 'minimum_accounting_amount_minor', 'is_active']);
        $record->update($data);
        app(OperationalAuditService::class)->log('supporter_tier_saved', $record, ['slug' => $record->slug, 'before' => $before, 'after' => $record->only(['name', 'minimum_accounting_amount_minor', 'is_active'])], auth()->user());

        return $record;
    }
}
