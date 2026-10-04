<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E14.1: سجلات التتبُّع (ad_impressions/ad_clicks) كانت بـcascadeOnDelete على
 * الموضع والحملة والمادة - فحذف أي أب كان يمحو تحليلاته. تصبح restrict: حذف أب
 * له تاريخ يفشل بدل أن يمحو السجل (دفاع على مستوى DB خلف حارس النموذج).
 *
 * لا تعديل لترحيلات قديمة، ولا اعتماد على تعديل FK بالمكان (غير متاح بثبات
 * بكل المحركات): نعيد بناء الجدولين - وهما جدولان صغيران جديدان بـE14 - مع نسخ
 * الصفوف كما هي (بمعرّفاتها) فلا يضيع أي سجل موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild('ad_impressions', 'rendered_at', restrict: true);
        $this->rebuild('ad_clicks', 'clicked_at', restrict: true);
    }

    public function down(): void
    {
        $this->rebuild('ad_impressions', 'rendered_at', restrict: false);
        $this->rebuild('ad_clicks', 'clicked_at', restrict: false);
    }

    protected function rebuild(string $table, string $timeColumn, bool $restrict): void
    {
        $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();

        Schema::drop($table);

        Schema::create($table, function (Blueprint $t) use ($timeColumn, $restrict) {
            $t->id();

            foreach (['ad_placement_id', 'sponsor_campaign_id', 'sponsor_creative_id'] as $column) {
                $fk = $t->foreignId($column)->constrained();
                $restrict ? $fk->restrictOnDelete() : $fk->cascadeOnDelete();
            }

            $t->timestamp($timeColumn);

            $t->index(['sponsor_campaign_id', $timeColumn]);
            $t->index(['ad_placement_id', $timeColumn]);
        });

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
};
