<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\FraudFlag;
use App\Services\FraudDetectionService;
use App\Services\OperationalAuditService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FraudFlagsRelationManager extends RelationManager
{
    protected static string $relationship = 'fraudFlags';

    protected static ?string $title = 'إشارات أمنية';

    protected static ?string $icon = 'heroicon-o-shield-exclamation';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('fraud.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('resolvedBy:id,name'))
            ->recordTitleAttribute('reason')
            ->columns([
                Tables\Columns\BadgeColumn::make('severity')->label('الخطورة')
                    ->colors(['success' => 'low', 'warning' => 'medium', 'danger' => 'high'])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', default => $state,
                    }),
                Tables\Columns\TextColumn::make('reason')->label('السبب'),
                Tables\Columns\IconColumn::make('resolved')->label('عولجت')->boolean(),
                Tables\Columns\TextColumn::make('resolvedBy.name')->label('عولجت بواسطة')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('resolve')
                    ->label('معالجة')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize(fn () => auth()->user()?->can('fraud.resolve'))
                    ->visible(fn (FraudFlag $record) => ! $record->resolved)
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('note')->label('ملاحظة المعالجة')->required()])
                    ->action(function (FraudFlag $record, array $data) {
                        app(FraudDetectionService::class)->resolve($record, auth()->user(), $data['note']);
                        app(OperationalAuditService::class)->log('fraud_flag_resolved', $record, ['note' => $data['note']]);
                        Notification::make()->title('تمت المعالجة')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}