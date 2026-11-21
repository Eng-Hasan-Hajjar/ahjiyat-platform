<?php

namespace App\Services\Cinematic\Admin;

use App\Models\CreatorSeries;
use App\Support\Search\ModelPicker;

/**
 * بحث «السلسلة المرتبطة» عند إنشاء قصة مميزة (E48): على الخادم ضمن كل سلاسل المبدعين لا أحدث 200 فقط (عبر ModelPicker العام).
 *
 * يبحث في العنوان المنشور والمعرّف العلني (كل كلمة يكفي أن تطابق أيًّا منهما). لا يكشف مالك السلسلة ولا أي بيانات خاصة:
 * الاسم + معرّف التمييز عند تكرار العنوان. الصلاحية (manage) يفحصها المستدعي؛ والتحقق من السلسلة عند الإنشاء يبقى في CinematicAuthoringService.
 */
final class CinematicSeriesPicker
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    public function search(string $term = '', int $offset = 0): array
    {
        return ModelPicker::make(fn () => CreatorSeries::query(), fn (CreatorSeries $s) => (string) ($s->published_title ?: $s->public_id))
            ->searchIn(['published_title', 'public_id'])
            ->orderBy('id', 'desc')
            ->distinguishDuplicates('published_title', fn (CreatorSeries $s) => 'معرّف '.$s->public_id)
            ->search($term, $offset);
    }
}
