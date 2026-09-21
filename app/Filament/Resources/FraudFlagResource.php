<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FraudFlagResource\Pages;
use App\Models\FraudFlag;
use App\Services\FraudDetectionService;
use App\Services\OperationalAuditService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FraudFlagResource extends Resource
{
    protected static ?string $model = FraudFlag::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'الأمان';

    protected static ?string $navigationLabel = 'علامات الاحتيال';

    protected static ?string $modelLabel = 'علامة احتيال';

    protected static ?string $pluralModelLabel = 'علامات الاحتيال';

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('resolved', false)->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name,email', 'resolvedBy:id,name']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\BadgeColumn::make('severity')->label('الخطورة')
                    ->colors(['success' => 'low', 'warning' => 'medium', 'danger' => 'high'])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', default => $state,
                    }),
                Tables\Columns\TextColumn::make('reason')->label('السبب'),
                Tables\Columns\TextColumn::make('details')->label('التفاصيل')->limit(50),
                Tables\Columns\IconColumn::make('resolved')->label('تمت المعالجة')->boolean(),
                Tables\Columns\TextColumn::make('resolvedBy.name')->label('عولجت بواسطة')->placeholder('—'),
                Tables\Columns\TextColumn::make('resolved_at')->label('تاريخ المعالجة')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('resolved')->label('تمت المعالجة')])
            ->actions([
                Tables\Actions\Action::make('resolve')
                    ->label('معالجة / إغلاق')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('resolve')
                    ->visible(fn (FraudFlag $record) => ! $record->resolved)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('ملاحظة المعالجة')
                            ->required()
                            ->helperText('هذه العلامة إشارة أمنية تحتاج مراجعة - معالجتها لا تُجمِّد الحساب أو تؤثر عليه تلقائياً.'),
                    ])
                    ->action(function (FraudFlag $record, array $data) {
                        app(FraudDetectionService::class)->resolve($record, auth()->user(), $data['note']);

                        app(OperationalAuditService::class)->log('fraud_flag_resolved', $record, [
                            'note' => $data['note'],
                            'user_id' => $record->user_id,
                        ]);

                        Notification::make()->title('تمت معالجة العلامة')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFraudFlags::route('/')];
    }
}