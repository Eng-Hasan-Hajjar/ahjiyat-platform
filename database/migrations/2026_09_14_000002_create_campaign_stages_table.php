<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();

            // بدون unique عمداً - إعادة الترتيب لاحقاً (C7 Filament) تحتاج تبديل
            // قيمتين مؤقتاً أثناء الحفظ، وunique صارم يكسر ذلك بمعاملة غير ذرية.
            $table->unsignedInteger('sort_order');

            $table->timestamps();

            $table->index(['campaign_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_stages');
    }
};