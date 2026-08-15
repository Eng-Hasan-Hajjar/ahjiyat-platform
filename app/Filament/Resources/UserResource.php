<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'المستخدمون';

    protected static ?string $navigationLabel = 'المستخدمون';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required(),
            Forms\Components\TextInput::make('email')->label('البريد الإلكتروني')->email()->required(),
            Forms\Components\Select::make('role')->label('الصلاحية')->options(['user' => 'مستخدم', 'admin' => 'مدير'])->required(),
            Forms\Components\Toggle::make('is_frozen')->label('محسوب مجمّد')->live(),
            Forms\Components\TextInput::make('frozen_reason')->label('سبب التجميد')
                ->visible(fn (Forms\Get $get) => $get('is_frozen')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('البريد')->searchable(),
                Tables\Columns\IconColumn::make('email_verified_at')->label('موثّق')->boolean(),
                Tables\Columns\TextColumn::make('wallet.available_balance')->label('الرصيد المتاح'),
                Tables\Columns\TextColumn::make('wallet.pending_balance')->label('الرصيد المعلق'),
                Tables\Columns\IconColumn::make('is_frozen')->label('مجمّد')->boolean()->trueColor('danger'),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ التسجيل')->date(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_frozen')->label('مجمّد'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_freeze')
                    ->label(fn (User $record) => $record->is_frozen ? 'رفع التجميد' : 'تجميد الحساب')
                    ->icon('heroicon-o-lock-closed')
                    ->color(fn (User $record) => $record->is_frozen ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->update(['is_frozen' => ! $record->is_frozen]);
                        Notification::make()->title('تم تحديث حالة الحساب')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
