<?php

namespace App\Filament\Resources\StoreItemResource\RelationManagers;

use App\Models\Currency;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'خيارات السعر';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('currency_id')
                ->label('العملة')
                ->options(fn () => Currency::where('is_active', true)->pluck('name', 'id'))
                ->required()
                ->native(false),
            Forms\Components\TextInput::make('amount')->label('القيمة')->numeric()->required()->minValue(1),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->modifyQueryUsing(fn ($query) => $query->with('currency:id,name,code'))
            ->columns([
                Tables\Columns\TextColumn::make('currency.name')->label('العملة')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('القيمة'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => ! $record->isProtected())
                    ->requiresConfirmation(),
            ]);
    }
}