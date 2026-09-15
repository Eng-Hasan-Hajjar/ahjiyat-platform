<?php

namespace App\Filament\Resources\CampaignGateResource\RelationManagers;

use App\Models\CampaignStep;
use App\Models\Puzzle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * الطبقة الأدنى بالتسلسل - لا Resource خاص للخطوات. الحقول الشرطية حسب
 * kind/reward_mode عبر Get/live() (نفس فلسفة PuzzleResource). D2 يضيف:
 * kind=reflection (Prompt/Min/Max)، حقول Media عامة (image/audio) لكل
 * الأنواع، وContent Status إداري (لا يُقرأ بمنطق الإكمال إطلاقاً).
 * Puzzle.solution_data لا تظهر هون إطلاقاً - فقط Select لأحجية موجودة مسبقاً.
 */
class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'الخطوات (Steps)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label('نوع الخطوة')
                ->options([
                    CampaignStep::KIND_NARRATIVE => 'سردية (Narrative)',
                    CampaignStep::KIND_PUZZLE => 'أحجية (Puzzle)',
                    CampaignStep::KIND_REFLECTION => 'تأمّل (Reflection)',
                ])
                ->required()->live()->columnSpanFull(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required()->default(1),

            // ===== narrative فقط =====
            Forms\Components\Textarea::make('content.body')
                ->label('نص الخطوة')->rows(5)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_NARRATIVE)
                ->columnSpanFull(),

            // ===== reflection فقط (D2) =====
            Forms\Components\Textarea::make('content.prompt')
                ->label('سؤال التأمّل')->rows(3)
                ->required(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('content.min_chars')
                ->label('الحد الأدنى لعدد الأحرف')->numeric()->minValue(1)->default(10)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION),
            Forms\Components\TextInput::make('content.max_chars')
                ->label('الحد الأقصى لعدد الأحرف')->numeric()->minValue(1)->default(2000)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_REFLECTION),

            // ===== Media عامة (D2) - لكل الأنواع، اختيارية =====
            Forms\Components\Section::make('وسائط مرافقة (اختياري)')
                ->schema([
                    Forms\Components\Select::make('content.media_type')
                        ->label('نوع الوسائط')
                        ->options(['image' => 'صورة', 'audio' => 'مقطع صوتي'])
                        ->native(false),
                    Forms\Components\FileUpload::make('content.media_path')
                        ->label('الملف')
                        ->directory('campaigns/media')
                        ->disk('public'),
                    Forms\Components\TextInput::make('content.caption')->label('تعليق توضيحي (اختياري)')->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsed()
                ->collapsible(),

            // ===== puzzle فقط - اختيار أحجية موجودة حصراً =====
            Forms\Components\Select::make('puzzle_id')
                ->label('الأحجية')
                ->options(fn () => Puzzle::query()->where('is_active', true)->pluck('title', 'id'))
                ->searchable()
                ->required(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->helperText('تعديل محتوى/حل الأحجية نفسها يتم من صفحة الأحجيات، لا هون.')
                ->columnSpanFull(),

            // ===== المكافأة =====
            Forms\Components\Select::make('reward_mode')
                ->label('سياسة المكافأة')
                ->options([
                    CampaignStep::REWARD_MODE_INHERIT => 'وراثة مكافأة الأحجية الطبيعية',
                    CampaignStep::REWARD_MODE_OVERRIDE => 'قيمة مخصَّصة لهذه الخطوة',
                    CampaignStep::REWARD_MODE_NONE => 'بلا مكافأة إطلاقاً',
                ])
                ->default(CampaignStep::REWARD_MODE_INHERIT)->required()->live()
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE),
            Forms\Components\TextInput::make('reward_override_amount')
                ->label('قيمة المكافأة المخصَّصة (جواهر)')->numeric()->minValue(0)
                ->required(fn (Get $get) => $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE
                    && $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE),

            // ===== Content Status - إداري بحت، لا يُقرأ بمنطق اللعب =====
            Forms\Components\Select::make('content.status')
                ->label('حالة المحتوى')
                ->options([
                    CampaignStep::CONTENT_STATUS_FINAL => 'نهائي',
                    CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'محتوى مؤقت (Placeholder)',
                    CampaignStep::CONTENT_STATUS_CONTENT_PENDING => 'بانتظار محتوى العميل',
                    CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'بانتظار قرار تقني (يمنع النشر العام)',
                ])
                ->default(CampaignStep::CONTENT_STATUS_FINAL)
                ->native(false)
                ->helperText('يُعرض للإدارة فقط أبداً - لا يظهر لأي لاعب.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('العنوان'),
                Tables\Columns\TextColumn::make('kind')
                    ->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        CampaignStep::KIND_PUZZLE => 'أحجية',
                        CampaignStep::KIND_REFLECTION => 'تأمّل',
                        default => 'سردية',
                    }),
                Tables\Columns\TextColumn::make('puzzle.title')->label('الأحجية')->placeholder('—'),
                Tables\Columns\TextColumn::make('content_status_display')
                    ->label('حالة المحتوى')
                    ->state(fn (CampaignStep $record) => match ($record->contentStatus()) {
                        CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'قرار تقني معلَّق',
                        CampaignStep::CONTENT_STATUS_CONTENT_PENDING => 'بانتظار محتوى',
                        CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'مؤقت',
                        default => 'نهائي',
                    })
                    ->badge()
                    ->color(fn (CampaignStep $record) => match ($record->contentStatus()) {
                        CampaignStep::CONTENT_STATUS_TECHNICAL_PENDING => 'danger',
                        CampaignStep::CONTENT_STATUS_CONTENT_PENDING, CampaignStep::CONTENT_STATUS_PLACEHOLDER => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}