<?php

namespace App\Policies;

use App\Models\User;

/**
 * E32 - طلبات الدفع (مالية). العرض في لوحة الإدارة sponsorships.view؛ كل الإجراءات (تحقق/رفض/توضيح/إلغاء/استرداد) sponsorships.confirm عبر SponsorshipPaymentService؛
 * تنزيل الإثبات (بيانات مالية/شخصية) لمن يملك sponsorships.confirm فقط. لا تعديل خام ولا حذف من أي واجهة: التاريخ المالي يبقى.
 * مالك الطلب يصل لطلبه عبر مسارات /support/* المقيَّدة بملكيته في الاستعلام نفسه (404 لغيره)؛ لا يمرّ عبر هذه السياسة.
 */
class SponsorshipPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsorships.view');
    }

    public function view(User $user, $model): bool
    {
        return $user->can('sponsorships.view');
    }

    public function downloadProof(User $user, $model): bool
    {
        return $user->can('sponsorships.confirm');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }
}
