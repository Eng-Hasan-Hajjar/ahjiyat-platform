<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Currency;
use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\OperationalAuditService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GemTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'currencyTransactions';

    protected static ?string $title = 'المحفظة والمعاملات';

    protected static ?string $icon = 'heroicon-o-currency-dollar';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('users.view_wallet') ?? false;
    }

    protected static function typeLabel(string $type): string
    {
        return match ($type) {
            CurrencyTransaction::TYPE_EARN_PENDING => 'كسب (معلَّق)',
            CurrencyTransaction::TYPE_RELEASE_AVAILABLE => 'إتاحة رصيد',
            CurrencyTransaction::TYPE_REDEEM => 'استبدال/صرف',
            CurrencyTransaction::TYPE_EXPIRE => 'انتهاء صلاحية',
            CurrencyTransaction::TYPE_ADMIN_ADJUSTMENT => 'تعديل إداري',
            CurrencyTransaction::TYPE_PURCHASE => 'شراء',
            default => $type,
        };
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('currency:id,name,code'))
            ->recordTitleAttribute('reason')
            ->columns([
                Tables\Columns\TextColumn::make('currency.name')->label('العملة')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('القيمة')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => ($state >= 0 ? '+' : '').$state),
                Tables\Columns\TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn ($state) => static::typeLabel($state)),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->limit(40),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('currency_id')->label('العملة')->options(fn () => Currency::pluck('name', 'id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('adjust')
                    ->label('تعديل يدوي')
                    ->icon('heroicon-o-plus-circle')
                    ->color('warning')
                    ->authorize(fn () => auth()->user()?->can('wallet.adjust'))
                    ->form([
                        Forms\Components\Select::make('currency_id')
                            ->label('العملة')
                            ->options(fn () => Currency::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('amount')
                            ->label('القيمة (موجبة للإضافة، سالبة للخصم)')
                            ->numeric()->required()
                            ->helperText('مثال: 100 للإضافة، -50 للخصم.'),
                        Forms\Components\Textarea::make('reason')->label('السبب')->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data) use ($user) {
                        $currency = Currency::findOrFail($data['currency_id']);

                        try {
                            app(CurrencyWalletService::class)->adjust($user, $currency, (int) $data['amount'], $data['reason']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('تعذَّر التعديل')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(OperationalAuditService::class)->log('wallet_manual_adjustment', $user, [
                            'currency' => $currency->internal_key, 'amount' => (int) $data['amount'], 'reason' => $data['reason'],
                        ]);

                        Notification::make()->title('تم تعديل الرصيد')->success()->send();
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}