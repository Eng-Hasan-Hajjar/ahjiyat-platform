<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E40 (1/4) - تعريف مسار الأحجيات (ahjiyat_passes) وشرائحه (ahjiyat_pass_tiers).
|
|  ahjiyat_passes
|     كيان مستقل تمامًا عن الموسم (لا season_id ولا أي ربط). status مخزَّن: draft|scheduled|active|ended|archived؛ **الحالة الفعلية تُشتقّ من التواريخ** في PassLifecycleService
|     (المخزَّن يُزامنه الأمر المجدول وعمليات الإدارة). live_slot = 1 فقط للمسار active المخزَّن، وunique(live_slot) يجعل «مسار فعّال واحد» قيدًا بقاعدة البيانات (NULL لا يتعارض).
|     claim_ends_at = ends_at + claim_grace_days (مشتقّ يُكتب مع كل تعديل مسودة ويُقفل بالنشر) لتبقى استعلامات «قابل للاستلام الآن» فهرسية.
|     السعر بالوحدة الصغرى (عدد صحيح) وعملة واحدة للمسار؛ كلاهما يُقفل بالنشر. theme_config قائمة بيضاء مغلقة (accent/background/tagline) لا HTML ولا CSS.
|  ahjiyat_pass_tiers
|     عتبات تراكمية: required_points تصاعدية صارمة والشريحة 1 عادةً 0. unique(pass, tier_number). تُحذف تتابعيًا مع **مسودة** لم تُنشر فقط (حارس النموذج يمنع حذف المسار المنشور).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ahjiyat_passes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('ahjiyat_passes_public_unique');
            $table->string('internal_key', 64)->unique('ahjiyat_passes_key_unique');
            $table->string('slug', 80)->unique('ahjiyat_passes_slug_unique');
            $table->string('name', 120);
            $table->string('subtitle', 160)->nullable();
            $table->text('description')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedTinyInteger('claim_grace_days')->default(5);
            $table->timestamp('claim_ends_at');

            $table->boolean('premium_enabled')->default(true);
            $table->unsignedBigInteger('premium_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('premium_sales_start_at')->nullable();
            $table->timestamp('premium_sales_end_at')->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedTinyInteger('live_slot')->nullable()->unique('ahjiyat_passes_live_slot_unique');
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('total_tiers')->default(0);

            $table->json('theme_config')->nullable();
            $table->string('banner_path', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at'], 'ahjiyat_passes_status_starts_index');
            $table->index(['starts_at', 'ends_at'], 'ahjiyat_passes_window_index');
            $table->index('claim_ends_at', 'ahjiyat_passes_claim_ends_index');
        });

        Schema::create('ahjiyat_pass_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->cascadeOnDelete();
            $table->unsignedSmallInteger('tier_number');
            $table->unsignedInteger('required_points');
            $table->string('label', 60)->nullable();
            $table->boolean('is_milestone')->default(false);
            $table->timestamps();

            $table->unique(['ahjiyat_pass_id', 'tier_number'], 'ahjiyat_pass_tiers_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ahjiyat_pass_tiers');
        Schema::dropIfExists('ahjiyat_passes');
    }
};
