<?php

namespace App\Filament\Resources\SponsorshipContributionResource\Pages;

use App\Filament\Resources\SponsorshipContributionResource;
use App\Filament\Widgets\SponsorshipStatsWidget;
use App\Models\SponsorshipProgram;
use App\Models\SupporterProfile;
use App\Services\Sponsorship\ContributionService;
use App\Services\Sponsorship\SponsorshipException;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSponsorshipContributions extends ListRecords
{
    protected static string $resource = SponsorshipContributionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SponsorshipStatsWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('record')->label('تسجيل مساهمة')->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->can('sponsorships.manage') ?? false)
                ->modalDescription('تُسجَّل المساهمة «معلّقة» ولا تُحتسب حتى يؤكّدها مسؤول مالي. لا تُدخل أرقام بطاقات أو حسابات بنكية.')
                ->form([
                    Forms\Components\Select::make('supporter_id')->label('الداعم')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => SupporterProfile::query()->where('status', SupporterProfile::STATUS_ACTIVE)->where('display_name', 'like', '%'.addcslashes($search, '%_\\').'%')->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => SupporterProfile::query()->whereKey($value)->value('display_name')),
                    Forms\Components\Select::make('program_id')->label('البرنامج')->required()
                        ->options(fn () => SponsorshipProgram::query()->whereIn('status', [SponsorshipProgram::STATUS_DRAFT, SponsorshipProgram::STATUS_ACTIVE])->orderByDesc('id')->limit(100)->pluck('title', 'id')->all()),
                    Forms\Components\TextInput::make('amount')->label('المبلغ')->required()->maxLength(20),
                    Forms\Components\Select::make('currency')->label('العملة')->required()->options(SponsorshipMoney::options())->default(SponsorshipMoney::accountingCurrency()),
                    Forms\Components\Toggle::make('is_anonymous')->label('مجهولة (لا تُنسب علنًا)'),
                    Forms\Components\TextInput::make('reference')->label('مرجع داخلي قصير (اختياري)')->maxLength((int) config('sponsorship.profile.reference_max')),
                    Forms\Components\Textarea::make('notes')->label('ملاحظات إدارية خاصة (لا تُعرض علنًا)')->rows(2)->maxLength((int) config('sponsorship.profile.notes_max')),
                ])
                ->action(function (array $data) {
                    try {
                        app(ContributionService::class)->record(
                            auth()->user(),
                            SupporterProfile::query()->findOrFail((int) $data['supporter_id']),
                            SponsorshipProgram::query()->findOrFail((int) $data['program_id']),
                            (string) $data['amount'],
                            (string) $data['currency'],
                            ['is_anonymous' => (bool) ($data['is_anonymous'] ?? false), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null],
                        );
                        Notification::make()->success()->title('سُجّلت المساهمة (معلّقة بانتظار التأكيد).')->send();
                    } catch (SponsorshipException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
