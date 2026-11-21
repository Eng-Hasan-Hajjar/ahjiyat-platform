<?php

namespace App\Filament\Resources;

use App\Filament\GameTypeAuthoring\MemoryFilamentSchema;
use App\Filament\GameTypeAuthoring\SequenceFilamentSchema;
use App\Filament\Resources\PuzzleLabExerciseResource\Pages;
use App\Models\PuzzleLabExercise;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E47 - إدارة تمارين «مختبر الأحجيات» (تدريب فقط).
 *
 * - محرّرات المحتوى هي نفسها محرّرات الأحجيات القائمة (MemoryFilamentSchema / SequenceFilamentSchema): قوائم عناصر بلا JSON يدوي.
 * - دورة الحياة: مسودة (الحفظ الافتراضي) ← نشر (يتحقق من اكتمال المحتوى) ← إخفاء. المعاينة الإدارية تفتح صفحة اللاعب نفسها بلا نشر.
 * - الصلاحيات: صلاحيات الأحجيات القائمة (puzzles.*) عبر PuzzleLabExercisePolicy. لا صلاحيات جديدة.
 * - لا مكافآت ولا جواهر ولا XP هنا ولا في أي مسار لهذه الميزة.
 */
class PuzzleLabExerciseResource extends Resource
{
    protected static ?string $model = PuzzleLabExercise::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'الأحجيات';

    protected static ?string $navigationLabel = 'مختبر الأحجيات (تدريب)';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'تمرين تدريبي';

    protected static ?string $pluralModelLabel = 'تمارين مختبر الأحجيات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات التمرين')
                ->description('تمرين للتدريب فقط: لا جواهر ولا نقاط خبرة ولا أثر على التقدّم الرسمي.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')->label('عنوان التمرين')->required()->maxLength(120)->columnSpanFull(),

                    Forms\Components\Select::make('game_type')
                        ->label('نوع التمرين')
                        ->options(PuzzleLabExercise::TYPES)
                        ->required()
                        ->native(false)
                        ->live()
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->helperText(fn (string $context) => $context === 'edit' ? 'لا يتغيّر النوع بعد الإنشاء؛ أنشئ تمرينًا جديدًا لنوع آخر.' : null)
                        ->afterStateUpdated(fn (Set $set) => $set('game_config', [])),

                    Forms\Components\Select::make('level')
                        ->label('المستوى')
                        ->options(PuzzleLabExercise::LEVELS)
                        ->default('easy')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('الترتيب في القائمة (الأصغر أولًا)')
                        ->numeric()->integer()->minValue(0)->maxValue(65000)->default(0)->required(),

                    Forms\Components\TextInput::make('slug')
                        ->label('الرابط (اختياري)')
                        ->helperText('أحرف إنجليزية صغيرة وأرقام وشرطات فقط. اتركه فارغًا ليُولَّد تلقائيًا. لا يتغيّر بعد الإنشاء.')
                        ->minLength(3)->maxLength(80)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->notIn(['preview'])
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $context) => $context === 'edit')
                        ->dehydrated(fn (string $context) => $context === 'create')
                        ->extraInputAttributes(['dir' => 'ltr']),

                    Forms\Components\Placeholder::make('status_label')
                        ->label('الحالة')
                        ->content(fn (?PuzzleLabExercise $record) => $record?->statusLabel() ?? 'مسودة (تُحفظ كمسودة عند الإنشاء)')
                        ->visible(fn (string $context) => $context === 'edit'),
                ]),

            Forms\Components\Section::make('نصوص التمرين')
                ->description('الحقول الأربعة الأولى مطلوبة قبل النشر، ويمكن حفظ المسودة بدونها.')
                ->schema([
                    Forms\Components\TextInput::make('summary')->label('وصف قصير (يظهر في قائمة التمارين)')->maxLength(220),
                    Forms\Components\Textarea::make('instructions')->label('تعليمات التمرين (يقرؤها اللاعب قبل البدء)')->rows(4)->maxLength(1500),
                    Forms\Components\Textarea::make('wrong_note')
                        ->label('الملاحظة التعليمية بعد المحاولة الخاطئة')
                        ->helperText('اكتب فكرة تساعد اللاعب على التفكير بشكل أفضل. لا تكتب الحل نفسه: هذه الملاحظة تظهر بعد الخطأ.')
                        ->rows(3)->maxLength(600),
                    Forms\Components\Textarea::make('success_explanation')
                        ->label('شرح موجز عند النجاح (اختياري)')
                        ->rows(3)->maxLength(800),
                ]),

            Forms\Components\Section::make('محتوى التمرين')
                ->description('من '.PuzzleLabExercise::MIN_ENTRIES.' إلى '.PuzzleLabExercise::MAX_ENTRIES.' عناصر، بلا تكرار. اختر نوع التمرين أولًا.')
                ->visible(fn (Get $get) => filled($get('game_type')))
                ->schema([
                    // محرّرات الأحجيات القائمة نفسها (تقرأ حقل game_type بالنموذج نفسه) مع حدود المختبر.
                    ...collect((new MemoryFilamentSchema)->fields())
                        ->map(fn (Forms\Components\Repeater $field) => $field->maxItems(PuzzleLabExercise::MAX_ENTRIES))
                        ->all(),
                    ...collect((new SequenceFilamentSchema)->fields())
                        ->map(fn (Forms\Components\Repeater $field) => $field->minItems(PuzzleLabExercise::MIN_ENTRIES)->maxItems(PuzzleLabExercise::MAX_ENTRIES))
                        ->all(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('game_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PuzzleLabExercise::TYPES[$state] ?? $state)
                    ->color('info'),
                Tables\Columns\TextColumn::make('level')
                    ->label('المستوى')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PuzzleLabExercise::LEVELS[$state] ?? $state)
                    ->color(fn ($state) => ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PuzzleLabExercise::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['published' => 'success', 'hidden' => 'warning'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تعديل')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(PuzzleLabExercise::STATUSES),
                Tables\Filters\SelectFilter::make('game_type')->label('النوع')->options(PuzzleLabExercise::TYPES),
                Tables\Filters\SelectFilter::make('level')->label('المستوى')->options(PuzzleLabExercise::LEVELS),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('معاينة')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (PuzzleLabExercise $record) => route('puzzle-lab.preview', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('publish')
                    ->label('نشر')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('سيظهر التمرين للاعبين في مختبر الأحجيات.')
                    ->visible(fn (PuzzleLabExercise $record) => ! $record->isPublished() && (auth()->user()?->can('publish', $record) ?? false))
                    ->action(fn (PuzzleLabExercise $record) => static::publishRecord($record)),
                Tables\Actions\Action::make('hide')
                    ->label('إخفاء')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('سيختفي التمرين من قائمة اللاعبين، ويمكن نشره لاحقًا.')
                    ->visible(fn (PuzzleLabExercise $record) => $record->isPublished() && (auth()->user()?->can('publish', $record) ?? false))
                    ->action(fn (PuzzleLabExercise $record) => static::hideRecord($record)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /** نشر بعد التحقق من اكتمال المحتوى؛ أسباب الرفض تظهر للمحرّر بالعربية. */
    public static function publishRecord(PuzzleLabExercise $record): bool
    {
        try {
            $record->publish();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('لا يمكن نشر التمرين بعد')->body($e->getMessage())->persistent()->send();

            return false;
        }

        Notification::make()->success()->title('نُشر التمرين ويظهر الآن للاعبين.')->send();

        return true;
    }

    public static function hideRecord(PuzzleLabExercise $record): void
    {
        $record->hide();

        Notification::make()->success()->title('أُخفي التمرين عن اللاعبين.')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPuzzleLabExercises::route('/'),
            'create' => Pages\CreatePuzzleLabExercise::route('/create'),
            'edit' => Pages\EditPuzzleLabExercise::route('/{record}/edit'),
        ];
    }
}
