<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\CampaignResource\RelationManagers\StagesRelationManager;
use App\Models\Campaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * أعلى مستوى بالتسلسل الإداري: Campaign page → Manage Stages → Manage
 * Gates → Manage Steps. Stages/Gates/Steps تُدار عبر Resources متتالية
 * مخفية عن التنقّل الرئيسي - لا Giant Nested Form، ولا Visual Builder كامل.
 */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'الحملات';

    protected static ?string $navigationLabel = 'الحملات';

    protected static ?string $modelLabel = 'حملة';

    protected static ?string $pluralModelLabel = 'الحملات';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('العنوان')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state)))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('slug')
                ->label('المعرّف بالرابط (slug)')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('يُستخدم بالرابط العام - أحرف/أرقام/شرطات فقط.')
                ->columnSpanFull(),

            Forms\Components\Textarea::make('description')->label('الوصف')->columnSpanFull(),

            Forms\Components\FileUpload::make('cover_image')
                ->label('صورة الغلاف')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('campaigns/covers')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096),

            Forms\Components\Toggle::make('is_active')->label('مفعّلة')->default(false),

            Forms\Components\DateTimePicker::make('starts_at')->label('تاريخ البدء (اختياري)'),
            Forms\Components\DateTimePicker::make('ends_at')->label('تاريخ الانتهاء (اختياري)'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('الرابط'),
                Tables\Columns\IconColumn::make('is_active')->label('مفعّلة')->boolean(),
                Tables\Columns\TextColumn::make('starts_at')->label('البدء')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_at')->label('الانتهاء')->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('stages_count')->label('عدد المراحل')->counts('stages'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [StagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}