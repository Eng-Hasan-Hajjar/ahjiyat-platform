<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| CIN-1 (1/3) - مكتبة وسائط السلاسل السينمائية + القصص + نسخها.
|
|  cinematic_media_assets: ملفات خاصة (فيديو/ملصق/ترجمة/صوت) بعد فحص نوعها الحقيقي وخصائصها. لا يُخدم أي ملف مباشرة: المسار المحروس فقط.
|    unique(role, sha256): الملف نفسه لا يُخزَّن مرتين في الدور نفسه (إعادة الرفع تُرجع الموجود).
|  cinematic_stories: القصة (غلاف تنظيمي ثابت). «قصيرة/طويلة» وسم تنظيمي فقط. السلسلة المميزة تُربط بـcreator_series للقراءة فقط (ملكية E43).
|  cinematic_revisions: نسخة المحتوى (مسودة ← مراجعة ← منشورة ← مستبدلة/مسحوبة). نسخة منشورة واحدة لكل قصة يفرضها unique(story_id, live_slot)
|    (live_slot=1 للمنشورة، وNULL لغيرها؛ NULL متعددة مسموحة). المحتوى (مراحل/فصول/مشاهد) محصور بنسخة ولا يتغير بعد نشرها.
|  الحذف: كل المفاتيح RESTRICT للتاريخ؛ الوسائط SET NULL (سحب ملف لا يكسر مشهدًا بل يجعله «ينتظر وسيطًا»). لا أعمدة نقدية ولا عشرية.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinematic_media_assets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_media_public_unique');
            $table->string('role', 16);
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name', 180);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('video_codec', 16)->nullable();
            $table->string('audio_codec', 16)->nullable();
            $table->string('status', 16)->default('ready');
            $table->json('meta')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['role', 'sha256'], 'cinematic_media_role_hash_unique');
            $table->index(['status', 'role'], 'cinematic_media_status_role_index');
            $table->index('uploaded_by', 'cinematic_media_uploader_index');
        });

        Schema::create('cinematic_stories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_stories_public_unique');
            $table->string('slug', 120)->unique('cinematic_stories_slug_unique');
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->string('label_kind', 16)->default('long');
            $table->string('access_mode', 16)->default('free');
            $table->foreignId('creator_series_id')->nullable()->constrained('creator_series')->restrictOnDelete();
            $table->foreignId('cover_asset_id')->nullable()->constrained('cinematic_media_assets')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('creator_series_id', 'cinematic_stories_series_index');
            $table->index('cover_asset_id', 'cinematic_stories_cover_index');
            $table->index('created_by', 'cinematic_stories_creator_index');
        });

        Schema::create('cinematic_revisions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_revisions_public_unique');
            $table->foreignId('story_id')->constrained('cinematic_stories')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 16)->default('draft');
            $table->unsignedTinyInteger('live_slot')->nullable();
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('readiness')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'revision_number'], 'cinematic_revisions_number_unique');
            $table->unique(['story_id', 'live_slot'], 'cinematic_revisions_live_unique');
            $table->index('status', 'cinematic_revisions_status_index');
            $table->index('created_by', 'cinematic_revisions_creator_index');
            $table->index('published_by', 'cinematic_revisions_publisher_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinematic_revisions');
        Schema::dropIfExists('cinematic_stories');
        Schema::dropIfExists('cinematic_media_assets');
    }
};
