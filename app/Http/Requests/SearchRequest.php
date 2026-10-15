<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * E27: مدخلات البحث العام. **متسامحة عمدًا**: لا تفشل بالتحقق ولا تُعيد توجيهًا بأخطاء (صفحة بحث بمدخل غريب تُعرض فارغة بدل 422).
 * مصفوفات/أنواع غير نصية (?q[]=x) تُعامَل كفراغ. الطول والتطبيع والحدود تفرضها خدمة البحث (SearchQuery) لا هنا.
 */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function term(): string
    {
        $term = $this->query('q');

        return is_string($term) ? $term : '';
    }

    public function typeFilter(): ?string
    {
        $type = $this->query('type');

        return is_string($type) && $type !== '' && strlen($type) <= 32 ? $type : null;
    }

    public function pageNumber(): int
    {
        $page = $this->query('page');

        return is_string($page) && ctype_digit($page) && strlen($page) <= 4 ? max(1, (int) $page) : 1;
    }
}
