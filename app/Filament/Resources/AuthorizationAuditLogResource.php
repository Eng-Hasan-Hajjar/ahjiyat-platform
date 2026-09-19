<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuthorizationAuditLogResource\Pages;
use App\Models\AuthorizationAuditLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuthorizationAuditLogResource extends Resource
{
    protected static ?string $model = AuthorizationAuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'إدارة الوصول';

    protected static ?string $navigationLabel = 'سجل تغييرات الوصول';

    protected static ?string $modelLabel = 'سجل تدقيق';

    protected static ?string $pluralModelLabel = 'سجل تغييرات الوصول';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('system.audit_view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $actionLabels = [
            'role_created' => 'إنشاء دور',
            'role_permissions_updated' => 'تعديل صلاحيات دور',
            'user_roles_updated' => 'تعديل أدوار مستخدم',
            'user_direct_permissions_updated' => 'تعديل صلاحيات مباشرة لمستخدم',
        ];

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('actor.name')->label('المنفِّذ')->placeholder('نظام'),
                Tables\Columns\TextColumn::make('action')->label('الإجراء')->badge()
                    ->formatStateUsing(fn (string $state) => $actionLabels[$state] ?? $state),
                Tables\Columns\TextColumn::make('subject_type')->label('نوع الهدف')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        \App\Models\Role::class => 'دور',
                        \App\Models\User::class => 'مستخدم',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('subject_id')->label('معرّف الهدف'),
                Tables\Columns\TextColumn::make('ip_address')->label('عنوان IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')->label('الإجراء')->options($actionLabels),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('من تاريخ'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('إلى تاريخ'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuthorizationAuditLogs::route('/')];
    }
}