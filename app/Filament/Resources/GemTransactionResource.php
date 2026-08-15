<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GemTransactionResource\Pages;
use App\Models\GemTransaction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// سجل ledger للقراءة فقط - أي تعديل على الأرصدة لازم يمر حصراً عبر GemWalletService
class GemTransactionResource extends Resource
{
    protected static ?string $model = GemTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'الجواهر والاستبدال';

    protected static ?string $navigationLabel = 'سجل المعاملات';

    protected static ?string $modelLabel = 'معاملة';

    protected static ?string $pluralModelLabel = 'سجل معاملات الجواهر';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('القيمة')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('type')->label('النوع'),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('النوع')->options([
                    'earn_pending' => 'كسب معلق',
                    'release_available' => 'تحويل لمتاح',
                    'redeem' => 'استبدال',
                    'expire' => 'انتهاء صلاحية',
                    'admin_adjustment' => 'تعديل إداري',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGemTransactions::route('/')];
    }
}
