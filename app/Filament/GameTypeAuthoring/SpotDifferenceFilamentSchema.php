<?php

namespace App\Filament\GameTypeAuthoring;

use App\Filament\Forms\Components\HotspotEditor;
use App\Filament\GameTypeAuthoring\Contracts\FilamentGameTypeSchema;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

class SpotDifferenceFilamentSchema implements FilamentGameTypeSchema
{
    public function fields(): array
    {
        $visible = fn (Get $get) => $get('game_type') === 'spot_difference';

        return [
            Forms\Components\FileUpload::make('game_config.image_before')
                ->label('الصورة الأولى (قبل)')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('puzzles/spot-difference')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096)
                ->visible($visible)
                ->required($visible)
                ->columnSpan(1),

            Forms\Components\FileUpload::make('game_config.image_after')
                ->label('الصورة الثانية (فيها الفروق)')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('puzzles/spot-difference')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096)
                ->visible($visible)
                ->required($visible)
                ->live()
                // لا مسح تلقائي للفروق عند استبدال الصورة - فقط تحذير واضح،
                // لأن مواقعها القديمة على الأرجح لم تعد صحيحة على الصورة الجديدة.
                ->afterStateUpdated(function (?string $state, ?string $old) {
                    if ($old !== null && $state !== $old) {
                        Notification::make()
                            ->warning()
                            ->title('تم تغيير الصورة الثانية')
                            ->body('قد لا تعود مواقع الفروقات القديمة صحيحة على الصورة الجديدة - راجعها أدناه، أو استخدم "مسح الكل" وابدأ من جديد.')
                            ->persistent()
                            ->send();
                    }
                })
                ->columnSpan(1),

            HotspotEditor::make('solution_data.hotspots')
                ->label('تحديد الفروقات (انقر على الصورة الثانية)')
                ->imageField('game_config.image_after')
                ->beforeImageField('game_config.image_before')
                ->visible($visible)
                ->rule(function () {
                    return function (string $attribute, $value, \Closure $fail) {
                        $error = SpotDifferenceHotspotValidation::firstError((array) $value);

                        if ($error !== null) {
                            $fail($error);
                        }
                    };
                })
                ->columnSpanFull(),

            // عدد الفروقات المطلوبة - Read-only دائماً، لأنها Derived بالكامل من
            // عدد الفروق المحدَّدة أعلاه (لا Column مستقل بالـDB - لا مصدرَي حقيقة).
            Forms\Components\Placeholder::make('required_differences_display')
                ->label('عدد الفروقات المطلوبة للفوز')
                ->content(fn (Get $get) => count((array) $get('solution_data.hotspots')).' فروق (تُحسب تلقائياً حسب عدد الفروق المحدَّدة أعلاه)')
                ->visible($visible)
                ->columnSpanFull(),
        ];
    }
}