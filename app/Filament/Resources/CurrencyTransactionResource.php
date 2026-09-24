<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CurrencyTransactionResource\Pages;
use App\Models\Currency;
use App\Models\CurrencyTransaction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CurrencyTransactionResource extends Resource
{
    protected static ?string $model = CurrencyTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'الاقتصاد';

    protected static ?string $navigationLabel = 'سجل المعاملات';

    protected static ?string $modelLabel = 'معاملة';

    protected static ?string $pluralModelLabel = 'سجل المعاملات (Ledger)';

    protected static ?string $slug = 'currency-transactions';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'currency:id,name,code']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\TextColumn::make('currency.name')->label('العملة')->badge(),
                Tables\Columns\TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        CurrencyTransaction::TYPE_EARN_PENDING => 'كسب (معلَّق)',
                        CurrencyTransaction::TYPE_RELEASE_AVAILABLE => 'إتاحة رصيد',
                        CurrencyTransaction::TYPE_REDEEM => 'استبدال/صرف',
                        CurrencyTransaction::TYPE_EXPIRE => 'انتهاء صلاحية',
                        CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT => 'تعديل إداري',
                        CurrencyTransaction::TYPE_PURCHASE => 'شراء',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('amount')->label('القيمة')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => ($state >= 0 ? '+' : '').$state),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->limit(40),
                Tables\Columns\TextColumn::make('reference_type')->label('نوع المرجع')->formatStateUsing(fn ($state) => $state ? class_basename($state) : '—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('currency_id')->label('العملة')->options(fn () => Currency::pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('type')->label('النوع')->options([
                    CurrencyTransaction::TYPE_EARN_PENDING => 'كسب (معلَّق)',
                    CurrencyTransaction::TYPE_RELEASE_AVAILABLE => 'إتاحة رصيد',
                    CurrencyTransaction::TYPE_REDEEM => 'استبدال/صرف',
                    CurrencyTransaction::TYPE_EXPIRE => 'انتهاء صلاحية',
                    CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT => 'تعديل إداري',
                ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من تاريخ'),
                        Forms\Components\DatePicker::make('until')->label('إلى تاريخ'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCurrencyTransactions::route('/')];
    }
}