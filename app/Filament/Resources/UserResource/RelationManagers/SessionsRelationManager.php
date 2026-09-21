<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Session;
use App\Services\SessionManagementService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'الجلسات';

    protected static ?string $icon = 'heroicon-o-computer-desktop';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('security.sessions_view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('ip_address')->label('عنوان IP')->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('user_agent')->label('الجهاز/المتصفح')->limit(50)->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('last_activity')->label('آخر نشاط')
                    ->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Carbon::createFromTimestamp($state)->diffForHumans() : '—')
                    ->sortable(),
            ])
            ->defaultSort('last_activity', 'desc')
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('إنهاء الجلسة')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->authorize(fn () => auth()->user()?->can('security.sessions_revoke'))
                    ->requiresConfirmation()
                    ->action(function (Session $record) {
                        try {
                            app(SessionManagementService::class)->revoke($record, auth()->user());
                        } catch (\Throwable $e) {
                            Notification::make()->title('تعذَّر إنهاء الجلسة')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('تم إنهاء الجلسة')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}