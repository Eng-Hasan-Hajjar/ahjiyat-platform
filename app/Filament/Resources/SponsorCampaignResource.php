<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorCampaignResource\Pages;
use App\Filament\Resources\SponsorCampaignResource\RelationManagers;
use App\Models\AdPlacement;
use App\Models\SponsorCampaign;
use App\Services\Advertising\SponsorCampaignService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SponsorCampaignResource extends Resource
{
    protected static ?string $model = SponsorCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'الإعلانات والرعايات';

    protected static ?string $navigationLabel = 'حملات الرعاة';

    protected static ?string $modelLabel = 'حملة راعٍ';

    protected static ?string $pluralModelLabel = 'حملات الرعاة';

    protected static function statusLabel(string $status): string
    {
        return match ($status) {
            SponsorCampaign::STATUS_DRAFT => 'مسودة',
            SponsorCampaign::STATUS_PENDING_REVIEW => 'بانتظار المراجعة',
            SponsorCampaign::STATUS_APPROVED => 'مُعتمَدة',
            SponsorCampaign::STATUS_PAUSED => 'مُوقَفة',
            SponsorCampaign::STATUS_REJECTED => 'مرفوضة',
            default => $status,
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('هوية الراعي والحملة')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('internal_key')->label('المفتاح الداخلي')
                        ->required()->maxLength(100)->unique(ignoreRecord: true)
                        ->disabled(fn (?SponsorCampaign $record) => $record !== null)
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Forms\Components\TextInput::make('sponsor_name')->label('اسم الراعي')->required(),
                    Forms\Components\TextInput::make('campaign_name')->label('اسم الحملة')->required(),
                ]),
            Forms\Components\Section::make('الجدولة والأولوية')
                ->columns(3)
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ في (اختياري)'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي في (اختياري)'),
                    Forms\Components\TextInput::make('priority')->label('الأولوية')->numeric()->minValue(0)->maxValue(100)->default(0),
                ]),
            Forms\Components\Section::make('المواضع المسموح بها')
                ->schema([
                    Forms\Components\CheckboxList::make('placements')
                        ->label('')
                        ->relationship('placements', 'name')
                        ->options(fn () => AdPlacement::pluck('name', 'id'))
                        ->columns(2),
                ]),
            Forms\Components\Section::make('ملاحظات داخلية')
                ->schema([
                    Forms\Components\Textarea::make('notes')->label('ملاحظات (لا تظهر للمستخدم)')->columnSpanFull(),
                    Forms\Components\Textarea::make('review_note')->label('سبب الرفض/ملاحظة المراجعة')->disabled()->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sponsor_name')->label('الراعي')->searchable(),
                Tables\Columns\TextColumn::make('campaign_name')->label('الحملة')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => self::statusLabel($state))
                    ->color(fn ($state) => match ($state) {
                        SponsorCampaign::STATUS_APPROVED => 'success',
                        SponsorCampaign::STATUS_PENDING_REVIEW => 'warning',
                        SponsorCampaign::STATUS_REJECTED => 'danger',
                        SponsorCampaign::STATUS_PAUSED => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('priority')->label('الأولوية')->sortable(),
                Tables\Columns\TextColumn::make('creatives_count')->label('المواد')->counts('creatives'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('submit')
                    ->label('إرسال للمراجعة')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (SponsorCampaign $record) => in_array($record->status, [SponsorCampaign::STATUS_DRAFT, SponsorCampaign::STATUS_REJECTED], true))
                    ->action(function (SponsorCampaign $record) {
                        try {
                            app(SponsorCampaignService::class)->submitForReview($record);
                            Notification::make()->success()->title('أُرسلت الحملة للمراجعة')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('تعذَّر الإرسال')->body($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SponsorCampaign $record) => $record->status === SponsorCampaign::STATUS_PENDING_REVIEW && auth()->user()->can('review', $record))
                    ->action(function (SponsorCampaign $record) {
                        try {
                            app(SponsorCampaignService::class)->approve($record, auth()->user());
                            Notification::make()->success()->title('اعتُمدت الحملة')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('تعذَّر الاعتماد')->body($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([Forms\Components\Textarea::make('note')->label('سبب الرفض')->required()])
                    ->visible(fn (SponsorCampaign $record) => $record->status === SponsorCampaign::STATUS_PENDING_REVIEW && auth()->user()->can('review', $record))
                    ->action(function (SponsorCampaign $record, array $data) {
                        app(SponsorCampaignService::class)->reject($record, auth()->user(), $data['note']);
                        Notification::make()->success()->title('رُفضت الحملة')->send();
                    }),

                Tables\Actions\Action::make('pause')
                    ->label('إيقاف')
                    ->icon('heroicon-o-pause-circle')
                    ->color('gray')
                    ->visible(fn (SponsorCampaign $record) => $record->status === SponsorCampaign::STATUS_APPROVED && auth()->user()->can('pause', $record))
                    ->action(function (SponsorCampaign $record) {
                        app(SponsorCampaignService::class)->pause($record, auth()->user());
                        Notification::make()->success()->title('أُوقفت الحملة')->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->label('استئناف')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (SponsorCampaign $record) => $record->status === SponsorCampaign::STATUS_PAUSED && auth()->user()->can('pause', $record))
                    ->action(function (SponsorCampaign $record) {
                        app(SponsorCampaignService::class)->resume($record, auth()->user());
                        Notification::make()->success()->title('استُؤنفت الحملة')->send();
                    }),
            ])
            ->defaultSort('priority', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CreativesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSponsorCampaigns::route('/'),
            'create' => Pages\CreateSponsorCampaign::route('/create'),
            'edit' => Pages\EditSponsorCampaign::route('/{record}/edit'),
        ];
    }
}
