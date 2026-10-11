<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E24 - فهارس مُبرَّرة بـ EXPLAIN (PostgreSQL 16 محليًا كبديل تقريبي؛ الإنتاج المتوقع MySQL 8 - انظر docs/performance-e24.md).
 * كل فهرس مرتبط باستعلام فعلي وبسبب كلفة الكتابة:
 *
 *  puzzle_attempts(puzzle_id)          - عدّ المحاولات لكل أحجية (قائمة الإدارة attempts_count، إحصاءات الأحجية). كل الفهارس القائمة تبدأ بـ user_id فكان البحث بـ puzzle_id يمسح الجدول (على MySQL يوجد فهرس FK ضمني
 *                                         وهذا الفهرس الصريح يحلّ محلّه تلقائيًا فلا كلفة كتابة إضافية؛ على PostgreSQL لا يوجد فهرس للمفتاح الأجنبي أصلًا).
 *  puzzle_attempts(is_correct, user_id)- لوحة الصدارة: GROUP BY user_id لصفوف is_correct=1 كمسح فهرس مغطٍّ (1.5M صف: 1473ms مسح جدول كامل -> 294ms GroupAggregate بمسح فهرس فقط بعد VACUUM؛ قياس PostgreSQL محلي لا MySQL).
 *                                         كلفة الكتابة: إدخال إضافي بمفتاح ضيّق لكل محاولة (معدّل محدود بسرعة اللعب)؛ تُعوَّض بأن النتيجة مخزَّنة مؤقتًا ولا يُحسب إلا مرة بالدقيقة.
 *  users(created_at)                   - «آخر المسجَّلين» في مركز العمليات (ORDER BY created_at DESC LIMIT 8 كان يمسح ويرتّب كل المستخدمين) وتحليلات التسجيل بنافذة زمنية. الكتابة: عند التسجيل فقط.
 *  device_sightings(ip_address, user_id)         - عدّ الحسابات المختلفة لكل IP (كشف مشاركة IP): فهرس مغطٍّ بدل قراءة كل صفوف الـIP (IP شبكة مشتركة قد يضم آلاف الحسابات).
 *  device_sightings(user_id, ip_address, device_hash) - updateOrCreate لبصمة (مستخدم، IP، جهاز): بحث نقطي بدل فلترة كل صفوف الـIP. الكتابة صارت ≤ مرة/دقيقة لكل بصمة (E24 throttle).
 *
 * غير مشمول عمدًا (موثّق): حذف الفهارس المكرّرة بالبادئة (puzzle_attempts user_id+puzzle_id، context_lookup، device_sightings.ip_address، notifications morph) توصية P1 بعد التحقق على MySQL staging
 * لتعلّقها بقيود المفاتيح الأجنبية؛ لا فهارس created_at على puzzle_attempts/currency_transactions لتحليلات إدارية مخزَّنة (كلفة كتابة على أحَرّ جدولين بلا مبرّر قياس).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_attempts', function (Blueprint $table) {
            if (! Schema::hasIndex('puzzle_attempts', 'puzzle_attempts_puzzle_id_index')) {
                $table->index('puzzle_id', 'puzzle_attempts_puzzle_id_index');
            }
            if (! Schema::hasIndex('puzzle_attempts', 'puzzle_attempts_correct_user_index')) {
                $table->index(['is_correct', 'user_id'], 'puzzle_attempts_correct_user_index');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasIndex('users', 'users_created_at_index')) {
                $table->index('created_at', 'users_created_at_index');
            }
        });

        Schema::table('device_sightings', function (Blueprint $table) {
            if (! Schema::hasIndex('device_sightings', 'device_sightings_ip_user_index')) {
                $table->index(['ip_address', 'user_id'], 'device_sightings_ip_user_index');
            }
            if (! Schema::hasIndex('device_sightings', 'device_sightings_user_ip_device_index')) {
                $table->index(['user_id', 'ip_address', 'device_hash'], 'device_sightings_user_ip_device_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_sightings', function (Blueprint $table) {
            $table->dropIndex('device_sightings_user_ip_device_index');
            $table->dropIndex('device_sightings_ip_user_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_created_at_index');
        });

        Schema::table('puzzle_attempts', function (Blueprint $table) {
            $table->dropIndex('puzzle_attempts_correct_user_index');
            $table->dropIndex('puzzle_attempts_puzzle_id_index');
        });
    }
};
