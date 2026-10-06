<?php

namespace App\Filament\Resources\TeamResource\RelationManagers;

use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Teams\TeamException;
use App\Services\Teams\TeamMembershipService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * أعضاء الفريق (E19-E11): عرض فقط + إجراء إزالة **عبر الخدمة** (لا حذف خام للصف). المالك لا يُزال أبدًا (الخدمة والحارس يرفضانه)، وكل إزالة مدقَّقة.
 * لا إنشاء ولا تعديل دور هنا: الأدوار بيد مالك الفريق، ونقل الملكية إجراء مستقل.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'الأعضاء';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('العضو'),
                Tables\Columns\TextColumn::make('role')->label('الدور')->badge()->formatStateUsing(fn ($state) => ['owner' => 'مالك', 'admin' => 'مشرف', 'member' => 'عضو'][$state] ?? $state),
                Tables\Columns\TextColumn::make('joined_at')->label('انضم')->dateTime('Y-m-d'),
            ])
            ->actions([
                Tables\Actions\Action::make('remove')->label('إزالة')->color('danger')->icon('heroicon-o-user-minus')->requiresConfirmation()
                    ->visible(fn (TeamMembership $m) => $m->role !== TeamMembership::ROLE_OWNER && (auth()->user()?->can('adminManage', $m->team) ?? false))
                    ->action(function (TeamMembership $m) {
                        try {
                            app(TeamMembershipService::class)->adminRemove(auth()->user(), $m->team, $m->user);
                            Notification::make()->success()->title('أُزيل العضو.')->send();
                        } catch (TeamException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
