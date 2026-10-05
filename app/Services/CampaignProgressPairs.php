<?php

namespace App\Services;

use App\GameEngine\Support\AttemptContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تعريف واحد لـ"تقدّم حملة فعلي" وللعلاقة بين المستخدمين والحملات التي يتقدّمون بها: تعتمد عليه أوامر التعبئة الرجعية
 * الصامتة (فتح المراحل، إكمال الحملات) وتذكيرات "ينتهي قريبًا"، كي لا يختلف تعريفه بينها. التقدّم الفعلي = سرد/تأمل
 * مكتمل، أو محاولة أحجية صحيحة بسياق خطوة حملة.
 */
final class CampaignProgressPairs
{
    /** استعلام مستخدمين (يُستعمل مع chunkById: لا تحميل لكل المستخدمين). */
    public static function usersQuery(): Builder
    {
        return User::query()->where(function ($q) {
            $q->whereIn('id', fn ($s) => $s->select('user_id')->from('user_campaign_progress')->whereNotNull('completed_at'))
                ->orWhereIn('id', fn ($s) => $s->select('user_id')->from('puzzle_attempts')
                    ->where('is_correct', true)->where('context_type', AttemptContext::TYPE_CAMPAIGN_STEP));
        });
    }

    /**
     * مستخدمو حملة معيّنة ذوو تقدّم فعلي فيها (الاتجاه المعاكس لـcampaignsByUser). استعلام يُستعمل مع chunkById.
     */
    public static function usersForCampaignQuery(int $campaignId): Builder
    {
        return User::query()->where(function ($q) use ($campaignId) {
            $q->whereIn('id', self::narrativeRows()->where('s.campaign_id', $campaignId)->select('p.user_id'))
                ->orWhereIn('id', self::puzzleRows()->where('s.campaign_id', $campaignId)->select('pa.user_id'));
        });
    }

    /**
     * أزواج لدفعة مستخدمين بدفعة استعلامات واحدة (لا استعلام لكل مستخدم).
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, Collection<int, int>> user_id => معرّفات الحملات
     */
    public static function campaignsByUser(array $userIds): Collection
    {
        return self::puzzleRows()->whereIn('pa.user_id', $userIds)
            ->union(self::narrativeRows()->whereIn('p.user_id', $userIds))
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->pluck('campaign_id')->unique()->values());
    }

    private static function narrativeRows(): QueryBuilder
    {
        return DB::table('user_campaign_progress as p')
            ->join('campaign_steps as st', 'st.id', '=', 'p.campaign_step_id')
            ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')
            ->join('campaign_stages as s', 's.id', '=', 'g.campaign_stage_id')
            ->whereNotNull('p.completed_at')
            ->select('p.user_id', 's.campaign_id');
    }

    private static function puzzleRows(): QueryBuilder
    {
        return DB::table('puzzle_attempts as pa')
            ->join('campaign_steps as st', 'st.id', '=', 'pa.context_id')
            ->join('campaign_gates as g', 'g.id', '=', 'st.campaign_gate_id')
            ->join('campaign_stages as s', 's.id', '=', 'g.campaign_stage_id')
            ->where('pa.is_correct', true)->where('pa.context_type', AttemptContext::TYPE_CAMPAIGN_STEP)
            ->select('pa.user_id', 's.campaign_id');
    }
}
