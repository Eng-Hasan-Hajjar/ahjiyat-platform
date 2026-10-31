<?php

namespace App\Services\AiAssistant;

use App\Models\AiConversation;
use App\Models\AiFeedback;
use App\Models\AiKnowledgeArticle;
use App\Models\AiRequestMetric;
use App\Services\AiAssistant\Conversation\AiBudget;
use Illuminate\Support\Facades\DB;

/**
 * E45 - أرقام لوحة «تطوّر AI» الإدارية: **مجمَّعة فقط** (أعداد/نسب/زمن)، بلا نص سؤال أو ردّ ولا هوية مستخدم ولا مفاتيح. كلها قراءة، بحدود نافذة زمنية، باستعلامات تجميع ثابتة العدد.
 * مصدر الاستخدام جدول ai_request_metrics (خالٍ من المحتوى بالبناء)؛ فجوات المعرفة = ردود «امتنع» مجمَّعة حسب المجال لتوجيه المحررين لما ينقص الدليل.
 */
final class TatawwurAnalytics
{
    public function __construct(protected AiBudget $budget) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'enabled' => TatawwurSettings::enabled(),
            'provider_enabled' => TatawwurSettings::providerEnabled(),
            'guests_enabled' => TatawwurSettings::guestsEnabled(),
            'streaming_enabled' => TatawwurSettings::streamingEnabled(),
            'provider' => TatawwurSettings::providerName(),
            'model_configured' => TatawwurSettings::model() !== null,
            'model' => TatawwurSettings::model(),
            'provider_ready' => TatawwurSettings::providerReady(),
            'mode' => TatawwurSettings::providerReady() ? 'ai' : 'guide',
            'retention_days' => TatawwurSettings::retentionDays(),
            'tokens_today' => $this->budget->used(),
            'tokens_limit' => TatawwurSettings::globalDailyTokenLimit(),
        ];
    }

    /** @return array<string, mixed> */
    public function overview(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = now()->subDays($days);

        $byOutcome = AiRequestMetric::query()->where('created_at', '>=', $since)->select('outcome', DB::raw('COUNT(*) as n'))->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all();
        $total = array_sum($byOutcome);

        $agg = AiRequestMetric::query()->where('created_at', '>=', $since)->selectRaw('COALESCE(SUM(input_tokens),0) as tin, COALESCE(SUM(output_tokens),0) as tout, COALESCE(AVG(latency_ms),0) as lat, COALESCE(MAX(latency_ms),0) as maxlat, COALESCE(SUM(grounded),0) as grounded, COALESCE(SUM(streamed),0) as streamed, COALESCE(SUM(tool_calls),0) as tools')->first();

        $errors = AiRequestMetric::query()->where('created_at', '>=', $since)->whereNotNull('error_category')->select('error_category', DB::raw('COUNT(*) as n'))->groupBy('error_category')->orderByDesc('n')->limit(10)->pluck('n', 'error_category')->map(fn ($n) => (int) $n)->all();
        $audience = AiRequestMetric::query()->where('created_at', '>=', $since)->select('audience', DB::raw('COUNT(*) as n'))->groupBy('audience')->pluck('n', 'audience')->map(fn ($n) => (int) $n)->all();

        $perDay = AiRequestMetric::query()->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->orderBy('d')->pluck('n', 'd')->map(fn ($n) => (int) $n)->all();

        $gaps = AiRequestMetric::query()->where('created_at', '>=', $since)->where('outcome', 'abstained')
            ->select('area', DB::raw('COUNT(*) as n'))->groupBy('area')->orderByDesc('n')->limit(8)->get()
            ->map(fn ($row) => ['area' => $row->area !== null ? (AiKnowledgeArticle::AREAS[$row->area] ?? $row->area) : 'غير مصنّف', 'count' => (int) $row->n])->all();

        $ratings = AiFeedback::query()->where('created_at', '>=', $since)->select('rating', DB::raw('COUNT(*) as n'))->groupBy('rating')->pluck('n', 'rating')->map(fn ($n) => (int) $n)->all();
        $reasons = AiFeedback::query()->where('created_at', '>=', $since)->where('rating', AiFeedback::RATING_DOWN)->whereNotNull('reason')->select('reason', DB::raw('COUNT(*) as n'))->groupBy('reason')->orderByDesc('n')->pluck('n', 'reason')->map(fn ($n) => (int) $n)->all();

        $articles = AiKnowledgeArticle::query()->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return [
            'days' => $days,
            'total' => $total,
            'by_outcome' => $byOutcome,
            'rates' => [
                'grounded' => $total > 0 ? round(((int) $agg->grounded) / $total * 100, 1) : 0.0,
                'abstained' => $total > 0 ? round(($byOutcome['abstained'] ?? 0) / $total * 100, 1) : 0.0,
                'fallback' => $total > 0 ? round(($byOutcome['fallback'] ?? 0) / $total * 100, 1) : 0.0,
                'error' => $total > 0 ? round(($byOutcome['error'] ?? 0) / $total * 100, 1) : 0.0,
            ],
            'tokens_in' => (int) $agg->tin,
            'tokens_out' => (int) $agg->tout,
            'latency_avg_ms' => (int) round((float) $agg->lat),
            'latency_max_ms' => (int) $agg->maxlat,
            'tool_calls' => (int) $agg->tools,
            'streamed' => (int) $agg->streamed,
            'errors' => $errors,
            'audience' => $audience,
            'per_day' => $perDay,
            'gaps' => $gaps,
            'ratings' => $ratings,
            'reasons' => $reasons,
            'articles' => $articles,
            'active_conversations' => AiConversation::query()->where('expires_at', '>', now())->count(),
        ];
    }
}
