<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Project - Volunteer assignments
        Schema::create('project_volunteer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'completed'])->default('pending');
            $table->string('role')->nullable(); // team_leader, member
            $table->date('joined_at')->nullable();
            $table->integer('hours_contributed')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        // Ratings & Reviews
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('rater_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('rated_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('type', ['volunteer_rating', 'project_rating', 'owner_rating']);
            $table->tinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        // Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Donations / Resources
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('donor_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('type', ['money', 'materials', 'tools', 'food', 'other'])->default('money');
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('status')->default('received'); // received, used
            $table->timestamps();
        });

        // Project Updates / Progress Logs
        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->json('images')->nullable();
            $table->integer('progress_percentage');
            $table->timestamps();
        });

        // Volunteer Applications
        Schema::create('volunteer_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('message')->nullable();
            $table->json('offered_skills')->nullable();
            $table->integer('available_hours_per_week')->default(0);
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        // Announcements
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->enum('target', ['all', 'volunteers', 'owners'])->default('all');
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('volunteer_applications');
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('project_volunteer');
    }
};