<?php

namespace App\Filament\Resources\EditorialShelfResource\Pages;

use App\Filament\Resources\EditorialShelfResource;
use App\Services\Editorial\EditorialShelfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditEditorialShelf extends EditRecord
{
    protected static string $resource = EditorialShelfResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items()->get(['content_type', 'content_id'])
            ->map(fn ($i) => ['type' => (string) $i->content_type, 'content_id' => (int) $i->content_id])->all();

        return $data;
    }

    /** حقول الرف ثم العناصر في معاملة واحدة عبر الخدمة (صلاحية + تدقيق + تفريغ كاش). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $items = collect((array) ($data['items'] ?? []))->map(fn ($r) => [(string) ($r['type'] ?? ''), $r['content_id'] ?? null])->values()->all();
        unset($data['items']);

        try {
            return DB::transaction(function () use ($record, $data, $items) {
                $service = app(EditorialShelfService::class);
                $updated = $service->update(auth()->user(), $record, $data);

                return $service->setItems(auth()->user(), $updated, $items);
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
