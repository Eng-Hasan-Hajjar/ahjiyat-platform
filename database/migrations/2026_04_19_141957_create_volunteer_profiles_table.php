<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volunteer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('skills')->nullable(); // ['carpentry','electrical','plumbing','painting','masonry']
            $table->json('availability')->nullable(); // days & times
            $table->integer('hours_per_week')->default(0);
            $table->integer('total_hours_contributed')->default(0);
            $table->integer('points')->default(0);
            $table->string('experience_level')->default('beginner'); // beginner, intermediate, expert
            $table->text('certifications')->nullable();
            $table->boolean('has_vehicle')->default(false);
            $table->integer('travel_distance_km')->default(10);
            $table->integer('completed_projects')->default(0);
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteer_profiles');
    }
};