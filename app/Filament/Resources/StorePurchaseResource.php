<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StorePurchaseResource\Pages;
use App\Models\StoreItem;
use App\Models\StorePurchase;
use App\Services\OperationalAuditService;
use App\Services\Store\StorePurchaseService;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StorePurchaseResource extends Resource
{
    protected static ?string $model = StorePurchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'المتجر';

    protected static ?string $navigationLabel = 'المشتريات';

    protected static ?string $modelLabel = 'عملية شراء';

    protected static ?string $pluralModelLabel = 'المشتريات';

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function statusLabel(string $status): string
    {
        return match ($status) {
            StorePurchase::STATUS_PENDING_FULFILLMENT => 'بانتظار الإنجاز',
            StorePurchase::STATUS_FULFILLED => 'مكتمل',
            StorePurchase::STATUS_REFUNDED => 'مُسترجَع',
            StorePurchase::STATUS_CANCELLED => 'ملغى',
            default => $status,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'item:id,name', 'currency:id,name,code']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\TextColumn::make('item.name')->label('العنصر')->searchable()->description(fn (StorePurchase $r) => $r->item_snapshot['sku'] ?? ''),
                Tables\Columns\TextColumn::make('price_amount')->label('السعر')->formatStateUsing(fn (StorePurchase $r) => $r->price_amount.' '.($r->currency->code ?? '')),
                Tables\Columns\BadgeColumn::make('status')->label('الحالة')
                    ->formatStateUsing(fn ($state) => static::statusLabel($state))
                    ->colors([
                        'warning' => StorePurchase::STATUS_PENDING_FULFILLMENT,
                        'success' => StorePurchase::STATUS_FULFILLED,
                        'danger' => StorePurchase::STATUS_REFUNDED,
                        'gray' => StorePurchase::STATUS_CANCELLED,
                    ]),
                Tables\Columns\TextColumn::make('fulfillment_type')->label('التسليم')->formatStateUsing(fn ($state) => match ($state) {
                    StoreItem::FULFILLMENT_INVENTORY => 'مخزون', StoreItem::FULFILLMENT_ENTITLEMENT => 'امتياز',
                    StoreItem::FULFILLMENT_MANUAL => 'يدوي', default => $state,
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options([
                    StorePurchase::STATUS_PENDING_FULFILLMENT => 'بانتظار الإنجاز',
                    StorePurchase::STATUS_FULFILLED => 'مكتمل',
                    StorePurchase::STATUS_REFUNDED => 'مُسترجَع',
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('fulfill')
                    ->label('إنجاز الطلب')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (StorePurchase $record) => $record->status === StorePurchase::STATUS_PENDING_FULFILLMENT
                        && auth()->user()?->can('fulfill', $record))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('admin_note')->label('ملاحظة إدارية (اختياري)'),
                        Forms\Components\TextInput::make('user_message')->label('رسالة تظهر للمستخدم (اختياري)'),
                    ])
                    ->action(function (StorePurchase $record, array $data) {
                        try {
                            app(StorePurchaseService::class)->fulfillManually(
                                $record, auth()->user(), $data['admin_note'] ?? null, $data['user_message'] ?? null
                            );
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('تعذَّر الإنجاز')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(OperationalAuditService::class)->log('store_purchase_fulfilled', $record, ['user_id' => $record->user_id]);
                        Notification::make()->title('تم إنجاز الطلب')->success()->send();
                    }),

                Tables\Actions\Action::make('refund')
                    ->label('استرجاع')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (StorePurchase $record) => $record->isRefundable() && auth()->user()?->can('refund', $record))
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('reason')->label('سبب الاسترجاع')->required()])
                    ->action(function (StorePurchase $record, array $data) {
                        try {
                            app(StorePurchaseService::class)->refund($record, auth()->user(), $data['reason']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('تعذَّر الاسترجاع')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        app(OperationalAuditService::class)->log('store_purchase_refunded', $record, [
                            'user_id' => $record->user_id, 'amount' => $record->price_amount, 'currency' => $record->currency->internal_key ?? null, 'reason' => $data['reason'],
                        ]);
                        Notification::make()->title('تم الاسترجاع')->success()->send();
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('تفاصيل الشراء')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('المستخدم'),
                    TextEntry::make('item_snapshot.name')->label('العنصر (وقت الشراء)'),
                    TextEntry::make('item_snapshot.sku')->label('SKU'),
                    TextEntry::make('price_amount')->label('المبلغ المدفوع')->formatStateUsing(fn ($state, StorePurchase $r) => $state.' '.($r->currency->code ?? '')),
                    TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => static::statusLabel($state))->badge(),
                    TextEntry::make('created_at')->label('تاريخ الشراء')->dateTime('Y-m-d H:i'),
                    TextEntry::make('fulfilled_at')->label('تاريخ الإنجاز')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('fulfiller.name')->label('أنجزه')->placeholder('—'),
                    TextEntry::make('refunded_at')->label('تاريخ الاسترجاع')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('refunder.name')->label('استرجعه')->placeholder('—'),
                    TextEntry::make('admin_note')->label('ملاحظة إدارية')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('user_message')->label('رسالة للمستخدم')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStorePurchases::route('/'),
            'view' => Pages\ViewStorePurchase::route('/{record}'),
        ];
    }
}