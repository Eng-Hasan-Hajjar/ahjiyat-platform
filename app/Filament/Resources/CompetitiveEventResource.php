<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompetitiveEventResource\Pages;
use App\Models\CompetitiveEvent;
use App\Models\Puzzle;
use App\Services\Competitive\CompetitiveEligibility;
use App\Services\Competitive\CompetitiveEventAdminService;
use App\Services\Competitive\CompetitiveException;
use App\Services\Competitive\Rewards\CompetitiveRewardDistributionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * إدارة المنافسات (E17-B12). الحالة ليست حقلًا قابلًا للتحرير: النشر والإلغاء والاعتماد إجراءات Domain مفوَّضة بسياسة ومدقَّقة
 * (CompetitiveEventAdminService)، لا تعديل خام. حقول العدالة (الأحجية والمواعيد والسعة) تُقفَل بعد النشر (حارس النموذج + تعطيل هنا).
 * لا تعديل يدوي لأي نتيجة أو نقطة أو فائز. الأحجية الهدف بلا تلميح وإجابة منفردة فقط.
 */
class CompetitiveEventResource extends Resource
{
    protected static ?string $model = CompetitiveEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'المنافسات';

    protected static ?string $modelLabel = 'منافسة';

    protected static ?string $pluralModelLabel = 'المنافسات';

    protected static function lockedAfterPublish(?CompetitiveEvent $record): bool
    {
        return $record !== null && $record->status !== CompetitiveEvent::STATUS_DRAFT;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->maxLength(120)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            Forms\Components\TextInput::make('slug')->label('المعرّف في الرابط')->required()->alphaDash()->maxLength(80)->unique(ignoreRecord: true)->notIn(CompetitiveEvent::RESERVED_SLUGS)
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\Textarea::make('description')->label('الوصف')->maxLength(2000)->columnSpanFull(),
            Forms\Components\Select::make('puzzle_id')->label('الأحجية (بلا تلميح، إجابة منفردة)')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => app(CompetitiveEligibility::class)->eligiblePuzzlesQuery(requireActive: false)
                    ->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')->orderBy('title')->limit(30)->pluck('title', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => Puzzle::query()->find($value)?->title)
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\DateTimePicker::make('starts_at')->label('تبدأ في')->required()
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\DateTimePicker::make('ends_at')->label('تنتهي في')->required()->after('starts_at')
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\DateTimePicker::make('registration_starts_at')->label('يبدأ التسجيل (اختياري)')
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\DateTimePicker::make('registration_ends_at')->label('ينتهي التسجيل (اختياري)')
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\TextInput::make('max_participants')->label('أقصى عدد مشاركين (اختياري)')->numeric()->minValue(1)
                ->disabled(fn (?CompetitiveEvent $record) => static::lockedAfterPublish($record))->dehydrated(fn (?CompetitiveEvent $record) => ! static::lockedAfterPublish($record)),
            Forms\Components\Toggle::make('is_featured')->label('مميزة'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $phaseLabels = ['draft' => 'مسوّدة', 'upcoming' => 'قادمة', 'live' => 'مباشرة', 'ended' => 'انتهت (بانتظار الاعتماد)', 'completed' => 'معتمَدة', 'cancelled' => 'مُلغاة'];

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['results', 'participants as completed_participants_count' => fn ($q) => $q->where('status', 'completed')]))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('phase')->label('الحالة')->badge()
                    ->state(fn (CompetitiveEvent $record) => $phaseLabels[$record->phase()] ?? $record->phase())
                    ->color(fn (CompetitiveEvent $record) => match ($record->phase()) { 'live' => 'success', 'upcoming' => 'info', 'cancelled' => 'danger', 'draft' => 'gray', default => 'warning' }),
                Tables\Columns\TextColumn::make('starts_at')->label('البداية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('ends_at')->label('النهاية')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('participants_count')->label('المسجَّلون')
                    ->formatStateUsing(fn ($state, CompetitiveEvent $record) => $state.($record->max_participants !== null ? ' / '.$record->max_participants : '')),
                Tables\Columns\TextColumn::make('results_count')->label('أرسلوا نتائجهم'),
                Tables\Columns\IconColumn::make('is_featured')->label('مميزة')->boolean(),
                Tables\Columns\TextColumn::make('rewards_status')->label('الجوائز')
                    ->visible(fn () => auth()->user()?->can('competitive_events.rewards.view') ?? false)
                    ->state(function (CompetitiveEvent $record) {
                        if (! $record->rewardRules()->exists()) {
                            return '—';
                        }

                        $c = app(CompetitiveRewardDistributionService::class)->counts($record);

                        return "مؤهَّلون {$c['eligible']} · ممنوحة {$c['granted']} · معلّقة {$c['pending']} · فاشلة {$c['failed']}";
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::lifecycleAction('publish', 'نشر', 'success', fn (CompetitiveEvent $r) => $r->status === CompetitiveEvent::STATUS_DRAFT, 'publish', 'تم نشر المنافسة.'),
                static::lifecycleAction('cancel', 'إلغاء', 'danger', fn (CompetitiveEvent $r) => in_array($r->status, [CompetitiveEvent::STATUS_DRAFT, CompetitiveEvent::STATUS_PUBLISHED], true), 'cancel', 'أُلغيت المنافسة (لا قبول لنتائج جديدة، والتاريخ محفوظ).'),
                static::lifecycleAction('finalize', 'اعتماد النتائج', 'warning', fn (CompetitiveEvent $r) => $r->phase() === CompetitiveEvent::PHASE_ENDED, 'finalize', 'اعتُمدت النتائج النهائية.'),
                Tables\Actions\Action::make('retry_rewards')->label('إعادة محاولة الجوائز الفاشلة')->color('warning')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->visible(fn (CompetitiveEvent $r) => $r->status === CompetitiveEvent::STATUS_COMPLETED && (auth()->user()?->can('retryRewards', $r) ?? false)
                        && $r->rewardGrants()->where('status', 'failed')->exists())
                    ->action(function (CompetitiveEvent $record) {
                        try {
                            $s = app(CompetitiveRewardDistributionService::class)->retryFailed($record, auth()->user());
                            Notification::make()->success()->title("أُعيدت المحاولة: {$s['granted']} ممنوحة، {$s['failed']} ما زالت فاشلة.")->send();
                        } catch (CompetitiveException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('results')->label('النتائج')->icon('heroicon-o-trophy')
                    ->visible(fn (CompetitiveEvent $r) => $r->status !== CompetitiveEvent::STATUS_DRAFT)
                    ->url(fn (CompetitiveEvent $r) => route('competitions.show', $r), shouldOpenInNewTab: true),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    /** إجراء دورة حياة: تفويض بالسياسة + الخدمة (تدقيق) + رسالة؛ أي رفض منطقي يظهر إشعارًا لا خطأ 500. */
    protected static function lifecycleAction(string $name, string $label, string $color, \Closure $visible, string $ability, string $done): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (CompetitiveEvent $record) => $visible($record) && auth()->user()?->can($ability, $record))
            ->action(function (CompetitiveEvent $record) use ($name, $done) {
                try {
                    app(CompetitiveEventAdminService::class)->{$name}($record, auth()->user());
                    Notification::make()->success()->title($done)->send();
                } catch (CompetitiveException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [CompetitiveEventResource\RelationManagers\RewardRulesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitiveEvents::route('/'),
            'create' => Pages\CreateCompetitiveEvent::route('/create'),
            'edit' => Pages\EditCompetitiveEvent::route('/{record}/edit'),
        ];
    }
}
