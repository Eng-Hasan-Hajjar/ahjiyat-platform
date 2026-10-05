<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * went_live_at: لحظة أول مرة صار فيها الموسم "مباشرًا" - تُكتَب مرة واحدة فقط (SeasonLifecycleService) فلا
 * يتكرر SeasonStarted عند إعادة الإيقاف/التفعيل.
 *
 * تعبئة رجعية (بلا أحداث وبلا إشعارات - استعلامات قاعدة بيانات خالصة): كل موسم **بدأ فعلًا قبل الترحيل**
 * (منشور + حملته مفعَّلة + starts_at ليس بالمستقبل) يُسجَّل كأنه بدأ، سواء ما زال مباشرًا أو انتهى. بلا ذلك
 * سيُنبَّه اللاعبون "بدأ الموسم" عن موسم قديم عند أول مزامنة، أو عند تمديد ends_at لموسم منتهٍ لاحقًا.
 * (قاعدة "بدأ" هنا لقطة ثابتة عمدًا ولا تستدعي كود التطبيق، فلا يكسرها تغيّر لاحق بالخدمة.)
 * المواسم "قريبًا" وغير المنشورة وحملاتها غير المفعَّلة تبقى null: ستُنبَّه عند بدئها الفعلي الأول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->timestamp('went_live_at')->nullable()->after('is_featured');
        });

        $now = now();

        $alreadyStarted = DB::table('seasons')
            ->join('campaigns', 'campaigns.id', '=', 'seasons.campaign_id')
            ->where('seasons.is_published', true)
            ->where('campaigns.is_active', true)
            ->where(fn ($q) => $q->whereNull('campaigns.starts_at')->orWhere('campaigns.starts_at', '<=', $now))
            ->pluck('seasons.id');

        foreach ($alreadyStarted->chunk(500) as $ids) {
            DB::table('seasons')->whereIn('id', $ids->all())->whereNull('went_live_at')->update(['went_live_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('went_live_at');
        });
    }
};
