<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| CIN-1 (3/3) - تقدّم اللاعب (قرار الخادم الوحيد).
|
|  cinematic_progress: صف لكل (مستخدم، قصة، نسخة منشورة): unique(user_id, story_id, revision_id). مثبَّت على النسخة التي بدأ عليها.
|  cinematic_scene_states: حالة كل مشهد ضمن التقدّم. unique(progress_id, scene_id) يمنع الإتمام المزدوج مهما تكرر الطلب؛ completed_at يُكتب مرة واحدة فقط.
|  لا أعمدة نقدية ولا ربط بالمحفظة: لا شيء هنا يمنح جواهر أو خبرة. حذف المستخدم يحذف تقدّمه (حق المحو)؛ النسخة والمشهد RESTRICT.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cinematic_progress', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('cinematic_progress_public_unique');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('story_id')->constrained('cinematic_stories')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('cinematic_revisions')->restrictOnDelete();
            $table->string('status', 16)->default('in_progress');
            $table->foreignId('current_scene_id')->nullable()->constrained('cinematic_scenes')->nullOnDelete();
            $table->unsignedBigInteger('migrated_from_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'story_id', 'revision_id'], 'cinematic_progress_owner_unique');
            $table->index('story_id', 'cinematic_progress_story_index');
            $table->index('revision_id', 'cinematic_progress_revision_index');
            $table->index('current_scene_id', 'cinematic_progress_scene_index');
        });

        Schema::create('cinematic_scene_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('progress_id')->constrained('cinematic_progress')->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained('cinematic_scenes')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_mode', 16)->nullable();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['progress_id', 'scene_id'], 'cinematic_scene_states_unique');
            $table->index('scene_id', 'cinematic_scene_states_scene_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cinematic_scene_states');
        Schema::dropIfExists('cinematic_progress');
    }
};
