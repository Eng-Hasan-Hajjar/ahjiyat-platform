<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\SeasonResource\Pages;
use App\Filament\Resources\SeasonResource\RelationManagers\AnnouncementsRelationManager;
use App\Filament\Resources\SeasonResource\RelationManagers\ContentItemsRelationManager;
use App\Filament\Resources\SeasonResource\RelationManagers\PhasesRelationManager;
use App\Models\AhjiyatPass;
use App\Models\Campaign;
use App\Models\Season;
use App\Services\SeasonLifecycleService;
use App\Services\Seasons\SeasonTheme;
use App\Services\SeasonReadinessService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'الحملات';

    protected static ?string $navigationLabel = 'المواسم الرسمية';

    protected static ?string $modelLabel = 'موسم رسمي';

    protected static ?string $pluralModelLabel = 'المواسم الرسمية';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الأساسيات')
                ->schema([
                    Forms\Components\Select::make('campaign_id')
                        ->relationship('campaign', 'title')
                        ->label('الحملة المرتبطة')
                        ->helperText('إدارة المحتوى الفعلي (المراحل/البوابات/المهام) تتم من صفحة الحملة عبر زر "إدارة القصة" أدناه.')
                        ->searchable()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->live()
                        ->columnSpanFull(),

                    // عرض عنوان الحملة كمرجع سريع فقط (Read-only) - التعديل الفعلي
                    // للعنوان يتم من صفحة الحملة نفسها، لا هون، تفاديًا لمصدرَي تحرير.
                    Forms\Components\Placeholder::make('campaign_title_display')
                        ->label('عنوان الحملة الحالي')
                        ->content(fn (Get $get) => Campaign::find($get('campaign_id'))?->title ?? '—')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('code')->label('رمز الموسم')->placeholder('S01')->required(),
                    Forms\Components\TextInput::make('slug')->label('المعرّف بالرابط (slug)')->required()->unique(ignoreRecord: true)
                        ->notIn((array) config('seasons.reserved_slugs', []))
                        ->validationMessages(['not_in' => 'هذا المعرّف محجوز لمسار بالموقع. اختر معرّفًا آخر.']),
                ])->columns(2),

            Forms\Components\Section::make('الهوية البصرية')
                ->schema([
                    Forms\Components\FileUpload::make('logo_image')
                        ->label('شعار الموسم (اختياري)')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        // مسار عام ثابت (لا يعتمد على Slug/ID غير موجودين بعد عند
                        // الإنشاء) - آمن للاستخدام بأي موسم مستقبلي، لا "aseel" هون.
                        ->directory('seasons/branding')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048),

                    Forms\Components\FileUpload::make('banner_image')
                        ->label('صورة الغلاف الرئيسية (Hero Banner)')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('seasons/branding')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(4096)
                        ->helperText('تظهر خلف عنوان الموسم بصفحة الـHero وبالصفحة الرئيسية.'),

                    Forms\Components\TextInput::make('theme_config.hero_tagline')
                        ->label('الجملة التعريفية (Tagline)')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('grand_prize_description')
                        ->label('وصف الجائزة الكبرى')
                        ->helperText('اترك فارغاً لعرض "سيتم الإعلان عن الجائزة الكبرى قريباً" تلقائياً للاعبين.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('theme_config.preset')
                        ->label('نمط الثيم')
                        ->options(fn () => SeasonTheme::presetLabels())
                        ->default('generic')
                        ->native(false)
                        ->required()
                        ->helperText('خيارات آمنة محدَّدة مسبقاً فقط - لا كتابة CSS/JS حرة.'),
                ])->columns(2),

            Forms\Components\Section::make('إدارة القصة')
                ->schema([
                    Forms\Components\Placeholder::make('manage_story_hint')
                        ->label('')
                        ->content('استخدم زرَّي "إدارة القصة" و"معاينة الموسم" أعلى صفحة القائمة أو أسفل صفحة التعديل بعد الحفظ، للانتقال إلى إدارة المراحل/البوابات/المهام أو معاينة تجربة اللاعب الفعلية.')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('الجدولة والمسار')
                ->description('النافذة الصريحة اختيارية: الحقل الفارغ يتبع تواريخ الحملة الأساسية كما كان. المراحل والمحتوى والإعلانات تُدار من التبويبات أسفل الصفحة بعد الحفظ.')
                ->schema([
                    Forms\Components\DateTimePicker::make('starts_at')->label('بداية الموسم')->seconds(false)->helperText('فارغ = تبدأ مع الحملة الأساسية.'),
                    Forms\Components\DateTimePicker::make('ends_at')->label('نهاية الموسم')->seconds(false)->helperText('فارغ = تنتهي مع الحملة الأساسية.'),
                    Forms\Components\DateTimePicker::make('announcement_at')->label('موعد ظهور الموسم للجمهور')->seconds(false)->helperText('فارغ = يظهر فور النشر. يجب ألا يتأخر عن البداية.'),
                    Forms\Components\DateTimePicker::make('archive_at')->label('موعد الأرشفة التلقائية')->seconds(false)->helperText('اختياري. بعده ينتقل الموسم إلى الأرشيف وتُجمَّد نافذته.'),
                    Forms\Components\Select::make('ahjiyat_pass_id')->label('مسار الأحجيات المرتبط (اختياري)')->searchable()->native(false)->placeholder('بدون مسار')
                        ->options(fn () => AhjiyatPass::query()->orderByDesc('id')->limit(100)->pluck('name', 'id')->all())
                        ->helperText('عرض فقط في صفحة الموسم: المسار يملك نقاطه ومكافآته وملكيته.')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('النشر')
                ->schema([
                    Forms\Components\Toggle::make('is_published')
                        ->label('نشر الموسم')
                        ->helperText('لن يُحفظ منشوراً إذا كانت هناك عناصر تقنية معلَّقة تمنع النشر العام - سيظهر تنبيه بدلاً من ذلك.'),
                    Forms\Components\Toggle::make('is_featured')->label('إبرازه في الصفحة الرئيسية'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('الرمز'),
                Tables\Columns\TextColumn::make('campaign.title')->label('الحملة'),
                Tables\Columns\TextColumn::make('lifecycle_state')
                    ->label('الحالة')
                    ->state(fn (Season $record) => app(SeasonLifecycleService::class)->stateLabel(app(SeasonLifecycleService::class)->state($record)))
                    ->badge()
                    ->color(fn (Season $record) => match (app(SeasonLifecycleService::class)->state($record)) {
                        SeasonLifecycleService::STATE_LIVE => 'success',
                        SeasonLifecycleService::STATE_SCHEDULED => 'warning',
                        SeasonLifecycleService::STATE_DRAFT => 'gray',
                        default => 'info',
                    }),
                Tables\Columns\IconColumn::make('is_published')->label('منشور')->boolean(),
                Tables\Columns\IconColumn::make('is_featured')->label('مُبرَز')->boolean(),
                Tables\Columns\TextColumn::make('readiness')
                    ->label('جاهزية النشر')
                    ->state(fn (Season $record) => app(SeasonReadinessService::class)->isReadyToPublish($record) ? 'جاهز' : 'يحتاج استكمال')
                    ->badge()
                    ->color(fn (Season $record) => app(SeasonReadinessService::class)->isReadyToPublish($record) ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('manageCampaign')
                    ->label('إدارة القصة')
                    ->icon('heroicon-o-map')
                    ->url(fn (Season $record) => CampaignResource::getUrl('edit', ['record' => $record->campaign_id])),

                Tables\Actions\Action::make('preview')
                    ->label('معاينة الموسم')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Season $record) => route('seasons.show', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (Season $record) => $record->went_live_at === null),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [PhasesRelationManager::class, ContentItemsRelationManager::class, AnnouncementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeasons::route('/'),
            'create' => Pages\CreateSeason::route('/create'),
            'edit' => Pages\EditSeason::route('/{record}/edit'),
        ];
    }
}