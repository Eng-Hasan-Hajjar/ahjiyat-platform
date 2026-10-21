<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserCollectionResource\Pages;
use App\Models\UserCollection;
use App\Services\Collections\CollectionModerationService;
use App\Services\Engagement\EngagementException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E35 - إشراف المجموعات **المشتركة** (غير المدرجة والعامة) فقط: المجموعات الخاصة لا تظهر للمشرفين إطلاقًا (الاستعلام نفسه يستثنيها).
 * قراءة فقط + إجراءان (إخفاء بسبب / استعادة) عبر CollectionModerationService (collections.moderate + تدقيق + إشعار المالك). لا تعديل لعنوان أو وصف المستخدم، ولا حذف.
 */
class UserCollectionResource extends Resource
{
    protected static ?string $model = UserCollection::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'الإشراف';

    protected static ?string $navigationLabel = 'المجموعات المشتركة';

    protected static ?string $modelLabel = 'مجموعة';

    protected static ?string $pluralModelLabel = 'المجموعات المشتركة';

    protected static ?int $navigationSort = 84;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_collections.visibility', '!=', UserCollection::VISIBILITY_PRIVATE);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name')->withCount(['items', 'reports as open_reports_count' => fn ($q) => $q->where('status', 'open')]))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption((int) config('collections.reports.moderation_page_size', 20))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('user.name')->label('المالك'),
                Tables\Columns\TextColumn::make('visibility')->label('الظهور')->badge()->formatStateUsing(fn ($state) => UserCollection::visibilityLabel((string) $state))
                    ->color(fn ($state) => $state === UserCollection::VISIBILITY_PUBLIC ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('items_count')->label('الأعمال')->numeric(),
                Tables\Columns\TextColumn::make('saves_count')->label('الحفظ')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('open_reports_count')->label('بلاغات مفتوحة')->numeric()->sortable(),
                Tables\Columns\IconColumn::make('is_moderation_hidden')->label('مخفية')->boolean(),
                Tables\Columns\TextColumn::make('moderation_reason')->label('سبب الإخفاء')->limit(40)->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('visibility')->label('الظهور')->options([UserCollection::VISIBILITY_UNLISTED => 'غير مدرجة', UserCollection::VISIBILITY_PUBLIC => 'عامة']),
                Tables\Filters\TernaryFilter::make('is_moderation_hidden')->label('مخفية'),
                Tables\Filters\Filter::make('has_open_reports')->label('عليها بلاغات مفتوحة')
                    ->query(fn (Builder $query) => $query->whereHas('reports', fn ($q) => $q->where('status', 'open'))),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('فتح')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (UserCollection $r) => route('collections.public.show', $r->public_id), shouldOpenInNewTab: true)
                    ->visible(fn (UserCollection $r) => ! $r->is_moderation_hidden),
                Tables\Actions\Action::make('hide')->label('إخفاء')->color('danger')->icon('heroicon-o-eye-slash')
                    ->form([Forms\Components\Textarea::make('reason')->label('السبب (إلزامي، يراه المالك)')->required()->minLength((int) config('collections.reports.note_min'))->maxLength((int) config('collections.reports.note_max'))])
                    ->visible(fn (UserCollection $r) => ! $r->is_moderation_hidden && (auth()->user()?->can('collections.moderate') ?? false))
                    ->action(fn (UserCollection $record, array $data) => self::guard(fn () => app(CollectionModerationService::class)->hide($record, auth()->user(), (string) $data['reason']), 'أُخفيت المجموعة.')),
                Tables\Actions\Action::make('restore')->label('استعادة')->color('success')->icon('heroicon-o-eye')->requiresConfirmation()
                    ->visible(fn (UserCollection $r) => $r->is_moderation_hidden && (auth()->user()?->can('collections.moderate') ?? false))
                    ->action(fn (UserCollection $record) => self::guard(fn () => app(CollectionModerationService::class)->restore($record, auth()->user()), 'استُعيدت المجموعة.')),
            ]);
    }

    protected static function guard(\Closure $call, string $ok): void
    {
        try {
            $call();
            Notification::make()->success()->title($ok)->send();
        } catch (EngagementException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUserCollections::route('/')];
    }
}
