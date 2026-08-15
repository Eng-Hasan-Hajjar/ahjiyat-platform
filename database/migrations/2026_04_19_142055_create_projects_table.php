<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->enum('type', [
                'shop', 'workshop', 'clinic', 'bakery', 'restaurant',
                'school', 'mosque', 'pharmacy', 'other'
            ])->default('other');
            $table->enum('status', [
                'pending', 'approved', 'in_progress', 'completed', 'cancelled', 'rejected'
            ])->default('pending');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->integer('damage_percentage')->default(0); // 0-100
            $table->string('address');
            $table->string('city');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('required_skills')->nullable();
            $table->integer('volunteers_needed')->default(1);
            $table->integer('volunteers_assigned')->default(0);
            $table->integer('estimated_days')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->text('before_images')->nullable(); // JSON array of image paths
            $table->text('after_images')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('estimated_cost', 10, 2)->default(0);
            $table->decimal('actual_cost', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};