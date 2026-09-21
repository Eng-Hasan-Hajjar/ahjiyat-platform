<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\RedemptionRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RedemptionRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptionRequests';

    protected static ?string $title = 'طلبات الاستبدال';

    protected static ?string $icon = 'heroicon-o-gift';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('redemptions.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reward_description')
            ->columns([
                Tables\Columns\TextColumn::make('gems_amount')->label('الجواهر'),
                Tables\Columns\TextColumn::make('reward_description')->label('المكافأة')->limit(40),
                Tables\Columns\BadgeColumn::make('status')->label('الحالة')
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
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}