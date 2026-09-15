<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\SeasonResource\Pages;
use App\Models\Season;
use App\Services\SeasonReadinessService;
use Filament\Forms;
use Filament\Forms\Form;
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
                        ->helperText('إدارة المحتوى الفعلي (المراحل/البوابات/المهام) تتم من صفحة الحملة.')
                        ->searchable()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('code')->label('رمز الموسم')->placeholder('S01')->required(),
                    Forms\Components\TextInput::make('slug')->label('المعرّف بالرابط (slug)')->required()->unique(ignoreRecord: true),
                ])->columns(2),

            Forms\Components\Section::make('الهوية والعرض')
                ->schema([
                    Forms\Components\FileUpload::make('banner_image')->label('صورة الغلاف (Banner)')->image()->directory('seasons/aseel/banner'),
                    Forms\Components\FileUpload::make('logo_image')->label('شعار الموسم (اختياري)')->image()->directory('seasons/aseel/logo'),
                    Forms\Components\Textarea::make('grand_prize_description')
                        ->label('وصف الجائزة الكبرى')
                        ->helperText('اترك فارغاً لعرض "سيتم الإعلان عن الجائزة الكبرى قريباً".')
                        ->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('الثيم البصري')
                ->description('خيارات آمنة ومحدَّدة مسبقاً فقط - لا كتابة CSS/JS حرة.')
                ->schema([
                    Forms\Components\Select::make('theme_config.preset')
                        ->label('نمط الثيم')
                        ->options(['generic' => 'عام (هوية المنصة القياسية)', 'aseel' => 'أصيل (غموض وتحقيق رقمي)'])
                        ->default('generic')->native(false)->required(),
                    Forms\Components\TextInput::make('theme_config.hero_tagline')->label('الجملة التعريفية (Tagline)')->columnSpanFull(),
                ])->columns(2),

            Forms\Components\Section::make('النشر')
                ->schema([
                    Forms\Components\Toggle::make('is_published')
                        ->label('نشر الموسم')
                        ->helperText('لن يُحفظ منشوراً إذا كانت هناك عناصر تقنية معلَّقة تمنع النشر العام - سيظهر تنبيه.'),
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
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
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