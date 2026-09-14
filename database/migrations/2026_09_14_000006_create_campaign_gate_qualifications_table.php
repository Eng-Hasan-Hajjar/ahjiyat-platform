<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_gate_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_gate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // NULL = تأهّل غير مشروط (بلا ترتيب FirstN). SQL يعتبر كل NULL مختلفاً
            // عن غيره حتى ضمن Unique، فتعدد صفوف بنفس البوابة برتبة NULL لا يتعارض
            // أبداً مع unique(campaign_gate_id, rank) - هذا هو السلوك المطلوب بالضبط.
            // الحماية الفعلية من تكرار نفس المستخدم تبقى unique(campaign_gate_id, user_id).
            $table->unsignedInteger('rank')->nullable();
            $table->timestamp('qualified_at');

            $table->timestamps();

            $table->unique(['campaign_gate_id', 'user_id']);
            $table->unique(['campaign_gate_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_gate_qualifications');
    }
};