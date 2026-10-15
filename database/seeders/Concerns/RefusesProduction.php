<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * E25 - يمنع seeders البيانات التجريبية من العمل بالإنتاج (APP_ENV=production): تزرع حسابات بكلمات مرور معروفة (password / change-me-now) وبيانات وهمية.
 * الإنتاج يستعمل seeders محددة فقط (RolesAndPermissionsSeeder، AdminUserSeeder مع ADMIN_BOOTSTRAP_*) - انظر docs/first-production-deploy.md.
 */
trait RefusesProduction
{
    protected function refuseInProduction(string $what): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException("{$what} ممنوع بالإنتاج: يزرع حسابات/بيانات تجريبية بكلمات مرور معروفة. شغّل بدلًا منه seeders المحددة فقط: RolesAndPermissionsSeeder ثم AdminUserSeeder (مع ADMIN_BOOTSTRAP_EMAIL/PASSWORD) - docs/first-production-deploy.md");
        }
    }
}
