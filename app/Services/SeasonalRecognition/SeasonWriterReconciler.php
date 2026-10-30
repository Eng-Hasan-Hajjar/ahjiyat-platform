<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonRecognitionSetting;
use App\Models\SeasonWriterEntry;
use App\Models\SeasonWriterVoteBudget;
use App\Models\User;
use App\Services\OperationalAuditService;
use Illuminate\Support\Facades\DB;

/**
 * E44 - مطابقة دوري الكتّاب: دفتر التوزيعات (season_writer_star_allocations) هو **السلطة**، والمجاميع المخزَّنة (stars_total/voters_count/spent_stars) مشتقّة منه.
 *
 * - inspect(): قراءة فقط (استعلامات مجمَّعة بلا حلقات لكل صف) تُرجع نتائج {code, ...}. يستعملها أمر seasonal-recognition:reconcile (بلا خيار إصلاح) وصفحة الإدارة والمُنهي.
 * - rebuildAggregates(): إجراء إداري **صريح** قبل الإنهاء فقط: يعيد بناء مجاميع الكتّاب من الدفتر تحت قفل الإعدادات الحصري (لا تصويت بين القفل والتحديث)،
 *   ثم يصحّح ميزانيات الناخبين واحدةً واحدةً بترتيب القفل نفسه الذي يتبعه التصويت (الميزانية أولًا)، فلا دورة انتظار. يُدقَّق.
 *   لا يلمس دفتر التوزيعات نفسه ولا موسمًا مُنهى.
 */
class SeasonWriterReconciler
{
    public const PERMISSION = 'seasonal-recognition.audit';

    public function __construct(
        protected SeasonRecognitionSettingsService $settings,
        protected OperationalAuditService $audit,
    ) {}

    /**
     * @return list<array<string, mixed>> قائمة فارغة = متسق.
     */
    public function inspect(Season $season, ?int $limit = null): array
    {
        $limit ??= max(1, (int) config('seasonal_recognition.limits.reconcile_default_limit', 5000));
        $findings = [];
        $seasonId = (int) $season->id;

        // 1) مجاميع الكتّاب مقابل الدفتر.
        $rows = DB::table('season_writer_entries as e')
            ->leftJoin('season_writer_star_allocations as a', 'a.writer_entry_id', '=', 'e.id')
            ->where('e.season_id', $seasonId)
            ->groupBy('e.id', 'e.stars_total', 'e.voters_count')
            ->havingRaw('e.stars_total <> COALESCE(SUM(a.stars), 0) OR e.voters_count <> COUNT(a.id)')
            ->select('e.id', 'e.stars_total', 'e.voters_count')
            ->selectRaw('COALESCE(SUM(a.stars), 0) as ledger_stars, COUNT(a.id) as ledger_voters')
            ->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'entry_aggregate_mismatch', 'entry_id' => (int) $r->id, 'stored_stars' => (int) $r->stars_total, 'ledger_stars' => (int) $r->ledger_stars, 'stored_voters' => (int) $r->voters_count, 'ledger_voters' => (int) $r->ledger_voters];
        }

        // 2) ميزانيات الناخبين: المصروف يطابق الدفتر ولا يتجاوز الإجمالي.
        $rows = DB::table('season_writer_vote_budgets as b')
            ->leftJoin('season_writer_star_allocations as a', function ($join) {
                $join->on('a.season_id', '=', 'b.season_id')->on('a.voter_user_id', '=', 'b.user_id');
            })
            ->where('b.season_id', $seasonId)
            ->whereNotNull('b.user_id')
            ->groupBy('b.id', 'b.user_id', 'b.spent_stars', 'b.total_stars')
            ->havingRaw('b.spent_stars <> COALESCE(SUM(a.stars), 0) OR b.spent_stars > b.total_stars')
            ->select('b.user_id', 'b.spent_stars', 'b.total_stars')
            ->selectRaw('COALESCE(SUM(a.stars), 0) as ledger_spent')
            ->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'budget_mismatch', 'user_id' => (int) $r->user_id, 'stored_spent' => (int) $r->spent_stars, 'ledger_spent' => (int) $r->ledger_spent, 'total' => (int) $r->total_stars];
        }

        // 3) توزيع بلا صف ميزانية (لا يحدث عبر الخدمة).
        $rows = DB::table('season_writer_star_allocations as a')
            ->leftJoin('season_writer_vote_budgets as b', function ($join) {
                $join->on('b.season_id', '=', 'a.season_id')->on('b.user_id', '=', 'a.voter_user_id');
            })
            ->where('a.season_id', $seasonId)
            ->whereNotNull('a.voter_user_id')
            ->whereNull('b.id')
            ->select('a.id', 'a.voter_user_id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = ['code' => 'allocation_without_budget', 'allocation_id' => (int) $r->id, 'user_id' => (int) $r->voter_user_id];
        }

        // 4) تصويت لنفسه أو توزيع عابر للمواسم.
        $rows = DB::table('season_writer_star_allocations as a')
            ->join('season_writer_entries as e', 'e.id', '=', 'a.writer_entry_id')
            ->where('a.season_id', $seasonId)
            ->where(function ($q) {
                $q->whereColumn('a.voter_user_id', 'e.user_id')->orWhereColumn('a.season_id', '<>', 'e.season_id');
            })
            ->select('a.id', 'a.voter_user_id', 'e.user_id as writer_user_id', 'e.season_id as entry_season_id')->limit($limit)->get();

        foreach ($rows as $r) {
            $findings[] = [
                'code' => (int) $r->entry_season_id !== $seasonId ? 'allocation_cross_season' : 'self_vote_allocation',
                'allocation_id' => (int) $r->id,
            ];
        }

        // 5) قواعد الإعدادات: السعة، نافذة الأسبوع، سلامة اللقطة النهائية.
        $setting = $this->settings->settingFor($season);

        if ($setting !== null) {
            $count = SeasonWriterEntry::query()->where('season_id', $seasonId)->count();

            if ($count > min(SeasonRecognitionSetting::HARD_MAX_CAPACITY, (int) $setting->writers_capacity)) {
                $findings[] = ['code' => 'roster_over_capacity', 'roster' => $count, 'capacity' => (int) $setting->writers_capacity];
            }

            $latest = $this->settings->latestAllowedClose($season);

            if ($setting->writers_voting_closes_at !== null && ($latest === null || $setting->writers_voting_closes_at->greaterThan($latest))) {
                $findings[] = ['code' => 'closing_window_rule'];
            }

            if ($setting->writers_finalized_at !== null) {
                $missing = SeasonWriterEntry::query()->where('season_id', $seasonId)->where('status', SeasonWriterEntry::STATUS_ACTIVE)->where(function ($q) {
                    $q->whereNull('final_rank')->orWhereNull('outcome')->orWhereNull('finalized_at');
                })->count();

                if ($missing > 0) {
                    $findings[] = ['code' => 'final_snapshot_incomplete', 'entries' => $missing];
                }

                $ranks = SeasonWriterEntry::query()->where('season_id', $seasonId)->whereNotNull('final_rank')->selectRaw('COUNT(*) as n, COUNT(DISTINCT final_rank) as d, MIN(final_rank) as lo, MAX(final_rank) as hi')->first();

                if ($ranks !== null && (int) $ranks->n > 0 && ((int) $ranks->n !== (int) $ranks->d || (int) $ranks->lo !== 1 || (int) $ranks->hi !== (int) $ranks->n)) {
                    $findings[] = ['code' => 'final_ranks_not_contiguous'];
                }
            }
        }

        return array_slice($findings, 0, $limit);
    }

    /**
     * يعيد بناء المجاميع المشتقّة من الدفتر (قبل الإنهاء فقط). @return array{entries: int, budgets: int}
     *
     * @throws SeasonalRecognitionException
     */
    public function rebuildAggregates(Season $season, User $actor): array
    {
        if (! $actor->can(self::PERMISSION)) {
            throw new SeasonalRecognitionException('forbidden', 'لا تملك صلاحية مطابقة الدوري.');
        }

        $entriesFixed = DB::transaction(function () use ($season, $actor) {
            $setting = SeasonRecognitionSetting::query()->where('season_id', $season->id)->lockForUpdate()->first();

            if ($setting === null) {
                return 0;
            }

            if ($setting->writers_finalized_at !== null) {
                throw new SeasonalRecognitionException('finalized', 'انتهى الدوري؛ لا تُعاد مجاميعه.');
            }

            $ids = SeasonWriterEntry::query()->where('season_id', $season->id)->orderBy('id')->lockForUpdate()->pluck('id');
            $fixed = 0;

            $ledger = DB::table('season_writer_star_allocations')->where('season_id', $season->id)
                ->groupBy('writer_entry_id')
                ->selectRaw('writer_entry_id, SUM(stars) as s, COUNT(*) as c, MIN(created_at) as first_at, MAX(updated_at) as last_at')
                ->get()->keyBy('writer_entry_id');

            foreach (SeasonWriterEntry::query()->whereIn('id', $ids)->orderBy('id')->get() as $entry) {
                $l = $ledger->get($entry->id);
                $stars = (int) ($l->s ?? 0);
                $voters = (int) ($l->c ?? 0);

                if ((int) $entry->stars_total === $stars && (int) $entry->voters_count === $voters) {
                    continue;
                }

                $entry->forceFill([
                    'stars_total' => $stars,
                    'voters_count' => $voters,
                    'first_star_at' => $l?->first_at,
                    'last_star_at' => $l?->last_at,
                ])->save();
                $fixed++;
            }

            if ($fixed > 0) {
                $this->audit->log('seasonal_writers_aggregates_rebuilt', $setting, ['season' => $season->slug, 'entries' => $fixed], $actor);
            }

            return $fixed;
        });

        // الميزانيات خارج قفل الإعدادات: كل ناخب بمعاملة قصيرة تقفل ميزانيته أولًا (نفس ترتيب التصويت) ثم تعيد الجمع من الدفتر.
        $budgetsFixed = 0;
        $candidates = collect($this->inspect($season))->where('code', 'budget_mismatch')->pluck('user_id')->unique()->values();

        foreach ($candidates as $userId) {
            $budgetsFixed += DB::transaction(function () use ($season, $userId): int {
                $budget = SeasonWriterVoteBudget::query()->where('season_id', $season->id)->where('user_id', $userId)->lockForUpdate()->first();

                if ($budget === null) {
                    return 0;
                }

                $spent = (int) DB::table('season_writer_star_allocations')->where('season_id', $season->id)->where('voter_user_id', $userId)->sum('stars');

                if ($spent > (int) $budget->total_stars || $spent === (int) $budget->spent_stars) {
                    return 0; // التجاوز لا يُصحَّح بإعادة جمع: يبقى نتيجة مطابقة تُراجَع يدويًا.
                }

                $budget->forceFill(['spent_stars' => $spent])->save();

                return 1;
            });
        }

        if ($entriesFixed + $budgetsFixed > 0) {
            SeasonalRecognitionCache::bump((int) $season->id, SeasonalRecognitionCache::SCOPE_WRITERS);
        }

        return ['entries' => $entriesFixed, 'budgets' => $budgetsFixed];
    }
}
