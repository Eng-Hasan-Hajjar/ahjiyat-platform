<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * became_available_at: لحظة أول مرة صارت فيها الحملة "متاحة" - تُكتَب مرة واحدة فقط (CampaignLifecycleService) فلا
 * يتكرر CampaignBecameAvailable عند إعادة الإيقاف/التفعيل.
 *
 * تعبئة رجعية صامتة (استعلام خالص: بلا أحداث ولا إشعارات): كل حملة **أُتيحت فعلًا قبل الترحيل** (مفعَّلة +
 * starts_at ليس بالمستقبل)، سواء ما زالت متاحة أو انتهت، تُسجَّل كأنها أُتيحت - وإلا نُنبِّه اللاعبين عن حملة
 * قديمة عند أول مزامنة أو عند تمديد ends_at لاحقًا. غير المفعَّلة والقادمة تبقى null وتُنبَّه عند أول إتاحة فعلية.
 * (القاعدة لقطة ثابتة عمدًا ولا تستدعي كود التطبيق.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('became_available_at')->nullable()->after('ends_at');
        });

        $now = now();

        DB::table('campaigns')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->whereNull('became_available_at')
            ->update(['became_available_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('became_available_at');
        });
    }
};
