<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamResource\Pages;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * إدارة الفرق (E19-E10..E13). **عرض + تدخل عبر الخدمات فقط**: لا إنشاء ولا تعديل خام ولا حذف (السياسة تمنعها)، فلا تُكسَر الثوابت (مالك واحد، فريق واحد لكل مستخدم،
 * العدّاد). التعطيل/إعادة التفعيل ونقل الملكية وإزالة عضو إجراءات Domain مفوَّضة بصلاحيات teams.deactivate/teams.manage ومدقَّقة. مالك مجمَّد: لا يُفكَّك فريقه تلقائيًا؛
 * تتدخل الإدارة بنقل الملكية لعضو. محتوى الفريق (الاسم/الوصف) يُعرض مُهرَّبًا.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'الفرق';

    protected static ?string $navigationLabel = 'الفرق';

    protected static ?string $modelLabel = 'فريق';

    protected static ?string $pluralModelLabel = 'الفرق';

    protected static ?int $navigationSort = 60;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('owner:id,name'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الفريق')->searchable(),
                Tables\Columns\TextColumn::make('owner.name')->label('المالك')->placeholder('— (مؤرشف)'),
                Tables\Columns\TextColumn::make('members_count')->label('الأعضاء')->formatStateUsing(fn (Team $r) => "{$r->members_count} / {$r->capacity()}")->state(fn (Team $r) => $r),
                Tables\Columns\TextColumn::make('visibility')->label('الظهور')->badge()->formatStateUsing(fn ($state) => $state === 'public' ? 'عام' : 'خاص'),
                Tables\Columns\TextColumn::make('join_policy')->label('الانضمام')->formatStateUsing(fn ($state) => ['open' => 'مفتوح', 'request' => 'بطلب', 'invite_only' => 'بدعوة'][$state] ?? $state),
                Tables\Columns\IconColumn::make('is_active')->label('مفعَّل')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('التفعيل'),
                Tables\Filters\SelectFilter::make('visibility')->label('الظهور')->options(['public' => 'عام', 'private' => 'خاص']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('deactivate')->label('تعطيل')->color('danger')->icon('heroicon-o-no-symbol')->requiresConfirmation()
                    ->visible(fn (Team $r) => $r->is_active && (auth()->user()?->can('deactivate', $r) ?? false))
                    ->action(fn (Team $r) => app(TeamService::class)->adminSetActive(auth()->user(), $r, false)),
                Tables\Actions\Action::make('reactivate')->label('إعادة تفعيل')->color('success')->icon('heroicon-o-check-circle')->requiresConfirmation()
                    ->visible(fn (Team $r) => ! $r->is_active && (auth()->user()?->can('deactivate', $r) ?? false))
                    ->action(fn (Team $r) => app(TeamService::class)->adminSetActive(auth()->user(), $r, true)),
                Tables\Actions\Action::make('transfer')->label('نقل الملكية')->color('warning')->icon('heroicon-o-arrow-path-rounded-square')
                    ->visible(fn (Team $r) => (auth()->user()?->can('adminManage', $r) ?? false) && $r->members_count > 1)
                    ->form(fn (Team $r) => [
                        Forms\Components\Select::make('user_id')->label('المالك الجديد (عضو حالي)')->required()->searchable()
                            ->options(fn () => TeamMembership::query()->where('team_id', $r->id)->where('role', '!=', TeamMembership::ROLE_OWNER)->with('user:id,name')->get()->pluck('user.name', 'user_id')->all()),
                    ])
                    ->action(function (Team $r, array $data) {
                        try {
                            app(TeamService::class)->adminTransferOwnership(auth()->user(), $r, User::query()->findOrFail($data['user_id']));
                            Notification::make()->success()->title('نُقلت الملكية.')->send();
                        } catch (TeamException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('الفريق')->columns(3)->schema([
                Infolists\Components\TextEntry::make('name')->label('الاسم'),
                Infolists\Components\TextEntry::make('slug')->label('المعرّف'),
                Infolists\Components\TextEntry::make('owner.name')->label('المالك')->placeholder('— (مؤرشف)'),
                Infolists\Components\TextEntry::make('members_count')->label('الأعضاء'),
                Infolists\Components\TextEntry::make('max_members')->label('أقصى أعضاء')->placeholder('بلا حد (السقف العام)'),
                Infolists\Components\IconEntry::make('is_active')->label('مفعَّل')->boolean(),
                Infolists\Components\TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [TeamResource\RelationManagers\MembersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeams::route('/'),
            'view' => Pages\ViewTeam::route('/{record}'),
        ];
    }
}
