<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // ترتيب المرحلة ضمن تسلسل المشروع
            $table->unsignedInteger('order_index')->default(0);

            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');

            $table->date('planned_date')->nullable();
            $table->date('completed_date')->nullable();

            // من أنشأ المرحلة (صاحب المشروع غالباً)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};