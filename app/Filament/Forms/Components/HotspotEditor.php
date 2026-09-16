<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * محرِّر مرئي عام لمواقع الفروق (Hotspots) لأي أحجية game_type=spot_difference -
 * ليس خاصاً بأصيل ولا بأي موسم. الحالة (Array of {x,y,radius}) محفوظة بنفس
 * Column القديم (solution_data.hotspots) بلا أي تغيير Schema - أي بيانات
 * قديمة مُدخَلة يدوياً تظهر تلقائياً هنا.
 */
class HotspotEditor extends Field
{
    protected string $view = 'filament.forms.components.hotspot-editor';

    /** State Path لحقل الصورة "بعد" التي يُرسَم فوقها المحرِّر (إلزامية). */
    protected string $imageStatePath = 'game_config.image_after';

    /** State Path لحقل الصورة "قبل" (اختياري) - لعرض جنبًا-إلى-جنب فقط. */
    protected ?string $beforeImageStatePath = null;

    public function imageField(string $statePath): static
    {
        $this->imageStatePath = $statePath;

        return $this;
    }

    public function beforeImageField(string $statePath): static
    {
        $this->beforeImageStatePath = $statePath;

        return $this;
    }

    public function getImageStatePath(): string
    {
        return $this->imageStatePath;
    }

    public function getBeforeImageStatePath(): ?string
    {
        return $this->beforeImageStatePath;
    }
}