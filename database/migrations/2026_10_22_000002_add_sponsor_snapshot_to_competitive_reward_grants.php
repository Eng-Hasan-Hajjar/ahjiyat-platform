<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E30 (A) - لقطة تعزيز الرعاية على جائزة المنافسة وقت التخطيط (بعد اعتماد المنافسة): البرنامج + النقاط الأساسية + المكافأة المحسوبة.
 * القيم تأتي من خطة المنافسة المقفلة قبل بدئها (sponsorship_event_reward_plans) لا من تمويل لحظي؛ الجائزة الأساسية (amount) لا تُمسّ إطلاقًا.
 * أعمدة اسمية بلا FK (لقطة تاريخية: حذف/تعديل برنامج لا يمحو ما مُنح). الصفوف القديمة = 0 (لا تعزيز).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitive_reward_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('sponsor_program_id')->nullable()->after('amount');
            $table->unsignedInteger('sponsor_bonus_bps')->default(0)->after('sponsor_program_id');
            $table->unsignedInteger('sponsor_bonus_amount')->default(0)->after('sponsor_bonus_bps');
        });
    }

    public function down(): void
    {
        Schema::table('competitive_reward_grants', function (Blueprint $table) {
            $table->dropColumn(['sponsor_program_id', 'sponsor_bonus_bps', 'sponsor_bonus_amount']);
        });
    }
};
