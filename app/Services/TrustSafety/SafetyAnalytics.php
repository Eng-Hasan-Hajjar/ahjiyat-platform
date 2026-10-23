<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E38 - تحليلات السلامة والاعتراضات: **مجمَّعة وإدارية فقط** (trust-safety.view).
 *  - لا ترتيب مبلِّغين ولا «أكثر المبلِّغين نشاطًا»، ولا لوحة متصدرين للمشرفين ولا قياس أداء فردي (تحفيز الكمّ يفسد الحكم)؛ التجميع بالنوع/التصنيف/الحالة فقط.
 *  - عدد البلاغات على موضوع واحد يُعرض كمعلومة (مع المبلِّغين الفريدين) ولا يحسم شيئًا.
 *  - كاش قصير للأرقام المجمَّعة فقط (config trust_safety.analytics.cache_seconds)؛ حالة الحالات/الاعتراضات/الإجراءات الفعّالة **لا تُخزَّن** (تُقرأ حيّة من خدماتها).
 *  - المتوسطات تُحسب في PHP من أعمدة زمنية (لا دوال تاريخ خاصة بمحرك قاعدة بعينه) على عيّنة محدودة.
 */
class SafetyAnalytics
{
    private const SAMPLE = 2000;

    public function __construct(protected InboxService $inbox) {}

    /** @return array<string,mixed> */
    public function admin(User $actor): array
    {
        abort_unless($actor->can('trust-safety.view'), 403);

        $ttl = max(0, (int) config('trust_safety.analytics.cache_seconds'));
        $window = max(1, (int) config('trust_safety.analytics.window_days'));
        $aggregate = fn () => $this->aggregate($window);

        return [
            'window_days' => $window,
            'generated_at' => now()->toDateTimeString(),
            'aggregate' => $ttl > 0 ? Cache::remember('trust_safety:analytics:v1:'.$window, $ttl, $aggregate) : $aggregate(),
            // حيّ (غير مخزَّن): حالة التشغيل الحالية.
            'live' => $this->live($actor),
        ];
    }

    /** @return array<string,mixed> */
    protected function live(User $actor): array
    {
        $now = now()->toDateTimeString();
        $cases = ModerationCase::query()->whereIn('status', ModerationCase::ACTIVE_STATUSES)->selectRaw(
            "COUNT(*) as active_total, SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned_total, SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_total, "
            .'SUM(CASE WHEN resolution_due_at < ? OR (first_responded_at IS NULL AND first_response_due_at < ?) THEN 1 ELSE 0 END) as overdue_total',
            [$now, $now]
        )->first();
        $appeals = ModerationAppeal::query()->whereIn('status', ModerationAppeal::ACTIVE_STATUSES)->selectRaw(
            "SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_total, SUM(CASE WHEN status = 'in_review' THEN 1 ELSE 0 END) as review_total"
        )->first();
        $inbox = $this->inbox->summary($actor);

        return [
            'cases_active' => (int) ($cases->active_total ?? 0), 'cases_unassigned' => (int) ($cases->unassigned_total ?? 0),
            'cases_urgent' => (int) ($cases->urgent_total ?? 0), 'cases_overdue' => (int) ($cases->overdue_total ?? 0),
            'appeals_pending' => (int) ($appeals->pending_total ?? 0), 'appeals_in_review' => (int) ($appeals->review_total ?? 0),
            'inbox_open' => $inbox['open'], 'inbox_system_signals' => $inbox['system'], 'inbox_unlinked' => $inbox['unlinked'],
        ];
    }

    /** @return array<string,mixed> */
    protected function aggregate(int $window): array
    {
        $since = now()->subDays($window);

        return [
            'cases_opened' => ModerationCase::query()->where('created_at', '>=', $since)->count(),
            'cases_by_category' => $this->countBy(ModerationCase::query()->where('created_at', '>=', $since), 'category', fn ($k) => PolicyCatalog::categoryLabel($k)),
            'cases_by_resolution' => $this->countBy(ModerationCase::query()->where('resolved_at', '>=', $since), 'resolution', fn ($k) => ModerationCase::RESOLUTIONS[$k] ?? (string) $k),
            'actions_by_type' => $this->countBy(ModerationAction::query()->where('created_at', '>=', $since), 'action', fn ($k) => ModerationAction::LABELS[$k] ?? (string) $k),
            'response' => $this->responseTimes($since),
            'appeals' => $this->appealStats($since),
            'repeat_subjects' => $this->repeatSubjects($since),
        ];
    }

    /** @param callable(string):string $label @return array<string,int> */
    protected function countBy($query, string $column, callable $label): array
    {
        $out = [];

        foreach ($query->whereNotNull($column)->select($column.' as k')->selectRaw('COUNT(*) as n')->groupBy($column)->orderByDesc('n')->get() as $row) {
            $out[$label((string) $row->k)] = (int) $row->n;
        }

        return $out;
    }

    /** @return array{first_response_hours:?float,resolution_hours:?float,sample:int} */
    protected function responseTimes(CarbonInterface $since): array
    {
        $rows = ModerationCase::query()->where('created_at', '>=', $since)->orderByDesc('id')->limit(self::SAMPLE)->get(['created_at', 'first_responded_at', 'resolved_at']);

        $first = $rows->filter(fn ($c) => $c->first_responded_at !== null)->map(fn ($c) => $c->created_at->diffInMinutes($c->first_responded_at));
        $resolved = $rows->filter(fn ($c) => $c->resolved_at !== null)->map(fn ($c) => $c->created_at->diffInMinutes($c->resolved_at));

        return [
            'first_response_hours' => $first->isEmpty() ? null : round($first->avg() / 60, 1),
            'resolution_hours' => $resolved->isEmpty() ? null : round($resolved->avg() / 60, 1),
            'sample' => $rows->count(),
        ];
    }

    /** @return array<string,mixed> */
    protected function appealStats(CarbonInterface $since): array
    {
        $filed = ModerationAppeal::query()->where('created_at', '>=', $since);
        $by = (clone $filed)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $accepted = (int) ($by[ModerationAppeal::STATUS_ACCEPTED] ?? 0);
        $rejected = (int) ($by[ModerationAppeal::STATUS_REJECTED] ?? 0);
        $decided = $accepted + $rejected;

        $times = (clone $filed)->whereNotNull('decided_at')->whereIn('status', [ModerationAppeal::STATUS_ACCEPTED, ModerationAppeal::STATUS_REJECTED])
            ->orderByDesc('id')->limit(self::SAMPLE)->get(['created_at', 'decided_at'])->map(fn ($a) => $a->created_at->diffInMinutes($a->decided_at));

        return [
            'filed' => array_sum(array_map('intval', $by)),
            'by_status' => collect($by)->mapWithKeys(fn ($n, $s) => [ModerationAppeal::STATUSES[$s] ?? $s => (int) $n])->all(),
            'accepted' => $accepted, 'rejected' => $rejected,
            // معدل القبول مؤشر مراجعة جودة القرارات الأصلية (لا مقياس أداء لمشرف بعينه).
            'acceptance_rate' => $decided > 0 ? round($accepted / $decided * 100, 1) : null,
            'avg_decision_hours' => $times->isEmpty() ? null : round($times->avg() / 60, 1),
            'conflict_overrides' => (clone $filed)->where('conflict_flag', true)->count(),
        ];
    }

    /**
     * مواضيع تكرّر البلاغ عنها (عدّ فقط، بلا هوية مبلِّغ). معلومة للفرز البشري لا حكم.
     *
     * @return list<array{subject_type:string,subject_id:int,report_count:int,unique_reporters:int}>
     */
    protected function repeatSubjects(CarbonInterface $since): array
    {
        $min = max(2, (int) config('trust_safety.analytics.repeat_subject_min_reports'));
        $limit = max(1, (int) config('trust_safety.analytics.repeat_subjects_limit'));

        $rows = DB::query()->fromSub($this->inbox->union(), 'inbox')
            ->where('inbox.reported_at', '>=', $since->toDateTimeString())
            ->where('inbox.source_type', '<>', 'fraud_flag')
            ->selectRaw('inbox.subject_type, inbox.subject_id, COUNT(*) as report_count, COUNT(DISTINCT inbox.reporter_id) as unique_reporters')
            ->groupBy('inbox.subject_type', 'inbox.subject_id')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->orderByDesc('report_count')->orderBy('inbox.subject_type')->orderBy('inbox.subject_id')->limit($limit)->get();

        return $rows->map(fn ($r) => [
            'subject_type' => (string) $r->subject_type, 'subject_id' => (int) $r->subject_id,
            'report_count' => (int) $r->report_count, 'unique_reporters' => (int) $r->unique_reporters,
        ])->all();
    }
}
