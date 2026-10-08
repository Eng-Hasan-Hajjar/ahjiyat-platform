<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMessageReportResource\Pages;
use App\Models\ChatMessageReport;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatMuteService;
use App\Services\Chat\ChatReportService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * مركز بلاغات الدردشة (E21-E6/E7/N1): **يعرض الرسالة المبلَّغ عنها وحدها** (نصها وحالتها والمرسل والمبلِّغ ونوع الغرفة والسبب والوقت)، **لا تاريخ المحادثة** ولا قائمة بكل الرسائل الخاصة
 * (لا يوجد مورد رسائل أصلًا: الرسائل الخاصة ليست صندوق المشرف). الإجراءات بخدمات مدقَّقة بصلاحية chat.moderate: إخفاء (والبلاغ يصبح actioned)، استعادة، كتم المرسل بالعامة، رفض، أو تعليم "تمت المراجعة".
 */
class ChatMessageReportResource extends Resource
{
    protected static ?string $model = ChatMessageReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'بلاغات الدردشة';

    protected static ?string $modelLabel = 'بلاغ دردشة';

    protected static ?string $pluralModelLabel = 'بلاغات الدردشة';

    protected static ?int $navigationSort = 70;

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function statusLabel(string $s): string
    {
        return ['pending' => 'قيد الانتظار', 'reviewed' => 'تمت المراجعة', 'dismissed' => 'مرفوض', 'actioned' => 'اتُّخذ إجراء'][$s] ?? $s;
    }

    protected static function categoryLabel(string $c): string
    {
        return ['spam' => 'إزعاج', 'harassment' => 'إساءة', 'inappropriate' => 'غير لائق', 'scam' => 'احتيال', 'other' => 'آخر'][$c] ?? $c;
    }

    protected static function roomLabel(string $t): string
    {
        return ['direct' => 'محادثة خاصة', 'team' => 'دردشة فريق', 'global' => 'الدردشة العامة'][$t] ?? $t;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['message.thread:id,type', 'message.sender:id,name', 'reporter:id,name']))->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Tables\Columns\TextColumn::make('room')->label('الغرفة')->state(fn (ChatMessageReport $r) => self::roomLabel($r->message->thread->type)),
                Tables\Columns\TextColumn::make('category')->label('السبب')->formatStateUsing(fn ($state) => self::categoryLabel($state)),
                Tables\Columns\TextColumn::make('message.sender.name')->label('المرسل')->placeholder('حساب محذوف'),
                Tables\Columns\TextColumn::make('reporter.name')->label('المُبلِّغ'),
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['pending' => 'قيد الانتظار', 'reviewed' => 'تمت المراجعة', 'dismissed' => 'مرفوض', 'actioned' => 'اتُّخذ إجراء'])->default('pending')])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::review('dismiss', 'رفض', ChatMessageReport::STATUS_DISMISSED, 'gray'),
                self::review('reviewed', 'تمت المراجعة', ChatMessageReport::STATUS_REVIEWED, 'info'),
                self::hideAction(),
                self::restoreAction(),
                self::muteAction(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('البلاغ')->columns(3)->schema([
                Infolists\Components\TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => self::statusLabel($state)),
                Infolists\Components\TextEntry::make('category')->label('السبب')->formatStateUsing(fn ($state) => self::categoryLabel($state)),
                Infolists\Components\TextEntry::make('room')->label('الغرفة')->state(fn (ChatMessageReport $r) => self::roomLabel($r->message->thread->type)),
                Infolists\Components\TextEntry::make('reporter.name')->label('المُبلِّغ'),
                Infolists\Components\TextEntry::make('created_at')->label('وقت البلاغ')->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('details')->label('تفاصيل المُبلِّغ')->placeholder('—'),
            ]),
            // الرسالة المبلَّغ عنها فقط (نص عادي مهرَّب). لا سياق محادثة ولا رسائل أخرى.
            Infolists\Components\Section::make('الرسالة المبلَّغ عنها')->schema([
                Infolists\Components\TextEntry::make('message.sender.name')->label('المرسل')->placeholder('حساب محذوف'),
                Infolists\Components\TextEntry::make('message_state')->label('حالة الرسالة')->state(fn (ChatMessageReport $r) => $r->message->isDeleted() ? 'حذفها المرسل' : ($r->message->isHidden() ? 'مخفية إشرافيًا' : 'ظاهرة')),
                Infolists\Components\TextEntry::make('message.body')->label('النص')->columnSpanFull(),
            ]),
        ]);
    }

    protected static function review(string $name, string $label, string $status, string $color): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (ChatMessageReport $r) => $r->status === ChatMessageReport::STATUS_PENDING && (auth()->user()?->can('chat.moderate') ?? false))
            ->action(fn (ChatMessageReport $record) => self::guard(fn () => app(ChatReportService::class)->review(auth()->user(), $record, $status), 'حُدِّثت حالة البلاغ.'));
    }

    protected static function hideAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('hide')->label('إخفاء الرسالة')->color('danger')->icon('heroicon-o-eye-slash')
            ->form([Forms\Components\Textarea::make('reason')->label('السبب (إلزامي)')->required()->maxLength((int) config('chat.moderation_reason_max', 200))])
            ->visible(fn (ChatMessageReport $r) => $r->message->isNormal() && (auth()->user()?->can('chat.moderate') ?? false))
            ->action(function (ChatMessageReport $record, array $data) {
                self::guard(function () use ($record, $data) {
                    app(ChatModerationService::class)->hide(auth()->user(), $record->message, $data['reason']);
                    app(ChatReportService::class)->review(auth()->user(), $record, ChatMessageReport::STATUS_ACTIONED);
                }, 'أُخفيت الرسالة وحُسم البلاغ.');
            });
    }

    protected static function restoreAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('restore')->label('استعادة الرسالة')->color('success')->icon('heroicon-o-eye')
            ->form([Forms\Components\Textarea::make('reason')->label('السبب (إلزامي)')->required()->maxLength((int) config('chat.moderation_reason_max', 200))])
            ->visible(fn (ChatMessageReport $r) => $r->message->isHidden() && ! $r->message->isDeleted() && (auth()->user()?->can('chat.moderate') ?? false))
            ->action(fn (ChatMessageReport $record, array $data) => self::guard(fn () => app(ChatModerationService::class)->restore(auth()->user(), $record->message, $data['reason']), 'استُعيدت الرسالة.'));
    }

    protected static function muteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('mute')->label('كتم المرسل بالعامة')->color('warning')->icon('heroicon-o-speaker-x-mark')
            ->form([
                Forms\Components\Select::make('duration')->label('المدة')->options(['10m' => '10 دقائق', '1h' => 'ساعة', '24h' => '24 ساعة', '7d' => '7 أيام'])->required(),
                Forms\Components\Textarea::make('reason')->label('السبب (إلزامي)')->required()->maxLength((int) config('chat.moderation_reason_max', 200)),
            ])
            ->visible(fn (ChatMessageReport $r) => $r->message->sender_id !== null && (auth()->user()?->can('chat.moderate') ?? false))
            ->action(function (ChatMessageReport $record, array $data) {
                self::guard(function () use ($record, $data) {
                    app(ChatMuteService::class)->mute(auth()->user(), $record->message->sender, $data['duration'], $data['reason']);
                    app(ChatReportService::class)->review(auth()->user(), $record, ChatMessageReport::STATUS_ACTIONED);
                }, 'كُتم المرسل وحُسم البلاغ.');
            });
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (ChatException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListChatMessageReports::route('/'), 'view' => Pages\ViewChatMessageReport::route('/{record}')];
    }
}
