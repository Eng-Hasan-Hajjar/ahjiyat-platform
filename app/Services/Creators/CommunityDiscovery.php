<?php

namespace App\Services\Creators;

use App\Models\CommunityPuzzleAttempt;
use App\Models\CreatorPuzzle;
use App\Models\User;
use App\Services\Discovery\DiscoverCard;
use App\Support\CachedSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * E28 / E27 - أحجيات المجتمع بالاكتشاف: رائج + جديد + مقترحة للمسجَّل، **بسقف أحجيات لكل مبدع** (تنوّع المبدعين: مبدع غزير لا يحتكر القسم).
 *
 * قواعد ثابتة:
 *  - يقرأ فقط الأعمال الظاهرة (is_visible) في كل طلب؛ الكاش يحمل **معرّفات ودرجات فقط** (لا أسماء ولا بيانات لاعبين)، فإخفاء الإشراف أو تعليق المبدع يظهر فورًا ولو بقيت اللقطة بالكاش.
 *  - الرائج = لاعبون **مميَّزون** (لا محاولات) بحسابات موثَّقة غير مجمَّدة خلال نافذة E27 نفسها، بحدّ أدنى من اللاعبين؛ جدول community_puzzle_attempts وحده (لا puzzle_attempts).
 *    فلا تأثير لأي شيء هنا على الرائج الرسمي ولا على توصيات الأحجيات الرسمية، ولا على الاقتصاد/XP/المهام (عرض فقط).
 *  - عدد الاستعلامات ثابت لا يتبع حجم البيانات: جديد (1) + رائج (1 إن وُجدت لقطة) + للمسجَّل: سجلّه (1). الحساب الثقيل مخزَّن (TTL مثل رائج E27).
 *  - لا مكافآت ولا رعاية ولا ترتيب مدفوع. المبدع لا تُقترح عليه أعماله.
 */
class CommunityDiscovery
{
    /**
     * @return array{trending: list<DiscoverCard>, new: list<DiscoverCard>, for_you: list<DiscoverCard>}
     */
    public function sections(?User $viewer): array
    {
        $limit = max(1, (int) config('creators.discovery.section_limit', 8));
        $cap = max(1, (int) config('creators.discovery.per_creator_cap', 2));
        $pool = $this->newestPool();

        $trending = $this->trendingWorks($limit, $cap);
        $trendingIds = $trending->pluck('id')->all();

        $new = $this->capPerCreator($pool->reject(fn ($w) => in_array($w->id, $trendingIds, true)), $cap, $limit);

        $forYou = collect();

        if ($viewer !== null) {
            $seen = CommunityPuzzleAttempt::query()->where('user_id', $viewer->id)->distinct()
                ->limit((int) config('search.recommendations.history_cap', 500))->pluck('creator_puzzle_id')->all();
            $shown = array_merge($trendingIds, $new->pluck('id')->all(), $seen);

            $forYou = $this->capPerCreator(
                $pool->reject(fn ($w) => in_array($w->id, $shown, true) || (int) $w->creator_user_id === $viewer->id)->sort(fn ($a, $b) => [(int) $b->plays_count, (int) $b->id] <=> [(int) $a->plays_count, (int) $a->id])->values(),
                $cap,
                min($limit, (int) config('creators.discovery.reco_limit', 4)),
            );
        }

        return [
            'trending' => $trending->map(fn ($w) => $this->card($w, $w->trending_players.' لاعب هذا الأسبوع'))->values()->all(),
            'new' => $new->map(fn ($w) => $this->card($w, null))->values()->all(),
            'for_you' => $forYou->map(fn ($w) => $this->card($w, 'لم تجرّبها بعد'))->values()->all(),
        ];
    }

    /** أحدث الأعمال الظاهرة (مجمَّع قراءة واحد بضمّ التصنيف واسم المبدع: لا N+1). */
    protected function newestPool(): Collection
    {
        return $this->visibleQuery()
            ->orderByDesc('creator_puzzles.published_at')->orderByDesc('creator_puzzles.id')
            ->limit(max(1, (int) config('creators.discovery.reco_pool', 40)))->get();
    }

    /** @return Collection<int, CreatorPuzzle> أعمال ظاهرة رائجة بترتيب الدرجة، بسقف لكل مبدع؛ كل عنصر يحمل trending_players. */
    protected function trendingWorks(int $limit, int $cap): Collection
    {
        $scores = $this->trendingScores();

        if ($scores === []) {
            return collect();
        }

        $works = $this->visibleQuery()->whereIn('creator_puzzles.id', array_keys($scores))->get()->keyBy('id');

        $ordered = collect($scores)
            ->filter(fn (array $row, int $id) => $works->has($id))
            ->map(function (array $row, int $id) use ($works) {
                $work = $works->get($id);
                $work->setAttribute('trending_players', $row['players']);

                return $work;
            })
            ->values();

        return $this->capPerCreator($ordered, $cap, $limit);
    }

    /**
     * معرّفات + درجات فقط (لا بيانات لاعبين). الدرجة = لاعبون مميَّزون + وزن إضافي لمن حلّوا.
     *
     * @return array<int, array{players: int, score: int}> مرتبة بالدرجة ثم الأحدث.
     */
    public function trendingScores(): array
    {
        return CachedSnapshot::remember('discover.community.trending', (int) config('search.trending.cache_ttl', 300), function () {
            $days = max(1, (int) config('search.trending.window_days', 7));
            $min = max(1, (int) config('creators.discovery.trending_min_players', 2));
            $solverWeight = (int) config('search.trending.solver_weight', 2);

            $rows = DB::table('community_puzzle_attempts as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.created_at', '>=', now()->subDays($days))
                ->whereNotNull('u.email_verified_at')->where('u.is_frozen', false)
                ->groupBy('a.creator_puzzle_id')
                ->selectRaw('a.creator_puzzle_id as id, COUNT(DISTINCT a.user_id) as players, COUNT(DISTINCT CASE WHEN a.is_correct THEN a.user_id END) as solvers')
                ->havingRaw('COUNT(DISTINCT a.user_id) >= ?', [$min])
                ->get();

            return $rows
                ->map(fn ($r) => ['id' => (int) $r->id, 'players' => (int) $r->players, 'score' => (int) $r->players + $solverWeight * (int) $r->solvers])
                ->sort(fn ($a, $b) => [$b['score'], $b['id']] <=> [$a['score'], $a['id']])
                ->take(max(1, (int) config('creators.discovery.reco_pool', 40)))
                ->mapWithKeys(fn ($r) => [$r['id'] => ['players' => $r['players'], 'score' => $r['score']]])
                ->all();
        });
    }

    protected function visibleQuery(): Builder
    {
        return CreatorPuzzle::query()->visible()
            ->join('creator_profiles', 'creator_profiles.id', '=', 'creator_puzzles.creator_profile_id')
            ->join('users', 'users.id', '=', 'creator_profiles.user_id')
            ->leftJoin('puzzle_categories', 'puzzle_categories.id', '=', 'creator_puzzles.published_category_id')
            ->select(
                'creator_puzzles.id', 'creator_puzzles.public_id', 'creator_puzzles.creator_profile_id', 'creator_puzzles.published_title',
                'creator_puzzles.published_difficulty', 'creator_puzzles.plays_count', 'creator_puzzles.published_at',
                'creator_profiles.user_id as creator_user_id', 'users.name as creator_name', 'puzzle_categories.name as category_name',
            );
    }

    /** يحافظ على الترتيب ويتجاوز ما فاق سقف المبدع الواحد حتى يبلغ $limit. */
    protected function capPerCreator(Collection $works, int $cap, int $limit): Collection
    {
        $perCreator = [];

        return $works->filter(function ($work) use (&$perCreator, $cap) {
            $perCreator[$work->creator_profile_id] = ($perCreator[$work->creator_profile_id] ?? 0) + 1;

            return $perCreator[$work->creator_profile_id] <= $cap;
        })->take($limit)->values();
    }

    protected function card($work, ?string $reason): DiscoverCard
    {
        $difficulty = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$work->published_difficulty] ?? null;
        $by = 'بقلم '.$work->creator_name;

        return new DiscoverCard(
            (string) $work->published_title,
            route('community.puzzles.show', $work->public_id),
            'friends',
            $work->category_name ? $work->category_name.' · '.$by : $by,
            'من المجتمع'.($difficulty ? ' · '.$difficulty : ''),
            'primary',
            $reason,
        );
    }
}
