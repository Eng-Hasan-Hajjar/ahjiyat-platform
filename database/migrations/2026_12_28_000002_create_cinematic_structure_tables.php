<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| CIN-1 (2/3) - بنية المحتوى داخل نسخة: مراحل ← فصول ← مشاهد (+ حلول المشاهد المشفّرة).
|
|  stable_key: مفتاح ثابت يُنسخ مع النسخة الجديدة ليُطابَق التقدّم بين النسخ؛ unique ضمن النسخة.
|  position: ترتيب عادي (فهرس غير فريد: إعادة الترتيب تُطبَّع في معاملة، بلا تصادم قيود).
|  logic_hash: بصمة منطق المشهد (النوع+الإعداد+الحل+الأحجية). تغيّرها = لا يُحمل إتمام المشهد إلى نسخة جديدة.
|  cinematic_scene_solutions: الحل خادمي فقط (payload مشفّر). صف منفصل حتى لا يظهر بأي select/serialize على المشهد.
|  الحذف: حذف نسخة مسودة يحذف بنيتها (CASCADE)؛ نسخة عليها تقدّم لاعبين لا تُحذف (RESTRICT من جداول التقدّم). الأحجية RESTRICT. الوسائط SET NULL.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinematic_stages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_stages_public_unique');
            $table->foreignId('revision_id')->constrained('cinematic_revisions')->cascadeOnDelete();
            $table->string('stable_key', 64);
            $table->unsignedSmallInteger('position');
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->unique(['revision_id', 'stable_key'], 'cinematic_stages_key_unique');
            $table->index(['revision_id', 'position'], 'cinematic_stages_order_index');
        });

        Schema::create('cinematic_chapters', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_chapters_public_unique');
            $table->foreignId('revision_id')->constrained('cinematic_revisions')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('cinematic_stages')->cascadeOnDelete();
            $table->string('stable_key', 64);
            $table->unsignedSmallInteger('position');
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->unique(['revision_id', 'stable_key'], 'cinematic_chapters_key_unique');
            $table->index(['stage_id', 'position'], 'cinematic_chapters_order_index');
        });

        Schema::create('cinematic_scenes', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_scenes_public_unique');
            $table->foreignId('revision_id')->constrained('cinematic_revisions')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('cinematic_stages')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('cinematic_chapters')->cascadeOnDelete();
            $table->string('stable_key', 64);
            $table->unsignedSmallInteger('position');
            $table->string('type', 16);
            $table->string('title', 160);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_skippable')->default(false);
            $table->boolean('allow_replay')->default(true);
            $table->boolean('awaiting_media')->default(false);
            $table->unsignedSmallInteger('duration_hint_seconds')->nullable();
            $table->json('unlock_rule')->nullable();
            $table->json('config')->nullable();
            $table->char('logic_hash', 64);
            $table->foreignId('puzzle_id')->nullable()->constrained('puzzles')->restrictOnDelete();
            $table->foreignId('video_asset_id')->nullable()->constrained('cinematic_media_assets')->nullOnDelete();
            $table->foreignId('poster_asset_id')->nullable()->constrained('cinematic_media_assets')->nullOnDelete();
            $table->foreignId('subtitle_asset_id')->nullable()->constrained('cinematic_media_assets')->nullOnDelete();
            $table->foreignId('audio_asset_id')->nullable()->constrained('cinematic_media_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['revision_id', 'stable_key'], 'cinematic_scenes_key_unique');
            $table->index(['chapter_id', 'position'], 'cinematic_scenes_order_index');
            $table->index('stage_id', 'cinematic_scenes_stage_index');
            $table->index('puzzle_id', 'cinematic_scenes_puzzle_index');
            $table->index('video_asset_id', 'cinematic_scenes_video_index');
            $table->index('poster_asset_id', 'cinematic_scenes_poster_index');
            $table->index('subtitle_asset_id', 'cinematic_scenes_subtitle_index');
            $table->index('audio_asset_id', 'cinematic_scenes_audio_index');
        });

        Schema::create('cinematic_scene_solutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained('cinematic_scenes')->cascadeOnDelete();
            $table->text('payload');
            $table->timestamps();

            $table->unique('scene_id', 'cinematic_scene_solutions_scene_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinematic_scene_solutions');
        Schema::dropIfExists('cinematic_scenes');
        Schema::dropIfExists('cinematic_chapters');
        Schema::dropIfExists('cinematic_stages');
    }
};
