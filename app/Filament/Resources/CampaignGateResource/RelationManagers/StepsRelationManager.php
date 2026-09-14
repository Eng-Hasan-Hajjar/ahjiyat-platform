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
 * الطبقة الأدنى بالتسلسل - لا Resource خاص للخطوات (لا أبناء لها لتُدار).
 * الحقول الشرطية حسب kind/reward_mode تعتمد على Get/live() بنفس فلسفة
 * PuzzleResource تماماً. Puzzle.solution_data لا تظهر هون إطلاقاً (C7.6) -
 * فقط Select لأحجية موجودة مسبقاً.
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
                ])
                ->required()
                ->live()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title')->label('العنوان')->required()->columnSpanFull(),
            Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي')->columnSpanFull(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->required()->default(1),

            // ===== narrative فقط =====
            Forms\Components\Textarea::make('content.body')
                ->label('نص الخطوة')
                ->rows(5)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_NARRATIVE)
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('content.image')
                ->label('صورة (اختياري)')
                ->image()
                ->directory('campaigns/narrative')
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_NARRATIVE),

            // ===== puzzle فقط - اختيار أحجية موجودة حصراً، لا تحرير حلّها هون إطلاقاً (C7.6) =====
            Forms\Components\Select::make('puzzle_id')
                ->label('الأحجية')
                ->options(fn () => Puzzle::query()->where('is_active', true)->pluck('title', 'id'))
                ->searchable()
                ->required(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE)
                ->helperText('تعديل محتوى/حل الأحجية نفسها يتم من صفحة الأحجيات، لا هون.')
                ->columnSpanFull(),

            // ===== المكافأة - لكل الأنواع، لكن ذات معنى فعلي لخطوات puzzle فقط =====
            Forms\Components\Select::make('reward_mode')
                ->label('سياسة المكافأة')
                ->options([
                    CampaignStep::REWARD_MODE_INHERIT => 'وراثة مكافأة الأحجية الطبيعية',
                    CampaignStep::REWARD_MODE_OVERRIDE => 'قيمة مخصَّصة لهذه الخطوة',
                    CampaignStep::REWARD_MODE_NONE => 'بلا مكافأة إطلاقاً',
                ])
                ->default(CampaignStep::REWARD_MODE_INHERIT)
                ->required()
                ->live()
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE),

            Forms\Components\TextInput::make('reward_override_amount')
                ->label('قيمة المكافأة المخصَّصة (جواهر)')
                ->numeric()
                ->minValue(0)
                ->required(fn (Get $get) => $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE)
                ->visible(fn (Get $get) => $get('kind') === CampaignStep::KIND_PUZZLE
                    && $get('reward_mode') === CampaignStep::REWARD_MODE_OVERRIDE),
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
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === CampaignStep::KIND_PUZZLE ? 'أحجية' : 'سردية'),
                Tables\Columns\TextColumn::make('puzzle.title')->label('الأحجية')->placeholder('—'),
                Tables\Columns\TextColumn::make('reward_mode')->label('المكافأة')->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}