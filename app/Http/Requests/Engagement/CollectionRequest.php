<?php

namespace App\Http\Requests\Engagement;

use App\Services\Engagement\CollectionService;
use Illuminate\Foundation\Http\FormRequest;

/** E29 - إنشاء/تعديل مجموعة. القواعد مصدرها واحد (CollectionService::rules): عنوان ووصف نص عادي بلا وسوم. */
class CollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CollectionService::rules();
    }

    public function attributes(): array
    {
        return ['title' => 'عنوان المجموعة', 'description' => 'وصف المجموعة'];
    }
}
