<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E20-D: بطولة فرق مبنية على أحداث E17/E19 الموجودة. status: draft|published|completed|cancelled (upcoming/live/ended مشتقة من الوقت). points_snapshot: لقطة هيكلية
 * (rank→points) تُؤخذ عند النشر من الإعدادات فلا يغيّر تعديل الإعدادات لاحقًا تاريخ بطولة. champion_team_id يُكتب مرة واحدة بالاعتماد. started_notified_at يمنع إعادة
 * إشعار البدء. restrictOnDelete للبطل: التاريخ لا يُمحى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_championships', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 80)->unique();
            $table->string('description', 1000)->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_featured')->default(false);
            $table->json('points_snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('champion_team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->timestamp('started_notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_championships');
    }
};
