<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E31: يزيل الفهرس المؤقت الذي أضافه 2026_09_23_000002_e31_guard_wallets_user_fk_index (MySQL/MariaDB فقط).
 * بعد الترحيل 2026_09_23_000003 صار unique(user_id, currency_id) يخدم المفتاح الأجنبي على user_id (user هو أول أعمدته)، فالفهرس المؤقت زائد
 * (يبطّئ الكتابة بلا فائدة). لا يُحذف إلا إذا وُجد فهرس آخر يبدأ بـuser_id، حتى لا يُترك المفتاح الأجنبي بلا فهرس (الخطأ 1553).
 * لا شيء على SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $guard = 'wallets_user_id_e31_fk_index';

        if (! $connection->pretending()) {
            if (! Schema::hasTable('wallets')) {
                return;
            }

            $indexes = Schema::getIndexes('wallets');
            $hasGuard = collect($indexes)->contains(fn (array $index) => $index['name'] === $guard);
            $covered = collect($indexes)->contains(fn (array $index) => $index['name'] !== $guard && ($index['columns'][0] ?? null) === 'user_id');

            if (! $hasGuard || ! $covered) {
                return;
            }
        }

        Schema::table('wallets', function (Blueprint $table) use ($guard) {
            $table->dropIndex($guard);
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && Schema::hasIndex('wallets', 'wallets_user_id_e31_fk_index')) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->index('user_id', 'wallets_user_id_e31_fk_index');
        });
    }
};
