<?php

namespace App\Services\SeasonalRecognition;

use App\Models\CreatorProfile;
use App\Models\Season;
use App\Models\SeasonWriterEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E44 - ترتيب كتّاب الموسم. **قراءة فقط** من المجاميع الحيّة المخزَّنة (stars_total/voters_count يكتبها التصويت ذريًا)، أو من اللقطة النهائية بعد الإنهاء.
 *
 * ترتيب حتمي واحد يشاركه العرض الحيّ والمُنهي (لا يختلفان أبدًا):
 *   stars_total DESC ⇒ voters_count DESC ⇒ last_star_at ASC (الأقدم أولًا؛ NULL أخيرًا) ⇒ id ASC.
 *   المرتبة تسلسلية 1..N بالترتيب نفسه (لا مراتب مكرَّرة)، فيكون كل «ثالث» وكل «أدنى خمسة» محسومًا بلا غموض.
 *
 * الخصوصية: الحمولة العامة تجميعية فقط (اسم الكاتب العام ورابط صفحته، النجوم، عدد الناخبين، المرتبة) - لا هوية ناخب ولا توزيع فردي ولا ميزانيات.
 *   الكاتب الذي لم يعد معتمدًا أو حسابه مجمَّد لا يظهر علنًا (يطابق سلوك صفحة المبدع العامة)؛ المرتبة الحيّة العلنية تُحسب بين الظاهرين فقط.
 * اللوحة العلنية تُخزَّن بالكاش بنمط الإصدار؛ كل تصويت/تعديل قائمة يُبطله بعد commit. اللوحة الإدارية لا تُخزَّن.
 */
class SeasonWriterLeaderboardService
{
    public function __construct(protected SeasonRecognitionSettingsService $settings) {}

    /**
     * استعلام الكتّاب النشطين بالترتيب الحتمي (يستعمله المُنهي مع قفل).
     *
     * @return Builder<SeasonWriterEntry>
     */
    public function orderedActiveQuery(int $seasonId): Builder
    {
        return SeasonWriterEntry::query()
            ->where('season_writer_entries.season_id', $seasonId)
            ->where('season_writer_entries.status', SeasonWriterEntry::STATUS_ACTIVE)
            ->orderByDesc('season_writer_entries.stars_total')
            ->orderByDesc('season_writer_entries.voters_count')
            ->orderByRaw('season_writer_entries.last_star_at IS NULL')
            ->orderBy('season_writer_entries.last_star_at')
            ->orderBy('season_writer_entries.id');
    }

    /**
     * اللوحة العلنية (مخزَّنة). بعد الإنهاء تُقرأ من اللقطة النهائية الثابتة.
     *
     * @return array{state: string, finalized: bool, generated_at: string, writers_count: int, rows: list<array<string, mixed>>}
     */
    public function publicBoard(Season $season): array
    {
        return SeasonalRecognitionCache::remember(
            (int) $season->id,
            SeasonalRecognitionCache::SCOPE_WRITERS,
            'public-board',
            fn () => $this->buildBoard($season, publicOnly: true),
        );
    }

    /**
     * اللوحة الإدارية: كل النشطين (حتى غير الظاهرين علنًا) مع علامة الظهور، بلا كاش.
     *
     * @return array{state: string, finalized: bool, generated_at: string, writers_count: int, rows: list<array<string, mixed>>}
     */
    public function adminBoard(Season $season): array
    {
        return $this->buildBoard($season, publicOnly: false);
    }

    /** مرتبة كاتب في اللوحة العلنية (null إن لم يظهر). */
    public function publicRankOf(Season $season, SeasonWriterEntry $entry): ?int
    {
        foreach ($this->publicBoard($season)['rows'] as $row) {
            if ($row['public_id'] === $entry->public_id) {
                return (int) $row['rank'];
            }
        }

        return null;
    }

    /**
     * مجاميع الموسم للإدارة والتحليلات (بلا هويات): عدد الكتّاب، مجموع النجوم، عدد الناخبين المميّزين.
     *
     * @return array{writers: int, active: int, stars: int, voters: int, allocations: int}
     */
    public function totals(Season $season): array
    {
        $entries = SeasonWriterEntry::query()->where('season_id', $season->id)
            ->selectRaw('COUNT(*) as writers, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active, COALESCE(SUM(stars_total), 0) as stars', [SeasonWriterEntry::STATUS_ACTIVE])
            ->first();

        $allocations = DB::table('season_writer_star_allocations')->where('season_id', $season->id)
            ->selectRaw('COUNT(*) as allocations, COUNT(DISTINCT voter_user_id) as voters')
            ->first();

        return [
            'writers' => (int) ($entries->writers ?? 0),
            'active' => (int) ($entries->active ?? 0),
            'stars' => (int) ($entries->stars ?? 0),
            'voters' => (int) ($allocations->voters ?? 0),
            'allocations' => (int) ($allocations->allocations ?? 0),
        ];
    }

    /**
     * @return array{state: string, finalized: bool, generated_at: string, writers_count: int, rows: list<array<string, mixed>>}
     */
    protected function buildBoard(Season $season, bool $publicOnly): array
    {
        $setting = $this->settings->settingFor($season);
        $finalized = $setting?->writers_finalized_at !== null;

        $query = SeasonWriterEntry::query()
            ->select([
                'season_writer_entries.id', 'season_writer_entries.public_id', 'season_writer_entries.status',
                'season_writer_entries.stars_total', 'season_writer_entries.voters_count', 'season_writer_entries.last_star_at',
                'season_writer_entries.final_rank', 'season_writer_entries.final_stars', 'season_writer_entries.final_voters_count', 'season_writer_entries.outcome',
                'users.name as writer_name', 'users.is_frozen as writer_frozen',
                'creator_profiles.slug as writer_slug', 'creator_profiles.status as profile_status',
            ])
            ->join('creator_profiles', 'creator_profiles.id', '=', 'season_writer_entries.creator_profile_id')
            ->join('users', 'users.id', '=', 'season_writer_entries.user_id')
            ->where('season_writer_entries.season_id', $season->id);

        if ($finalized) {
            $query->whereNotNull('season_writer_entries.final_rank')->orderBy('season_writer_entries.final_rank');
        } else {
            $query->where('season_writer_entries.status', SeasonWriterEntry::STATUS_ACTIVE)
                ->orderByDesc('season_writer_entries.stars_total')
                ->orderByDesc('season_writer_entries.voters_count')
                ->orderByRaw('season_writer_entries.last_star_at IS NULL')
                ->orderBy('season_writer_entries.last_star_at')
                ->orderBy('season_writer_entries.id');
        }

        $rows = [];
        $position = 0;

        foreach ($query->get() as $row) {
            $visible = $row->profile_status === CreatorProfile::STATUS_APPROVED && ! (bool) $row->writer_frozen;

            if ($publicOnly && ! $visible) {
                continue;
            }

            $position++;

            $rows[] = [
                'rank' => $finalized ? (int) $row->final_rank : $position,
                'public_id' => (string) $row->public_id,
                'name' => (string) $row->writer_name,
                'slug' => (string) $row->writer_slug,
                'stars' => $finalized ? (int) $row->final_stars : (int) $row->stars_total,
                'voters' => $finalized ? (int) $row->final_voters_count : (int) $row->voters_count,
                'outcome' => $finalized ? (string) $row->outcome : null,
                'visible' => $visible,
            ];
        }

        if ($publicOnly) {
            // لا حقول إدارية في الحمولة العلنية.
            $rows = array_map(static function (array $r): array {
                unset($r['visible']);

                return $r;
            }, $rows);
        }

        return [
            'state' => $this->settings->votingState($season, $setting),
            'finalized' => $finalized,
            'generated_at' => now()->toIso8601String(),
            'writers_count' => count($rows),
            'rows' => $rows,
        ];
    }
}
