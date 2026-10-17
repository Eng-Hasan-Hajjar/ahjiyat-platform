<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E31: ترحيل ما قبل الإصلاح لـMySQL/MariaDB (لا يفعل شيئًا على SQLite ولا على أي قاعدة تعمل حاليًا).
 *
 * المشكلة: الترحيل 2026_09_23_000003_add_currency_id_to_wallets_table يحذف الفهرس الفريد wallets_user_id_unique(user_id) ثم يضيف
 * unique(user_id, currency_id) في جملة لاحقة. على MySQL هذا الفهرس هو الوحيد الذي يخدم المفتاح الأجنبي wallets_user_id_foreign،
 * فيفشل DROP INDEX بالخطأ 1553 (Cannot drop index needed in a foreign key constraint) ويتوقف migrate من الصفر عند هذه النقطة.
 *
 * الحل بلا تعديل الترحيل القديم: فهرس عادي مؤقت على user_id يضمنه هذا الترحيل قبل ذلك الترحيل (اسمه يسبقه أبجديًا ضمن نفس اليوم)،
 * فيجد MySQL فهرسًا بديلًا يخدم المفتاح الأجنبي أثناء حذف الفريد. ثم يحذفه ترحيل لاحق (2026_10_23_000001) بعد أن يصير unique(user_id, currency_id) موجودًا.
 */
return new class extends Migration
{
    public const GUARD_INDEX = 'wallets_user_id_e31_fk_index';

    public function up(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && (! Schema::hasTable('wallets') || Schema::hasIndex('wallets', self::GUARD_INDEX))) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->index('user_id', self::GUARD_INDEX);
        });
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $connection->pretending() && ! Schema::hasIndex('wallets', self::GUARD_INDEX)) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(self::GUARD_INDEX);
        });
    }
};
