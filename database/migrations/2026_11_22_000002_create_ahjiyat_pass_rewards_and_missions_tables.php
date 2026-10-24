<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E40 (2/4) - مكافآت الشرائح (ahjiyat_pass_tier_rewards) ومهام المسار (ahjiyat_pass_missions).
|
|  ahjiyat_pass_tier_rewards
|     مكافأة واحدة كحدّ أقصى لكل مسار (free|premium) لكل شريحة: unique(tier, track). المرجع عنصر متجر قائم (StoreItem) بـRESTRICT؛ لا محرك مكافآت ثانٍ ولا عملة ولا نقد.
|  ahjiyat_pass_missions
|     **وصلة إعداد** بين المسار وتعريف مهمة قائم (QuestDefinition) مع بيانات المسار: difficulty (إداري/عرض فقط، ليس مقيّمًا ثانيًا) وpass_points وmax_completions (سقف زراعة النقاط عبر فترات متعاقبة).
|     شرط المهمة وتقدّمها يبقيان حصرًا في QuestDefinition/UserQuestProgress. unique(pass, quest). quest_definition_id بـRESTRICT.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ahjiyat_pass_tier_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ahjiyat_pass_tier_id')->constrained('ahjiyat_pass_tiers')->cascadeOnDelete();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->cascadeOnDelete();
            $table->string('track', 8);
            $table->foreignId('store_item_id')->constrained('store_items')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['ahjiyat_pass_tier_id', 'track'], 'ahjiyat_pass_rewards_tier_track_unique');
            $table->index(['ahjiyat_pass_id', 'track'], 'ahjiyat_pass_rewards_pass_track_index');
        });

        Schema::create('ahjiyat_pass_missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ahjiyat_pass_id')->constrained('ahjiyat_passes')->cascadeOnDelete();
            $table->foreignId('quest_definition_id')->constrained('quest_definitions')->restrictOnDelete();
            $table->string('difficulty', 8);
            $table->unsignedInteger('pass_points');
            $table->unsignedSmallInteger('max_completions')->default(1);
            $table->unsignedTinyInteger('week_number')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['ahjiyat_pass_id', 'quest_definition_id'], 'ahjiyat_pass_missions_pass_quest_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ahjiyat_pass_missions');
        Schema::dropIfExists('ahjiyat_pass_tier_rewards');
    }
};
