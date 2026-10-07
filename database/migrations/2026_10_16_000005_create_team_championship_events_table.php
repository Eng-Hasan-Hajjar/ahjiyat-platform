<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** E20-D3: ربط البطولة بأحداث تنافسية (ربط واحد لكل زوج). يُدار بخدمة المجال فقط وللمسودة وحدها (يُقفل بعد النشر). الحدث restrictOnDelete: لا يُحذف حدث مرتبط ببطولة. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_championship_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_championship_id')->constrained('team_championships')->cascadeOnDelete();
            $table->foreignId('competitive_event_id')->constrained('competitive_events')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['team_championship_id', 'competitive_event_id'], 'tce_pair_unique');
            $table->index('competitive_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_championship_events');
    }
};
