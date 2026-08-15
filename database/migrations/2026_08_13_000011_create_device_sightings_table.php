<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // سجل خفيف لكل IP/جهاز شفناه مرتبط بمستخدم - أساس اكتشاف الحسابات المتعددة
        Schema::create('device_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45);
            $table->string('device_hash', 64); // hash(user-agent + إعدادات أخرى غير حساسة)
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index(['ip_address']);
            $table->index(['device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sightings');
    }
};
