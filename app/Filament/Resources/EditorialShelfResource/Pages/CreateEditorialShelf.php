<?php

namespace App\Filament\Resources\EditorialShelfResource\Pages;

use App\Filament\Resources\EditorialShelfResource;
use App\Services\Editorial\EditorialShelfService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateEditorialShelf extends CreateRecord
{
    protected static string $resource = EditorialShelfResource::class;

    /** يُنشأ الرف مسوّدة ثم تُحفظ عناصره في معاملة واحدة: فشل العناصر يتراجع عن الرف فلا يبقى رف نصف مكتمل. */
    protected function handleRecordCreation(array $data): Model
    {
        $items = $this->normalizeItems($data['items'] ?? []);
        unset($data['items']);

        try {
            return DB::transaction(function () use ($data, $items) {
                $service = app(EditorialShelfService::class);
                $shelf = $service->create(auth()->user(), $data);

                return $items === [] ? $shelf : $service->setItems(auth()->user(), $shelf, $items);
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    /** @return list<array{0: string, 1: int|string}> */
    protected function normalizeItems(mixed $rows): array
    {
        return collect((array) $rows)->map(fn ($r) => [(string) ($r['type'] ?? ''), $r['content_id'] ?? null])->values()->all();
    }
}
