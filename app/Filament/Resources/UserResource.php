<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\PermissionMatrix;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'إدارة الوصول';

    protected static ?string $navigationLabel = 'المستخدمون';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('البيانات الأساسية')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('الاسم')->required(),
                    Forms\Components\TextInput::make('email')->label('البريد الإلكتروني')->email()->required(),
                ])->columns(2),

            Forms\Components\Section::make('الأدوار والصلاحيات')
                ->visible(fn () => auth()->user()?->can('users.manage_roles'))
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('الأدوار')
                        ->relationship('roles', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Role $role) => $role->displayLabel())
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->saveRelationshipsUsing(function (User $record, $state) {
                            app(\App\Filament\Resources\UserResource\RoleAssignmentSaver::class)->save($record, $state);
                        }),

                    Forms\Components\Placeholder::make('effective_access')
                        ->label('الوصول الفعلي (بعد آخر حفظ)')
                        ->content(fn (?User $record) => $record ? view('filament.resources.user-resource.effective-access', ['user' => $record]) : 'احفظ المستخدم أولاً لعرض وصوله الفعلي.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('صلاحيات مباشرة (استثنائية)')
                ->visible(fn () => auth()->user()?->can('users.manage_roles'))
                ->description('يُفضَّل التحكم بالصلاحيات عبر الأدوار أعلاه دائماً - استخدم هذا القسم فقط لحالات استثنائية نادرة.')
                ->collapsed()
                ->schema([
                    PermissionMatrix::make('direct_permissions_matrix')->label(''),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['fraudFlags as open_fraud_flags_count' => fn ($q) => $q->where('resolved', false)])
                ->with(['wallet:id,user_id,available_balance,pending_balance', 'roles:id,name,label_ar,color']))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('البريد')->searchable()->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\IconColumn::make('email_verified_at')->label('موثّق')->boolean(),
                Tables\Columns\TextColumn::make('roles.label_ar')->label('الأدوار')->badge()
                    ->formatStateUsing(fn ($state, User $record) => $record->roles->map(fn (Role $r) => $r->displayLabel())->implode(', ') ?: '—'),
                Tables\Columns\TextColumn::make('account_status')->label('حالة الحساب')->badge()
                    ->state(fn (User $record) => match (true) {
                        $record->is_frozen => 'مجمّد',
                        ! $record->email_verified_at => 'غير موثّق',
                        default => 'نشط',
                    })
                    ->color(fn ($state) => match ($state) {
                        'مجمّد' => 'danger', 'غير موثّق' => 'warning', default => 'success',
                    }),
                Tables\Columns\TextColumn::make('open_fraud_flags_count')->label('إشارات أمنية')
                    ->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->visible(fn () => auth()->user()?->can('fraud.view')),
                Tables\Columns\TextColumn::make('wallet.available_balance')->label('الرصيد المتاح')
                    ->visible(fn () => auth()->user()?->can('users.view_wallet')),
                Tables\Columns\TextColumn::make('last_seen_at')->label('آخر ظهور')->since()->dateTooltip(),
                Tables\Columns\TextColumn::make('created_at')->label('تاريخ التسجيل')->date(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_frozen')->label('الحالة')
                    ->trueLabel('مجمّد')->falseLabel('نشط'),
                Tables\Filters\TernaryFilter::make('email_verified_at')->label('توثيق البريد')
                    ->nullable()->trueLabel('موثّق')->falseLabel('غير موثّق'),
                Tables\Filters\SelectFilter::make('roles')->label('الدور')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $role) => $role->displayLabel()),
                Tables\Filters\Filter::make('has_open_fraud')
                    ->label('لديه إشارة أمنية مفتوحة')
                    ->query(fn ($query) => $query->whereHas('fraudFlags', fn ($q) => $q->where('resolved', false)))
                    ->visible(fn () => auth()->user()?->can('fraud.view')),
                Tables\Filters\Filter::make('has_pending_redemption')
                    ->label('لديه طلب استبدال معلَّق')
                    ->query(fn ($query) => $query->whereHas('redemptionRequests', fn ($q) => $q->where('status', 'pending_review')))
                    ->visible(fn () => auth()->user()?->can('redemptions.view')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('freeze')
                    ->label('تجميد الحساب')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->authorize('freeze')
                    ->visible(fn (User $record) => ! $record->is_frozen)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('سبب التجميد')->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        app(UserAccountService::class)->freeze($record, auth()->user(), $data['reason']);
                        Notification::make()->title('تم تجميد الحساب')->success()->send();
                    }),

                Tables\Actions\Action::make('unfreeze')
                    ->label('رفع التجميد')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->authorize('unfreeze')
                    ->visible(fn (User $record) => $record->is_frozen)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        app(UserAccountService::class)->unfreeze($record, auth()->user());
                        Notification::make()->title('تم رفع التجميد')->success()->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\GemTransactionsRelationManager::class,
            RelationManagers\PuzzleAttemptsRelationManager::class,
            RelationManagers\CampaignProgressRelationManager::class,
            RelationManagers\FraudFlagsRelationManager::class,
            RelationManagers\RedemptionRequestsRelationManager::class,
            RelationManagers\DeviceSightingsRelationManager::class,
            RelationManagers\SessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}