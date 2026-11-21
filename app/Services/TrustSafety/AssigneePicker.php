<?php

namespace App\Services\TrustSafety;

use App\Models\User;
use App\Support\Search\ModelPicker;

/**
 * بحث قائمة «إعادة تعيين الحالة» (مشرفو الحالات) على الخادم: بدل تحميل أول 100 مشرف في <select>.
 *
 * مستهلك للمكوّن المشترك <x-searchable-select> خارج السلاسل السينمائية (E48) عبر ModelPicker العام. الصلاحية يفحصها المستدعي
 * (صفحة الحالة: trust-safety.supervise)، والخدمة CaseService::assign تعيد فحص الصلاحية وأن المُعيَّن يملك manage-cases وألا يتضارب
 * مع الحالة عند الحفظ، فاختيار القائمة وحده لا يعيّن أحدًا. المخرجات: الاسم، ومعرّف التمييز فقط عند تكرار الاسم (لا بريد ولا بيانات أخرى).
 */
final class AssigneePicker
{
    public const PERMISSION = 'trust-safety.manage-cases';

    /** هل يوجد أي مشرف يمكن التعيين إليه (لإظهار النموذج دون تحميل القائمة). */
    public function any(): bool
    {
        return $this->picker()->exists();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, next: int|null}
     */
    public function search(string $term = '', int $offset = 0): array
    {
        return $this->picker()->search($term, $offset);
    }

    /** المُعيَّن المختار يجب أن يكون ضمن المشرفين (الخدمة تتحقق أيضًا)؛ null = لا أحد. */
    public function find(int $id): ?User
    {
        $user = $this->picker()->find($id);

        return $user instanceof User ? $user : null;
    }

    private function picker(): ModelPicker
    {
        return ModelPicker::make(fn () => User::query()->permission(self::PERMISSION), fn (User $u) => (string) $u->name)
            ->searchIn(['name'])
            ->orderBy('name')
            ->distinguishDuplicates('name', fn (User $u) => 'معرّف '.$u->id);
    }
}
