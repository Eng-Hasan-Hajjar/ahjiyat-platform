<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E14 (بند 24-27): حقول مُهيكَلة فقط - صفر HTML/JS خام بأي حقل. حملة
 * واحدة قد تملك عدة Creatives (بند 122) - لذا مُستقلة لا مُدمَجة بالحملة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsor_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsor_campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('body', 280)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('destination_url');
            $table->string('image_path')->nullable();
            $table->string('alt_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['sponsor_campaign_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor_creatives');
    }
};
