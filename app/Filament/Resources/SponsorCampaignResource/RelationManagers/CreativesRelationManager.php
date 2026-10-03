<?php

namespace App\Filament\Resources\SponsorCampaignResource\RelationManagers;

use App\Models\SponsorCreative;
use App\Services\Advertising\SponsorCampaignService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E14 (بند 24-27/339/469): حقول مُهيكَلة فقط - صفر HTML/Script. المعاينة
 * (ViewAction) تُعيد استخدام نفس مكوِّن العرض الآمن، لا `{!! !!}` خام.
 */
class CreativesRelationManager extends RelationManager
{
    protected static string $relationship = 'creatives';

    protected static ?string $title = 'المواد الإعلانية';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required()->maxLength(255),
            Forms\Components\Textarea::make('body')->label('نص قصير')->maxLength(280),
            Forms\Components\TextInput::make('cta_label')->label('نص الزر')->maxLength(40),
            Forms\Components\TextInput::make('destination_url')->label('رابط الوجهة')
                ->required()->url()->maxLength(2048)
                ->helperText('HTTPS فقط - يُرفَض أي بروتوكول آخر.')
                ->extraInputAttributes(['dir' => 'ltr']),
            Forms\Components\FileUpload::make('image_path')->label('صورة (اختياري)')
                ->image()->disk('public')->directory('sponsor-creatives')
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(1024),
            Forms\Components\TextInput::make('alt_text')->label('نص بديل للصورة (Alt Text)')->maxLength(255),
            Forms\Components\Toggle::make('is_active')->label('نشطة')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('العنوان'),
                Tables\Columns\TextColumn::make('destination_url')->label('الرابط')->limit(40),
                Tables\Columns\IconColumn::make('is_active')->label('نشطة')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (SponsorCreative $record, array $data) {
                        app(SponsorCampaignService::class)->updateCreative($record, $data, auth()->user());

                        return $record->fresh();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
