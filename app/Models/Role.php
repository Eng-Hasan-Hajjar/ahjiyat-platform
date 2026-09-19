<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * تمديد Role الأساسية من Spatie لإضافة Metadata عرضية فقط (تسمية عربية،
 * وصف، is_system، لون، ترتيب) - كل منطق الصلاحيات الفعلي يبقى كما هو
 * بالحزمة الأصلية دون أي تعديل. مُسجَّلة كـmodels.role بـconfig/permission.php
 * (راجع تعليمات التركيب) بدل الكلاس الافتراضي.
 */
class Role extends SpatieRole
{
    protected $fillable = [
        'name', 'guard_name', 'label_ar', 'description', 'is_system', 'color', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** التسمية المعروضة - العربية إن وُجدت، وإلا الاسم التقني نفسه. */
    public function displayLabel(): string
    {
        return $this->label_ar ?: $this->name;
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === 'super-admin';
    }
}