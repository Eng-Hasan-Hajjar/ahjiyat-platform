<?php

namespace App\Filament\Resources\PuzzleLabExerciseResource\Pages;

use App\Filament\Resources\PuzzleLabExerciseResource;
use App\Models\PuzzleLabExercise;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePuzzleLabExercise extends CreateRecord
{
    protected static string $resource = PuzzleLabExerciseResource::class;

    protected static bool $canCreateAnother = false;

    /** الإنشاء دائمًا مسودة: لا يظهر للاعبين إلا بإجراء «نشر» الصريح. */
    protected function handleRecordCreation(array $data): Model
    {
        $exercise = new PuzzleLabExercise($data);

        // الحالة والمنشئ ليسا fillable عمدًا (لا يصلان من النموذج)، فتُسندان صراحةً هنا. (إسناد مباشر لا forceFill: حارس E32-98 يمنع نمط forceFill(['status' داخل Filament.)
        $exercise->status = PuzzleLabExercise::STATUS_DRAFT;
        $exercise->created_by = auth()->id();
        $exercise->updated_by = auth()->id();
        $exercise->save();

        return $exercise;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('حفظ كمسودة');
    }
}
