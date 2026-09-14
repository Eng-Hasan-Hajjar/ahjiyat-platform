<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_gate_id')->constrained()->cascadeOnDelete();

            // نص وليس Database ENUM عمداً - إضافة kind مستقبلية (reflection,
            // external_event...) تبقى تغييراً على مستوى التطبيق فقط، بلا Migration
            // لتوسعة نوع العمود. القيم المدعومة فعلياً اليوم: narrative, puzzle.
            $table->string('kind');

            $table->unsignedInteger('sort_order');
            $table->string('title');
            $table->string('subtitle')->nullable();

            // محتوى سردي فقط (نص/وسائط) - لا يحتوي أبداً حل أي لعبة.
            $table->json('content')->nullable();

            // Restrict عمداً: حذف Puzzle مستخدمة داخل حملة تاريخية يكسرها بصمت -
            // نمنع الحذف صراحة بدل تركه يحدث بلا تحذير (بعكس GameSession/PuzzleAttempt
            // اللي تُحذف Cascade مع Puzzle بحق، لأنها بيانات تشغيلية بحتة لا هيكلية).
            $table->foreignId('puzzle_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('reward_mode')->default('inherit'); // inherit | override | none
            $table->unsignedInteger('reward_override_amount')->nullable();

            $table->timestamps();

            $table->index(['campaign_gate_id', 'sort_order']);

            // foreignId()->constrained() ينشئ FK Constraint فقط، وليس Index مستقلاً
            // على كل قاعدة بيانات - نضيفه صراحة لأن هذا العمود سيُستعلَم عنه مباشرة
            // لاحقاً (مثلاً: "أي خطوات حملة تستخدم هذه الأحجية؟").
            $table->index('puzzle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_steps');
    }
};