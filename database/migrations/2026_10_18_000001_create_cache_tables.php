<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E24: جدولا الكاش (database cache store). config/cache.php و.env.example يعتمدان CACHE_STORE=database افتراضيًا
 * لكن المشروع لم يكن يملك migration لهما، فكل Cache::* (قفل المجدول withoutOverlapping/onOneServer، Cache::add، الإعدادات) كان ينهار أو يُبتلَع بصمت.
 * محمية بـ hasTable: آمنة على قاعدة أنشأتها أداة أخرى. لا تمسّ بيانات. مع Redis/file لا تُستعمل (وجودها غير مؤذٍ).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                // فهرس لحذف المنتهي (delete where expiration <= now) الذي يجريه مخزن الكاش دوريًا.
                $table->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
