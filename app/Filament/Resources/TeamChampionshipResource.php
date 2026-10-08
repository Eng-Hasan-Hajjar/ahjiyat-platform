<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamChampionshipResource\Pages;
use App\Models\TeamChampionship;
use App\Services\Teams\TeamChampionshipService;
use App\Services\Teams\TeamException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * بطولات الفرق (E20-E14..E18): CRUD للمسودة، ودورة الحياة **بإجراءات مجال** مدقَّقة (نشر، إلغاء، اعتماد) لا بتحرير حالة خام. المواعيد والمعرّف مقفلة بعد النشر (وأيضًا بحارس النموذج)،
 * وأحداثها تُدار بمدير أحداث عبر الخدمة وللمسودة وحدها. لا مكافآت اقتصادية: تعرُّف فقط. الحذف للمسودة فقط.
 */
class TeamChampionshipResource extends Resource
{
    protected static ?string $model = TeamChampionship::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'الفرق';

    protected static ?string $navigationLabel = 'بطولات الفرق';

    protected static ?string $modelLabel = 'بطولة فرق';

    protected static ?string $pluralModelLabel = 'بطولات الفرق';

    protected static ?int $navigationSort = 62;

    public static function form(Form $form): Form
    {
        $locked = fn (?TeamChampionship $record) => $record !== null && ! $record->isDraft();

        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->maxLength(120),
            Forms\Components\TextInput::make('slug')->label('المعرّف في الرابط')->required()->alphaDash()->maxLength(80)->unique(ignoreRecord: true)->disabled($locked),
            Forms\Components\Textarea::make('description')->label('الوصف')->maxLength(1000)->columnSpanFull(),
            Forms\Components\DateTimePicker::make('starts_at')->label('البداية')->required()->disabled($locked),
            Forms\Components\DateTimePicker::make('ends_at')->label('النهاية')->required()->after('starts_at')->disabled($locked),
            Forms\Components\Toggle::make('is_featured')->label('مميّزة'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with('champion:id,name')->withCount('events'))->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => ['draft' => 'مسودة', 'published' => 'منشورة', 'completed' => 'معتمَدة', 'cancelled' => 'ملغاة'][$state] ?? $state),
                Tables\Columns\TextColumn::make('phase')->label('المرحلة')->state(fn (TeamChampionship $r) => $r->status === 'published' ? ['upcoming' => 'قادمة', 'live' => 'جارية', 'ended' => 'انتهت: بانتظار الاعتماد'][$r->phase()] : '—'),
                Tables\Columns\TextColumn::make('events_count')->label('الأحداث'),
                Tables\Columns\TextColumn::make('champion.name')->label('البطل')->placeholder('—'),
                Tables\Columns\TextColumn::make('starts_at')->label('تبدأ')->dateTime('Y-m-d'),
                Tables\Columns\TextColumn::make('ends_at')->label('تنتهي')->dateTime('Y-m-d'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['draft' => 'مسودة', 'published' => 'منشورة', 'completed' => 'معتمَدة', 'cancelled' => 'ملغاة'])])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (TeamChampionship $r) => ! in_array($r->status, ['completed', 'cancelled'], true)),
                self::lifecycle('publish', 'نشر', 'success', 'heroicon-o-rocket-launch', fn (TeamChampionship $r) => $r->isDraft(), fn ($svc, $r) => $svc->publish(auth()->user(), $r), 'نُشرت البطولة وقُفلت مواعيدها ولقطة نقاطها.'),
                self::lifecycle('cancel', 'إلغاء', 'danger', 'heroicon-o-x-circle', fn (TeamChampionship $r) => in_array($r->status, ['draft', 'published'], true), fn ($svc, $r) => $svc->cancel(auth()->user(), $r), 'أُلغيت البطولة.'),
                self::lifecycle('finalize', 'اعتماد النتائج', 'warning', 'heroicon-o-check-badge', fn (TeamChampionship $r) => $r->status === 'published' && $r->ends_at->lessThanOrEqualTo(now()), fn ($svc, $r) => $svc->finalize(auth()->user(), $r), 'اعتُمدت النتائج وأُعلن البطل.'),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /** إجراء مجال مفوَّض (publish) ومدقَّق: أي رفض (حالة، نقص، صلاحية) يظهر إشعارًا. */
    protected static function lifecycle(string $name, string $label, string $color, string $icon, \Closure $visible, \Closure $call, string $success): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->icon($icon)->requiresConfirmation()
            ->visible(fn (TeamChampionship $r) => $visible($r) && (auth()->user()?->can('publish', $r) ?? false))
            ->action(function (TeamChampionship $record) use ($call, $success) {
                try {
                    $call(app(TeamChampionshipService::class), $record);
                    Notification::make()->success()->title($success)->send();
                } catch (TeamException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [TeamChampionshipResource\RelationManagers\EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeamChampionships::route('/'),
            'create' => Pages\CreateTeamChampionship::route('/create'),
            'edit' => Pages\EditTeamChampionship::route('/{record}/edit'),
        ];
    }
}
