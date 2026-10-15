<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E27 - فهرسان فقط، كلٌّ لاستعلام بحث فعلي بادئته index-friendly (col LIKE 'prefix%'):
 *
 *  users(name)               - بحث اللاعبين بالبادئة (الجدول الأكبر نموًا بين مصادر البحث). بدونه يمسح كل المستخدمين لكل ضغطة اقتراح. كلفة الكتابة: عند التسجيل وتغيير الاسم فقط (نادر).
 *  puzzles(is_active, title) - بحث الأحجيات: المساواة على is_active ثم مدى على title؛ يطابق شرط قائمة /puzzles (is_active=1). كلفة الكتابة: عند نشر/تعديل أحجية (إداري، نادر).
 *
 * غير مشمول عمدًا (موثّق بـdocs/search-discovery-e27.md):
 *  - لا فهرس على puzzle_attempts: «الرائج» يبدأ نافذته بالبحث الثنائي على المفتاح الأساسي ضمن حساب مخزَّن 5 دقائق، فلا كلفة كتابة على أسخن جدول (قرار E24 باقٍ).
 *  - لا فهرس على campaigns/competitive_events/team_championships/teams(name): جداول صغيرة بطبيعتها (مئات صفوف)، والمطابقة الجزئية عليها مسح بحدّ معرّف أقصى (config search.partial_match_max_id).
 *  - لا FULLTEXT: الانتقال له أو لمحرك خارجي مؤشره بالمستند (حجم الجداول + زمن الاستعلام المقيس).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('users', 'users_name_search_index')) {
            Schema::table('users', fn (Blueprint $table) => $table->index('name', 'users_name_search_index'));
        }

        if (! Schema::hasIndex('puzzles', 'puzzles_active_title_search_index')) {
            Schema::table('puzzles', fn (Blueprint $table) => $table->index(['is_active', 'title'], 'puzzles_active_title_search_index'));
        }
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            if (Schema::hasIndex('puzzles', 'puzzles_active_title_search_index')) {
                $table->dropIndex('puzzles_active_title_search_index');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', 'users_name_search_index')) {
                $table->dropIndex('users_name_search_index');
            }
        });
    }
};
