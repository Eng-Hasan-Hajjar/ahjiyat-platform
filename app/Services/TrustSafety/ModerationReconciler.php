<?php

namespace App\Services\TrustSafety;

use App\Models\ChatMessage;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationCase;
use App\Models\ModerationCaseSource;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E38 - مصالحة طبقة الإشراف (moderation:reconcile).
 *
 *  افتراضيًا **قراءة فقط**. `--fix` يصلح فقط ما هو **مشتقّ وآمن وعكوس** (أرقام الحالات، علامات active_marker المنحرفة)؛ لا يحذف ولا يعدّل تاريخًا
 *  (أحداث/أدلة/إجراءات/اعتراضات) ولا يلمس حالة أي مجال (المحتوى/الحساب/المبدع) ولا يغلق بلاغات. الانحرافات التي تحتاج حكمًا بشريًا تُبلَّغ فقط.
 *
 *  مستوى كل فحص: integrity (انحراف داخلي يجب إصلاحه) | drift (حالة المجال تغيّرت خارج هذه الطبقة؛ معلومة).
 */
class ModerationReconciler
{
    public function __construct(protected OperationalAuditService $audit) {}

    /** @return array{checks:array<string,array<string,mixed>>,total:int,integrity:int,fixed:int} */
    public function run(bool $fix = false, ?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('trust_safety.reconcile.limit'));
        $checks = [];

        foreach ($this->definitions() as $name => $def) {
            $ids = $def['find']($limit);
            $fixed = 0;

            if ($fix && $def['fix'] !== null && $ids !== []) {
                $fixed = DB::transaction(fn () => $def['fix']($ids));
            }

            $checks[$name] = [
                'level' => $def['level'], 'description' => $def['description'], 'count' => count($ids), 'sample' => array_slice($ids, 0, 10),
                'fixable' => $def['fix'] !== null, 'fixed' => $fixed,
            ];
        }

        $integrityOpen = 0;
        $fixedTotal = 0;
        $total = 0;

        foreach ($checks as $c) {
            $total += $c['count'];
            $fixedTotal += $c['fixed'];

            if ($c['level'] === 'integrity') {
                $integrityOpen += max(0, $c['count'] - $c['fixed']);
            }
        }

        if ($fix && $fixedTotal > 0) {
            $this->audit->log('moderation_reconciled', null, ['fixed' => $fixedTotal, 'checks' => array_keys(array_filter($checks, fn ($c) => $c['fixed'] > 0))], null);
        }

        return ['checks' => $checks, 'total' => $total, 'integrity' => $integrityOpen, 'fixed' => $fixedTotal];
    }

    /** @return array<string, array{level:string,description:string,find:callable,fix:?callable}> */
    protected function definitions(): array
    {
        return [
            'case_number_missing' => [
                'level' => 'integrity', 'description' => 'حالة بلا رقم مقروء',
                'find' => fn (int $n) => ModerationCase::query()->whereNull('case_number')->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => function (array $ids) {
                    $fixed = 0;

                    foreach (ModerationCase::query()->whereIn('id', $ids)->get() as $case) {
                        $case->forceFill(['case_number' => sprintf('%s-%s-%s', config('trust_safety.case.number_prefix'), $case->created_at?->format('Y') ?? now()->format('Y'), str_pad((string) $case->id, (int) config('trust_safety.case.number_pad'), '0', STR_PAD_LEFT))])->save();
                        $fixed++;
                    }

                    return $fixed;
                },
            ],
            'inactive_case_holds_sources' => [
                'level' => 'integrity', 'description' => 'حالة محسومة/مغلقة ما زالت تحتجز مصادرها (active_marker=1)',
                'find' => fn (int $n) => ModerationCaseSource::query()->where('active_marker', 1)
                    ->whereIn('moderation_case_id', ModerationCase::query()->whereNotIn('status', ModerationCase::ACTIVE_STATUSES)->select('id'))->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => fn (array $ids) => ModerationCaseSource::query()->whereIn('id', $ids)->update(['active_marker' => null]),
            ],
            'active_case_missing_hold' => [
                'level' => 'integrity', 'description' => 'حالة فعّالة لمصدرها (غير المفصول) active_marker فارغ',
                'find' => fn (int $n) => ModerationCaseSource::query()->whereNull('active_marker')->whereNull('detached_at')
                    ->whereIn('moderation_case_id', ModerationCase::query()->whereIn('status', ModerationCase::ACTIVE_STATUSES)->select('id'))->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => function (array $ids) {
                    $fixed = 0;

                    foreach (ModerationCaseSource::query()->whereIn('id', $ids)->get() as $source) {
                        $taken = ModerationCaseSource::query()->where('active_marker', 1)->where('source_type', $source->source_type)->where('source_id', $source->source_id)->exists();

                        if (! $taken) { // لا نكسر قيد «بلاغ في حالة فعّالة واحدة»؛ ما يتعارض يبقى للمراجعة البشرية.
                            ModerationCaseSource::query()->whereKey($source->id)->update(['active_marker' => 1]);
                            $fixed++;
                        }
                    }

                    return $fixed;
                },
            ],
            'resolved_case_missing_resolution' => [
                'level' => 'integrity', 'description' => 'حالة محسومة بلا نتيجة حسم',
                'find' => fn (int $n) => ModerationCase::query()->where('status', ModerationCase::STATUS_RESOLVED)->whereNull('resolution')->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => null,
            ],
            'resolved_case_open_report' => [
                'level' => 'integrity', 'description' => 'حالة محسومة ومصدرها (غير المفصول) ما زال بلاغه المجالي مفتوحًا',
                'find' => fn (int $n) => $this->resolvedCasesWithOpenReports($n),
                'fix' => null,
            ],
            'source_report_missing' => [
                'level' => 'drift', 'description' => 'مصدر يشير إلى بلاغ حُذف (يبقى المؤشر كسجل تاريخي)',
                'find' => fn (int $n) => $this->orphanSources($n),
                'fix' => null,
            ],
            'action_marker_reversed' => [
                'level' => 'integrity', 'description' => 'إجراء معكوس يحتجز قيده الفريد (active_marker=1)',
                'find' => fn (int $n) => ModerationAction::query()->where('active_marker', 1)->whereNotNull('reversed_at')->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => fn (array $ids) => ModerationAction::query()->whereIn('id', $ids)->update(['active_marker' => null]),
            ],
            'action_domain_drift' => [
                'level' => 'drift', 'description' => 'إجراء فعّال سجلًّا لكن حالة المجال لم تعد كما سُجِّلت (تغيّرت خارج هذه الطبقة)',
                'find' => fn (int $n) => $this->driftedActions($n),
                'fix' => null,
            ],
            'appeal_marker_active_without_hold' => [
                'level' => 'integrity', 'description' => 'اعتراض معلّق/قيد المراجعة بلا active_marker',
                'find' => fn (int $n) => ModerationAppeal::query()->whereIn('status', ModerationAppeal::ACTIVE_STATUSES)->whereNull('active_marker')->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => function (array $ids) {
                    $fixed = 0;

                    foreach (ModerationAppeal::query()->whereIn('id', $ids)->get() as $appeal) {
                        if (! ModerationAppeal::query()->where('moderation_action_id', $appeal->moderation_action_id)->where('active_marker', 1)->exists()) {
                            ModerationAppeal::query()->whereKey($appeal->id)->update(['active_marker' => 1]);
                            $fixed++;
                        }
                    }

                    return $fixed;
                },
            ],
            'appeal_marker_decided_with_hold' => [
                'level' => 'integrity', 'description' => 'اعتراض مقرَّر/مسحوب يحتجز قيده الفريد',
                'find' => fn (int $n) => ModerationAppeal::query()->whereNotIn('status', ModerationAppeal::ACTIVE_STATUSES)->where('active_marker', 1)->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => fn (array $ids) => ModerationAppeal::query()->whereIn('id', $ids)->update(['active_marker' => null]),
            ],
            'accepted_appeal_action_not_reversed' => [
                'level' => 'integrity', 'description' => 'اعتراض مقبول لكن الإجراء الأصلي غير موسوم معكوسًا',
                'find' => fn (int $n) => ModerationAppeal::query()->where('status', ModerationAppeal::STATUS_ACCEPTED)
                    ->whereIn('moderation_action_id', ModerationAction::query()->whereNull('reversed_at')->select('id'))->orderBy('id')->limit($n)->pluck('id')->all(),
                'fix' => null,
            ],
        ];
    }

    /** @return list<int> */
    protected function resolvedCasesWithOpenReports(int $limit): array
    {
        $out = [];

        $sources = ModerationCaseSource::query()->whereNull('detached_at')
            ->whereIn('moderation_case_id', ModerationCase::query()->where('status', ModerationCase::STATUS_RESOLVED)->select('id'))
            ->orderBy('id')->limit($limit * 4)->get();

        foreach ($sources->groupBy('source_type') as $type => $group) {
            if (! ReportSources::has((string) $type)) {
                continue;
            }

            $reports = ReportSources::def((string) $type)['model']::query()->whereIn('id', $group->pluck('source_id'))->get()->keyBy('id');

            foreach ($group as $source) {
                $report = $reports->get($source->source_id);

                if ($report !== null && ReportSources::isOpen($report, (string) $type)) {
                    $out[] = (int) $source->id;
                }
            }
        }

        sort($out);

        return array_slice($out, 0, $limit);
    }

    /** @return list<int> */
    protected function orphanSources(int $limit): array
    {
        $out = [];

        foreach (ModerationCaseSource::query()->orderByDesc('id')->limit($limit * 4)->get()->groupBy('source_type') as $type => $group) {
            if (! ReportSources::has((string) $type)) {
                continue;
            }

            $existing = ReportSources::def((string) $type)['model']::query()->whereIn('id', $group->pluck('source_id'))->pluck('id')->all();

            foreach ($group as $source) {
                if (! in_array($source->source_id, $existing, true)) {
                    $out[] = (int) $source->id;
                }
            }
        }

        sort($out);

        return array_slice($out, 0, $limit);
    }

    /** @return list<int> */
    protected function driftedActions(int $limit): array
    {
        $out = [];

        foreach (ModerationAction::query()->where('active_marker', 1)->orderBy('id')->limit($limit * 2)->get() as $action) {
            $id = (int) $action->subject_id;

            $applied = match ($action->action) {
                'hide_content' => match ($action->subject_type) {
                    'community_puzzle' => CreatorPuzzle::query()->whereKey($id)->where('hidden_reason', CreatorPuzzle::HIDDEN_MODERATION)->whereNotNull('hidden_at')->exists(),
                    'user_collection' => UserCollection::query()->whereKey($id)->where('is_moderation_hidden', true)->exists(),
                    'discussion_comment' => DiscussionComment::query()->whereKey($id)->where('status', DiscussionComment::STATUS_HIDDEN)->exists(),
                    'chat_message' => ChatMessage::query()->whereKey($id)->whereNotNull('hidden_at')->exists(),
                    default => true,
                },
                'suspend_creator' => CreatorProfile::query()->whereKey($id)->where('status', CreatorProfile::STATUS_SUSPENDED)->exists(),
                'freeze_user' => User::query()->whereKey($id)->where('is_frozen', true)->exists(),
                default => true,
            };

            if (! $applied) {
                $out[] = (int) $action->id;
            }
        }

        return array_slice($out, 0, $limit);
    }
}
