<?php

namespace App\Services\TrustSafety;

use App\Models\ModerationCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E38 - الصندوق الموحَّد: **قراءة فقط** فوق جداول البلاغات القائمة (E21/E28/E35/E36) + FraudFlag بوسم «إشارة نظام».
 *  - لا نسخ ولا مزامنة: استعلام UNION ALL بأعمدة موحَّدة ثابتة (من ReportSources فقط) ثم ترقيم الصفحات في SQL، ثم **تجميع الدُفعات** لكل نوع (لا N+1).
 *  - المرشِّحات كلها قائمة بيضاء ومُحقَّقة؛ لا ورود SQL ولا اسم جدول/عمود من الطلب. بلا بحث نصي حر (لا كشف محتوى).
 *  - عدد البلاغات وعدد المبلِّغين الفريدين يُعرضان للتجميع حسب الموضوع **كمعلومة فقط**: لا يرفعان الأولوية ولا يعنيان مخالفة.
 */
class InboxService
{
    public function __construct(protected SubjectResolver $subjects) {}

    /** يحوّل مدخلات الطلب الخام إلى مرشِّحات مُحقَّقة (قيم غير صالحة تُهمَل بصمت). @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $statuses = ['open', 'closed', 'all', 'dismissed', 'actioned', 'reviewed', 'resolved'];
        $status = in_array($input['status'] ?? 'open', $statuses, true) ? ($input['status'] ?? 'open') : 'open';

        $perPage = (int) ($input['per_page'] ?? config('trust_safety.inbox.per_page'));
        $perPage = max(1, min($perPage, (int) config('trust_safety.inbox.max_per_page')));

        $sources = array_values(array_filter((array) ($input['source'] ?? []), fn ($t) => ReportSources::has(is_string($t) ? $t : null)));
        $categories = array_values(array_filter((array) ($input['category'] ?? []), fn ($c) => PolicyCatalog::isCategory(is_string($c) ? $c : null)));
        $priority = in_array($input['priority'] ?? null, array_keys(ModerationCase::PRIORITIES), true) ? $input['priority'] : null;
        $assigned = $input['assigned'] ?? null;
        $assigned = $assigned === 'mine' || $assigned === 'unassigned' ? $assigned : (ctype_digit((string) $assigned) && (int) $assigned > 0 ? (int) $assigned : null);
        $hasCase = in_array($input['has_case'] ?? null, ['yes', 'no'], true) ? $input['has_case'] : null;
        $subjectType = in_array($input['subject_type'] ?? null, ModerationCase::SUBJECT_TYPES, true) ? $input['subject_type'] : null;

        return [
            'status' => $status,
            'source' => $sources,
            'category' => $categories,
            'priority' => $priority,
            'assigned' => $assigned,
            'has_case' => $hasCase,
            'subject_type' => $subjectType,
            'from' => $this->date($input['from'] ?? null),
            'to' => $this->date($input['to'] ?? null),
            'sort' => ($input['sort'] ?? 'oldest') === 'newest' ? 'newest' : 'oldest',
            'group' => ($input['group'] ?? null) === 'subject' ? 'subject' : null,
            'per_page' => $perPage,
            'page' => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /** @return LengthAwarePaginator<int, InboxItem> */
    public function paginate(User $actor, array $input): LengthAwarePaginator
    {
        $this->authorize($actor);
        $f = $this->normalize($input);

        $query = $this->filtered($f, $actor)->select('inbox.*', 'c.id as case_row_id', 'c.public_id as case_public_id', 'c.case_number', 'c.status as case_status', 'c.assigned_to as case_assigned_to');
        $f['sort'] === 'newest'
            ? $query->orderByDesc('inbox.reported_at')->orderByDesc('inbox.source_id')->orderBy('inbox.source_type')
            : $query->orderBy('inbox.reported_at')->orderBy('inbox.source_id')->orderBy('inbox.source_type');

        $page = $query->paginate($f['per_page'], ['*'], 'page', $f['page']);
        $items = $this->hydrate($page->items());

        return new Paginator($items, $page->total(), $page->perPage(), $page->currentPage(), ['path' => Paginator::resolveCurrentPath()]);
    }

    /**
     * تجميع حسب الموضوع: report_count، unique_reporters_count (إشارات النظام لا مبلِّغ لها فلا تُحتسب)، open_count، أول/آخر بلاغ.
     *
     * @return LengthAwarePaginator<int, array<string,mixed>>
     */
    public function paginateGrouped(User $actor, array $input): LengthAwarePaginator
    {
        $this->authorize($actor);
        $f = $this->normalize($input);

        $inner = $this->filtered($f, $actor)->select('inbox.*', 'c.id as case_row_id');
        $grouped = DB::query()->fromSub($inner, 'f')
            ->selectRaw("f.subject_type, f.subject_id, COUNT(*) as report_count, COUNT(DISTINCT f.reporter_id) as unique_reporters_count, "
                ."SUM(CASE WHEN f.status = 'open' THEN 1 ELSE 0 END) as open_count, MIN(f.reported_at) as first_reported_at, MAX(f.reported_at) as last_reported_at, MAX(f.case_row_id) as case_row_id")
            ->groupBy('f.subject_type', 'f.subject_id');
        $f['sort'] === 'newest'
            ? $grouped->orderByDesc('last_reported_at')->orderBy('f.subject_type')->orderBy('f.subject_id')
            : $grouped->orderBy('first_reported_at')->orderBy('f.subject_type')->orderBy('f.subject_id');

        $page = $grouped->paginate($f['per_page'], ['*'], 'page', $f['page']);
        $rows = collect($page->items());

        $subjects = $this->subjects->resolve($rows->map(fn ($r) => [(string) $r->subject_type, (int) $r->subject_id])->all());
        $cases = ModerationCase::query()->whereIn('id', $rows->pluck('case_row_id')->filter()->unique()->all())->get(['id', 'public_id', 'case_number', 'status'])->keyBy('id');
        $owners = $this->userNames(collect($subjects)->pluck('owner_id')->filter()->unique()->all());

        $items = $rows->map(function ($r) use ($subjects, $cases, $owners) {
            $s = $subjects[$r->subject_type.':'.$r->subject_id];
            $case = $r->case_row_id !== null ? $cases->get((int) $r->case_row_id) : null;

            return [
                'subject_type' => (string) $r->subject_type, 'subject_id' => (int) $r->subject_id,
                'subject_label' => $s['label'], 'subject_exists' => $s['exists'], 'excerpt' => $s['excerpt'],
                'subject_user_id' => $s['owner_id'], 'subject_user_name' => $s['owner_id'] !== null ? ($owners[$s['owner_id']] ?? null) : null,
                'report_count' => (int) $r->report_count, 'unique_reporters_count' => (int) $r->unique_reporters_count,
                'open_count' => (int) $r->open_count,
                'first_reported_at' => $this->time($r->first_reported_at), 'last_reported_at' => $this->time($r->last_reported_at),
                'case_public_id' => $case?->public_id, 'case_number' => $case?->case_number, 'case_status' => $case?->status,
            ];
        })->all();

        return new Paginator($items, $page->total(), $page->perPage(), $page->currentPage(), ['path' => Paginator::resolveCurrentPath()]);
    }

    /** ملخص حيّ مختصر لرأس الصندوق: بلاغات مفتوحة، إشارات نظام مفتوحة، بلا حالة. استعلام واحد. @return array{open:int,system:int,unlinked:int} */
    public function summary(User $actor): array
    {
        $this->authorize($actor);

        $row = DB::query()->fromSub($this->union(), 'inbox')
            ->leftJoin('moderation_case_sources as cs', function ($j) {
                $j->on('cs.source_type', '=', 'inbox.source_type')->on('cs.source_id', '=', 'inbox.source_id')->where('cs.active_marker', '=', 1);
            })
            ->where('inbox.status', 'open')
            ->selectRaw("COUNT(*) as open_total, SUM(CASE WHEN inbox.source_type = 'fraud_flag' THEN 1 ELSE 0 END) as system_total, SUM(CASE WHEN cs.id IS NULL THEN 1 ELSE 0 END) as unlinked_total")
            ->first();

        return ['open' => (int) ($row->open_total ?? 0), 'system' => (int) ($row->system_total ?? 0), 'unlinked' => (int) ($row->unlinked_total ?? 0)];
    }

    /** الاتحاد الموحَّد الخام. القيم الحرفية تأتي من سجل المصادر المغلق فقط (آمنة). */
    public function union(): Builder
    {
        $first = null;

        foreach (ReportSources::all() as $type => $def) {
            $reporter = $def['reporter_col'] !== null ? 'r.'.$def['reporter_col'] : 'NULL';
            $part = DB::table($def['table'].' as r')->selectRaw(
                "'{$type}' as source_type, r.id as source_id, '{$def['subject_type']}' as subject_type, r.{$def['subject_col']} as subject_id, "
                ."{$reporter} as reporter_id, r.{$def['reason_col']} as reason, {$def['status_expr']} as status, {$def['severity_expr']} as severity, r.created_at as reported_at"
            );

            $first === null ? $first = $part : $first->unionAll($part);
        }

        return $first;
    }

    protected function filtered(array $f, User $actor): Builder
    {
        $q = DB::query()->fromSub($this->union(), 'inbox')
            ->leftJoin('moderation_case_sources as cs', function ($j) {
                $j->on('cs.source_type', '=', 'inbox.source_type')->on('cs.source_id', '=', 'inbox.source_id')->where('cs.active_marker', '=', 1);
            })
            ->leftJoin('moderation_cases as c', 'c.id', '=', 'cs.moderation_case_id');

        match ($f['status']) {
            'open' => $q->where('inbox.status', 'open'),
            'closed' => $q->where('inbox.status', '<>', 'open'),
            'all' => null,
            default => $q->where('inbox.status', $f['status']),
        };

        if ($f['source'] !== []) {
            $q->whereIn('inbox.source_type', $f['source']);
        }

        if ($f['subject_type'] !== null) {
            $q->where('inbox.subject_type', $f['subject_type']);
        }

        if ($f['category'] !== []) {
            $this->applyCategory($q, $f['category']);
        }

        if ($f['priority'] !== null) {
            $this->applyPriority($q, $f['priority']);
        }

        if ($f['assigned'] === 'mine') {
            $q->where('c.assigned_to', $actor->id);
        } elseif ($f['assigned'] === 'unassigned') {
            $q->whereNotNull('c.id')->whereNull('c.assigned_to');
        } elseif (is_int($f['assigned'])) {
            $q->where('c.assigned_to', $f['assigned']);
        }

        if ($f['has_case'] === 'yes') {
            $q->whereNotNull('c.id');
        } elseif ($f['has_case'] === 'no') {
            $q->whereNull('c.id');
        }

        if ($f['from'] !== null) {
            $q->where('inbox.reported_at', '>=', $f['from']->startOfDay()->toDateTimeString());
        }

        if ($f['to'] !== null) {
            $q->where('inbox.reported_at', '<=', $f['to']->endOfDay()->toDateTimeString());
        }

        return $q;
    }

    /** @param list<string> $categories */
    protected function applyCategory(Builder $q, array $categories): void
    {
        $q->where(function (Builder $w) use ($categories) {
            $any = false;

            foreach (ReportSources::all() as $type => $def) {
                if ($def['system']) {
                    if (in_array('fraud_or_abuse', $categories, true)) {
                        $w->orWhere('inbox.source_type', $type);
                        $any = true;
                    }

                    continue;
                }

                $this->orReasonGroup($w, $type, PolicyCatalog::reasonsForCategories($type, $categories), in_array('other', $categories, true));
                $any = true;
            }

            if (! $any) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    protected function applyPriority(Builder $q, string $priority): void
    {
        $q->where(function (Builder $w) use ($priority) {
            foreach (ReportSources::all() as $type => $def) {
                if ($def['system']) {
                    $severities = array_keys(array_filter((array) config('trust_safety.fraud_severity_priority'), fn ($p) => $p === $priority));

                    if ($severities !== []) {
                        $w->orWhere(fn (Builder $x) => $x->where('inbox.source_type', $type)->whereIn('inbox.severity', $severities));
                    }

                    continue;
                }

                $this->orReasonGroup($w, $type, PolicyCatalog::reasonsForPriority($type, $priority), PolicyCatalog::otherPriority() === $priority);
            }

            $w->orWhereRaw('1 = 0');
        });
    }

    /** @param list<string> $reasons */
    protected function orReasonGroup(Builder $w, string $type, array $reasons, bool $includeUnmapped): void
    {
        if ($reasons === [] && ! $includeUnmapped) {
            return;
        }

        $known = array_keys((array) config('trust_safety.reason_map.'.$type, []));

        $w->orWhere(function (Builder $x) use ($type, $reasons, $includeUnmapped, $known) {
            $x->where('inbox.source_type', $type)->where(function (Builder $y) use ($reasons, $includeUnmapped, $known) {
                if ($reasons !== []) {
                    $y->whereIn('inbox.reason', $reasons);
                }

                if ($includeUnmapped) {
                    $known === [] ? $y->orWhereRaw('1 = 1') : $y->orWhereNotIn('inbox.reason', $known);
                }
            });
        });
    }

    /**
     * @param  array<int,object>  $rows
     * @return list<InboxItem>
     */
    protected function hydrate(array $rows): array
    {
        $rows = collect($rows);
        $subjects = $this->subjects->resolve($rows->map(fn ($r) => [(string) $r->subject_type, (int) $r->subject_id])->all());
        $userIds = collect($subjects)->pluck('owner_id')->merge($rows->pluck('case_assigned_to'))->filter()->unique()->all();
        $names = $this->userNames($userIds);

        return $rows->map(function ($r) use ($subjects, $names) {
            $def = ReportSources::def((string) $r->source_type);
            $s = $subjects[$r->subject_type.':'.$r->subject_id];
            $severity = $r->severity !== null ? (string) $r->severity : null;
            $category = PolicyCatalog::normalizeCategory((string) $r->source_type, (string) $r->reason, $severity);
            $priority = PolicyCatalog::suggestPriority($category, $def['system'] ? $severity : null);

            return new InboxItem(
                sourceType: (string) $r->source_type, sourceLabel: $def['label'], sourceId: (int) $r->source_id, systemSignal: (bool) $def['system'],
                subjectType: (string) $r->subject_type, subjectId: (int) $r->subject_id, subjectLabel: $s['label'], subjectExists: $s['exists'],
                subjectUserId: $s['owner_id'], subjectUserName: $s['owner_id'] !== null ? ($names[$s['owner_id']] ?? null) : null,
                excerpt: $s['excerpt'], reasonCode: (string) $r->reason, category: $category, categoryLabel: PolicyCatalog::categoryLabel($category),
                priority: $priority, status: (string) $r->status, statusLabel: ReportSources::statusLabel((string) $r->status), severity: $severity,
                reportedAt: $this->time($r->reported_at),
                casePublicId: $r->case_public_id, caseNumber: $r->case_number, caseStatus: $r->case_status,
                caseAssigneeId: $r->case_assigned_to !== null ? (int) $r->case_assigned_to : null,
                caseAssigneeName: $r->case_assigned_to !== null ? ($names[(int) $r->case_assigned_to] ?? null) : null,
            );
        })->all();
    }

    /** @param list<int> $ids @return array<int,string> */
    protected function userNames(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    protected function time(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('trust-safety.view'), 403);
    }
}
