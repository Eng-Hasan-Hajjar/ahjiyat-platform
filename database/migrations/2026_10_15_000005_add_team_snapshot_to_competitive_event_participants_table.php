<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E19-D: لقطة الفريق عند **التسجيل** بالحدث (أول نقطة موثوقة عند E17: الصف يُنشأ ذريًا مع حجز المقعد). ترتيب الفرق التاريخي يقرأ هذه اللقطة لا العضوية الحالية.
 * nullable: من سجّل قبل E19 أو بلا فريق = NULL ولا ربط تخميني ولا Backfill. restrictOnDelete: لا يُحذف فريق له تاريخ تنافسي (يُعطَّل فقط).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitive_event_participants', function (Blueprint $table) {
            $table->foreignId('team_id_snapshot')->nullable()->after('user_id')->constrained('teams')->restrictOnDelete();
            $table->index(['competitive_event_id', 'team_id_snapshot'], 'cep_event_team_idx');
        });
    }

    public function down(): void
    {
        Schema::table('competitive_event_participants', function (Blueprint $table) {
            $table->dropIndex('cep_event_team_idx');
            $table->dropConstrainedForeignId('team_id_snapshot');
        });
    }
};
