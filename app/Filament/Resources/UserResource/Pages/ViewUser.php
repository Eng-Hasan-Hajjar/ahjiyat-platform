<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Actions;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('freeze')
                ->label('تجميد الحساب')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->authorize('freeze')
                ->visible(fn () => ! $this->record->is_frozen)
                ->requiresConfirmation()
                ->form([\Filament\Forms\Components\Textarea::make('reason')->label('سبب التجميد')->required()])
                ->action(function (array $data) {
                    app(UserAccountService::class)->freeze($this->record, auth()->user(), $data['reason']);
                    Notification::make()->title('تم تجميد الحساب')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),

            Actions\Action::make('unfreeze')
                ->label('رفع التجميد')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->authorize('unfreeze')
                ->visible(fn () => $this->record->is_frozen)
                ->requiresConfirmation()
                ->action(function () {
                    app(UserAccountService::class)->unfreeze($this->record, auth()->user());
                    Notification::make()->title('تم رفع التجميد')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('الملف الأساسي')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('الاسم'),
                    TextEntry::make('email')->label('البريد الإلكتروني')->copyable(),
                    TextEntry::make('id')->label('معرّف المستخدم'),
                    TextEntry::make('created_at')->label('تاريخ التسجيل')->dateTime('Y-m-d H:i'),
                    IconEntry::make('email_verified_at')->label('البريد موثّق')
                        ->state(fn (User $record) => (bool) $record->email_verified_at)
                        ->boolean(),
                    TextEntry::make('last_seen_at')->label('آخر ظهور')->since()->placeholder('لم يُسجَّل بعد'),
                ]),

            Section::make('حالة الحساب')
                ->columns(3)
                ->schema([
                    TextEntry::make('account_status')->label('الحالة')->badge()
                        ->state(fn (User $record) => $record->is_frozen ? 'مجمّد' : 'نشط')
                        ->color(fn ($state) => $state === 'مجمّد' ? 'danger' : 'success'),
                    TextEntry::make('frozen_reason')->label('سبب التجميد')->placeholder('—'),
                    TextEntry::make('frozen_at')->label('تاريخ التجميد')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('frozenBy.name')->label('جمَّده')->placeholder('—'),
                ]),

            Section::make('الأدوار والوصول الفعلي')
                ->visible(fn () => auth()->user()?->can('users.manage_roles') || auth()->user()?->can('users.view'))
                ->schema([
                    TextEntry::make('roles_display')->label('الأدوار')->badge()
                        ->state(fn (User $record) => $record->roles->map(fn (Role $r) => $r->displayLabel())->all()),
                    TextEntry::make('effective_access')
                        ->label('الوصول الفعلي')
                        ->html()
                        ->state(fn (User $record) => view('filament.resources.user-resource.effective-access', ['user' => $record])->render()),
                ]),

            Section::make('ملخص الأمان')
                ->visible(fn () => auth()->user()?->can('fraud.view'))
                ->columns(2)
                ->schema([
                    TextEntry::make('open_fraud_count')->label('إشارات أمنية مفتوحة')
                        ->state(fn (User $record) => $record->fraudFlags()->where('resolved', false)->count())
                        ->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                    TextEntry::make('resolved_fraud_count')->label('إشارات تمت معالجتها')
                        ->state(fn (User $record) => $record->fraudFlags()->where('resolved', true)->count()),
                ]),

            Section::make('ملخص النشاط')
                ->visible(fn () => auth()->user()?->can('users.view_activity'))
                ->columns(3)
                ->schema([
                    TextEntry::make('total_attempts')->label('إجمالي المحاولات')
                        ->state(fn (User $record) => $record->puzzleAttempts()->count()),
                    TextEntry::make('correct_attempts')->label('محاولات صحيحة')
                        ->state(fn (User $record) => $record->puzzleAttempts()->where('is_correct', true)->count()),
                    TextEntry::make('solved_puzzles')->label('أحجيات محلولة')
                        ->state(fn (User $record) => $record->puzzleAttempts()->where('is_correct', true)->distinct('puzzle_id')->count('puzzle_id')),
                ]),

            Section::make('ملخص المحفظة')
                ->visible(fn () => auth()->user()?->can('users.view_wallet'))
                ->columns(2)
                ->schema([
                    TextEntry::make('wallet.available_balance')->label('الرصيد المتاح')->placeholder('0'),
                    TextEntry::make('wallet.pending_balance')->label('الرصيد المعلَّق')->placeholder('0'),
                ]),
        ]);
    }
}