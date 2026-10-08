<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMuteResource\Pages;
use App\Models\ChatMute;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMuteService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** كتم الدردشة العامة (E21-N2): عرض الكتم ورفعه بخدمة مدقَّقة بصلاحية chat.moderate. الإنشاء من بلاغ (إجراء «كتم المرسل») أو من الخدمة؛ لا تحرير خام. */
class ChatMuteResource extends Resource
{
    protected static ?string $model = ChatMute::class;

    protected static ?string $navigationIcon = 'heroicon-o-speaker-x-mark';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'كتم الدردشة';

    protected static ?string $modelLabel = 'كتم';

    protected static ?string $pluralModelLabel = 'حالات الكتم';

    protected static ?int $navigationSort = 71;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['user:id,name', 'createdBy:id,name']))->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('المستخدم')->searchable(),
                Tables\Columns\TextColumn::make('reason')->label('السبب')->limit(60),
                Tables\Columns\TextColumn::make('expires_at')->label('ينتهي')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('state')->label('الحالة')->badge()->state(fn (ChatMute $m) => $m->lifted_at !== null ? 'رُفع' : ($m->expires_at->isPast() ? 'انتهى' : 'نشِط')),
                Tables\Columns\TextColumn::make('createdBy.name')->label('بواسطة')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('unmute')->label('رفع الكتم')->color('success')->requiresConfirmation()
                    ->visible(fn (ChatMute $m) => $m->lifted_at === null && $m->expires_at->isFuture() && (auth()->user()?->can('chat.moderate') ?? false))
                    ->action(function (ChatMute $record) {
                        try {
                            app(ChatMuteService::class)->unmute(auth()->user(), $record->user);
                            Notification::make()->success()->title('رُفع الكتم.')->send();
                        } catch (ChatException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListChatMutes::route('/')];
    }
}
