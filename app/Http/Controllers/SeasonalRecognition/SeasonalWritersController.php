<?php

namespace App\Http\Controllers\SeasonalRecognition;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeasonalRecognition\AllocateStarsRequest;
use App\Models\CreatorProfile;
use App\Models\Season;
use App\Models\SeasonWriterEntry;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\SeasonalRecognition\SeasonalRecognitionException;
use App\Services\SeasonalRecognition\SeasonalRecognitionVisibility;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonWriterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonWriterVotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * E44 - صفحة «كتّاب الموسم» (ترتيب حيّ + توزيع النجوم). طبقة عرض رقيقة: **لا أهلية ولا ميزانية ولا نافذة تُحسم هنا** - كلها في SeasonWriterVotingService داخل معاملة.
 *  - show/standings: قراءة عامة بلا حساب. الحمولة تجميعية (الاسم العام، النجوم، عدد الناخبين، المرتبة) بلا هوية ناخب ولا توزيع فردي.
 *  - allocate: مستخدم موثَّق فقط؛ الكاتب من المسار (مقيَّد بالموسم)، والناخب من الجلسة، والجسم يحمل stars فقط.
 */
class SeasonalWritersController extends Controller
{
    public function __construct(
        protected SeasonalRecognitionVisibility $visibility,
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonWriterLeaderboardService $leaderboard,
        protected SeasonWriterVotingService $voting,
        protected CreatorSeriesVisibilityService $seriesVisibility,
    ) {}

    public function show(Request $request, Season $season)
    {
        $user = $request->user();
        abort_unless($this->visibility->writersAvailable($season, $user), 404);

        $season->loadMissing('campaign:id,title');
        $setting = $this->settings->settingFor($season);
        $state = $this->settings->votingState($season, $setting);
        $board = $this->leaderboard->publicBoard($season);

        return view('seasonal.writers', [
            'season' => $season,
            'title' => (string) ($season->campaign?->title ?? $season->slug),
            'setting' => $setting,
            'state' => $state,
            'opens' => $setting?->writers_voting_opens_at,
            'closes' => $setting !== null ? $this->settings->effectiveClose($season, $setting) : null,
            'board' => $board,
            'voter' => $user !== null ? $this->voterView($user, $season, $state) : null,
            'pollSeconds' => max(5, (int) config('seasonal_recognition.realtime.poll_seconds', 20)),
            'realtime' => (bool) config('seasonal_recognition.realtime.enabled', true),
            'debounceMs' => max(0, (int) config('seasonal_recognition.realtime.client_debounce_ms', 800)),
            'perMinute' => max(1, (int) config('seasonal_recognition.writers.mutations_per_minute', 30)),
        ]);
    }

    /** جزء HTML للترتيب الحيّ (علني بالكامل) - يجلبه العميل عند إشارة البثّ أو الاستطلاع. */
    public function standings(Request $request, Season $season): Response
    {
        abort_unless($this->visibility->writersAvailable($season, $request->user()), 404);

        $html = view('seasonal._writers_standings', ['board' => $this->leaderboard->publicBoard($season)])->render();

        return response($html)->header('Cache-Control', 'public, max-age=5')->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function allocate(AllocateStarsRequest $request, Season $season, string $entry): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->visibility->writersAvailable($season, $user), 404);

        $model = SeasonWriterEntry::query()->where('public_id', $entry)->where('season_id', $season->id)->firstOrFail();

        try {
            $result = $this->voting->allocate($user, $model, (int) $request->validated('stars'));
        } catch (SeasonalRecognitionException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true] + array_intersect_key($result, array_flip(['stars', 'spent', 'remaining', 'total'])));
        }

        return back()->with('success', $result['stars'] === 0 ? 'سُحبت نجومك من هذا الكاتب.' : 'تم حفظ نجومك.');
    }

    /**
     * لوحة الناخب: الميزانية وتوزيعاته والكتّاب الذين يحق له التصويت لهم، وسلاسلهم المربوطة الظاهرة (تلميح لمن لم يلعب بعد).
     *
     * @return array<string, mixed>
     */
    protected function voterView(User $user, Season $season, string $state): array
    {
        $panel = $this->voting->panel($user, $season);

        $writers = SeasonWriterEntry::query()
            ->select(['season_writer_entries.id', 'season_writer_entries.public_id', 'season_writer_entries.user_id', 'users.name as writer_name', 'creator_profiles.slug as writer_slug'])
            ->join('creator_profiles', 'creator_profiles.id', '=', 'season_writer_entries.creator_profile_id')
            ->join('users', 'users.id', '=', 'season_writer_entries.user_id')
            ->where('season_writer_entries.season_id', $season->id)
            ->where('season_writer_entries.status', SeasonWriterEntry::STATUS_ACTIVE)
            ->where('creator_profiles.status', CreatorProfile::STATUS_APPROVED)
            ->where('users.is_frozen', false)
            ->orderBy('users.name')->orderBy('season_writer_entries.id')
            ->get();

        $series = $writers->isEmpty() ? collect() : $this->seriesVisibility->visibleQuery()
            ->join('season_writer_series as l', 'l.creator_series_id', '=', 'creator_series.id')
            ->whereIn('l.writer_entry_id', $writers->pluck('id')->all())
            ->orderBy('creator_series.published_title')
            ->get(['creator_series.public_id', 'creator_series.published_title', 'l.writer_entry_id'])
            ->groupBy('writer_entry_id');

        $votable = array_flip($panel['votable']);

        return [
            'open' => $panel['open'],
            'total' => $panel['total'],
            'spent' => $panel['spent'],
            'remaining' => $panel['remaining'],
            'rows' => $writers->map(fn ($w) => [
                'public_id' => (string) $w->public_id,
                'name' => (string) $w->writer_name,
                'slug' => (string) $w->writer_slug,
                'self' => (int) $w->user_id === (int) $user->id,
                'can_vote' => isset($votable[(int) $w->id]),
                'stars' => (int) ($panel['allocations'][(int) $w->id] ?? 0),
                'series' => ($series[$w->id] ?? collect())->take(3)->map(fn ($s) => ['id' => (string) $s->public_id, 'title' => (string) $s->published_title])->values()->all(),
            ])->all(),
            'state' => $state,
        ];
    }
}
