<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use App\Services\SeasonReadinessService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSeason extends EditRecord
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('معاينة الموسم')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn () => route('seasons.show', $this->record))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    /** نفس منطق Publish Safety من CreateSeason - تحقّق قبل أي حفظ ينشر الموسم. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['is_published'] ?? false) {
            $readiness = app(SeasonReadinessService::class)->check($this->record);

            if (! $readiness['ready']) {
                $data['is_published'] = false;

                Notification::make()
                    ->warning()
                    ->title('لم يُحفَظ نشر الموسم')
                    ->body('توجد عناصر تحتاج استكمالاً قبل النشر العام: '.implode(' • ', $readiness['issues']))
                    ->persistent()
                    ->send();
            }
        }

        return $data;
    }
}