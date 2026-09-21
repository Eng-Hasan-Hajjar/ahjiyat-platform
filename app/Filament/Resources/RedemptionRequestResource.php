<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedemptionRequestResource\Pages;
use App\Models\RedemptionRequest;
use App\Services\OperationalAuditService;
use App\Services\RedemptionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RedemptionRequestResource extends Resource
{
    protected static ?string $model = RedemptionRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'الجواهر والاستبدال';

    protected static ?string $navigationLabel = 'طلبات الاستبدال';

    protected static ?string $modelLabel = 'طلب استبدال';

    protected static ?string $pluralModelLabel = 'طلبات الاستبدال';

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('status', RedemptionRequest::STATUS_PENDING)->count();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('user.name')->label('المستخدم')->disabled(),
            Forms\Components\TextInput::make('gems_amount')->label('عدد الجواهر')->disabled(),
            Forms\Components\Textarea::make('reward_description')->label('المكافأة المطلوبة')->disabled()->columnSpanFull(),
            Forms\Components\TextInput::make('reviewer.name')->label('راجعها')->disabled(),
            Forms\Components\Textarea::make('admin_note')->label('ملاحظة الإدارة')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name,email', 'reviewer:id,name']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\TextColumn::make('gems_amount')->label('الجواهر')->sortable(),
                Tables\Columns\TextColumn::make('reward_description')->label('المكافأة')->limit(40),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'warning' => RedemptionRequest::STATUS_PENDING,
                        'success' => fn ($state) => in_array($state, [RedemptionRequest::STATUS_APPROVED, RedemptionRequest::STATUS_FULFILLED]),
                        'danger' => fn ($state) => in_array($state, [RedemptionRequest::STATUS_REJECTED, RedemptionRequest::STATUS_CANCELLED]),
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        RedemptionRequest::STATUS_PENDING => 'قيد المراجعة',
                        RedemptionRequest::STATUS_APPROVED => 'مقبول',
                        RedemptionRequest::STATUS_REJECTED => 'مرفوض',
                        RedemptionRequest::STATUS_FULFILLED => 'تم التنفيذ',
                        RedemptionRequest::STATUS_CANCELLED => 'ملغى',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('reviewer.name')->label('راجعها')->placeholder('—'),
                Tables\Columns\TextColumn::make('reviewed_at')->label('تاريخ المراجعة')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ الطلب')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        RedemptionRequest::STATUS_PENDING => 'قيد المراجعة',
                        RedemptionRequest::STATUS_APPROVED => 'مقبول',
                        RedemptionRequest::STATUS_REJECTED => 'مرفوض',
                        RedemptionRequest::STATUS_FULFILLED => 'تم التنفيذ',
                        RedemptionRequest::STATUS_CANCELLED => 'ملغى',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('قبول')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('approve')
                    ->visible(fn (RedemptionRequest $record) => $record->status === RedemptionRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('note')->label('ملاحظة (اختياري)')])
                    ->action(function (RedemptionRequest $record, array $data) {
                        abort_if($record->fresh()->status !== RedemptionRequest::STATUS_PENDING, 409, 'تمت معالجة هذا الطلب بالفعل.');

                        app(RedemptionService::class)->approve($record, auth()->user(), $data['note'] ?? null);
                        app(OperationalAuditService::class)->log('redemption_approved', $record, [
                            'user_id' => $record->user_id, 'gems_amount' => $record->gems_amount,
                        ]);

                        Notification::make()->title('تم قبول الطلب')->success()->send();
                    }),

                Tables\Actions\Action::make('fulfill')
                    ->label('تم التنفيذ')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize('fulfill')
                    ->visible(fn (RedemptionRequest $record) => $record->status === RedemptionRequest::STATUS_APPROVED)
                    ->requiresConfirmation()
                    ->action(function (RedemptionRequest $record) {
                        abort_if($record->fresh()->status !== RedemptionRequest::STATUS_APPROVED, 409, 'حالة الطلب تغيَّرت بالفعل.');

                        app(RedemptionService::class)->markFulfilled($record, auth()->user());
                        app(OperationalAuditService::class)->log('redemption_fulfilled', $record, [
                            'user_id' => $record->user_id, 'gems_amount' => $record->gems_amount,
                        ]);

                        Notification::make()->title('تم تسجيل تنفيذ الطلب')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->authorize('reject')
                    ->visible(fn (RedemptionRequest $record) => $record->status === RedemptionRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('note')->label('سبب الرفض')->required()])
                    ->action(function (RedemptionRequest $record, array $data) {
                        abort_if($record->fresh()->status !== RedemptionRequest::STATUS_PENDING, 409, 'تمت معالجة هذا الطلب بالفعل.');

                        app(RedemptionService::class)->reject($record, auth()->user(), $data['note']);
                        app(OperationalAuditService::class)->log('redemption_rejected', $record, [
                            'user_id' => $record->user_id, 'gems_amount' => $record->gems_amount, 'reason' => $data['note'],
                        ]);

                        Notification::make()->title('تم رفض الطلب وإرجاع الجواهر')->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedemptionRequests::route('/'),
            'view' => Pages\ViewRedemptionRequest::route('/{record}'),
        ];
    }
}