<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FraudFlagResource\Pages;
use App\Models\FraudFlag;
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
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\BadgeColumn::make('severity')->label('الخطورة')
                    ->colors(['success' => 'low', 'warning' => 'medium', 'danger' => 'high']),
                Tables\Columns\TextColumn::make('reason')->label('السبب'),
                Tables\Columns\TextColumn::make('details')->label('التفاصيل')->limit(50),
                Tables\Columns\IconColumn::make('resolved')->label('تمت المعالجة')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('resolved')->label('تمت المعالجة')])
            ->actions([
                Tables\Actions\Action::make('resolve')
                    ->label('وضع علامة كمعالج')
                    ->icon('heroicon-o-check')
                    ->visible(fn (FraudFlag $record) => ! $record->resolved)
                    ->action(function (FraudFlag $record) {
                        $record->update(['resolved' => true]);
                        Notification::make()->title('تم تحديث الحالة')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFraudFlags::route('/')];
    }
}
