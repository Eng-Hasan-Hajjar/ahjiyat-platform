<?php

namespace App\Filament\Resources\PuzzleLabExerciseResource\Pages;

use App\Filament\Resources\PuzzleLabExerciseResource;
use App\Models\PuzzleLabExercise;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPuzzleLabExercise extends EditRecord
{
    protected static string $resource = PuzzleLabExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // المعاينة تفتح صفحة اللاعب نفسها بصلاحية الإدارة بلا نشر؛ تعرض آخر نسخة محفوظة.
            Actions\Action::make('preview')
                ->label('معاينة (آخر نسخة محفوظة)')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn () => route('puzzle-lab.preview', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('publish')
                ->label('حفظ ونشر للاعبين')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('سيُحفظ ما كتبته ثم يظهر التمرين للاعبين في مختبر الأحجيات.')
                ->visible(fn () => ! $this->record->isPublished() && (auth()->user()?->can('publish', $this->record) ?? false))
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    PuzzleLabExerciseResource::publishRecord($this->record->refresh());
                    $this->refreshFormData(['status', 'published_at']);
                }),

            Actions\Action::make('hide')
                ->label('إخفاء عن اللاعبين')
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('سيختفي التمرين من قائمة اللاعبين، ويمكن نشره لاحقًا.')
                ->visible(fn () => $this->record->isPublished() && (auth()->user()?->can('publish', $this->record) ?? false))
                ->action(function () {
                    PuzzleLabExerciseResource::hideRecord($this->record);
                    $this->refreshFormData(['status']);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /** تعديل تمرين ظاهر لا يجوز أن يتركه بمحتوى غير صالح: يُرفض الحفظ برسالة واضحة بدل أن يظهر للاعبين معطوبًا. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill($data);
        $record->forceFill(['updated_by' => auth()->id()]);

        if ($record->isPublished() && ($problems = $record->contentProblems()) !== []) {
            Notification::make()->danger()->title('لم يُحفظ التعديل: التمرين ظاهر للاعبين ولا يجوز أن يصبح غير مكتمل')
                ->body(implode("\n", $problems))->persistent()->send();

            $this->halt();
        }

        $record->save();

        return $record;
    }
}
