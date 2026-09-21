<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeviceSightingsRelationManager extends RelationManager
{
    protected static string $relationship = 'deviceSightings';

    protected static ?string $title = 'الأجهزة';

    protected static ?string $icon = 'heroicon-o-device-phone-mobile';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('users.view_security') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('device_hash')
            ->columns([
                Tables\Columns\TextColumn::make('ip_address')->label('عنوان IP')->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('device_hash')->label('بصمة الجهاز')
                    ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 6).'••••'.substr($state, -4) : '—')
                    ->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('last_seen_at')->label('آخر ظهور')->since()->dateTooltip()->sortable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}