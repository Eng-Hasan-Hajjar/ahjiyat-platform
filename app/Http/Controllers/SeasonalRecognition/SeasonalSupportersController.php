<?php

namespace App\Http\Controllers\SeasonalRecognition;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeasonalRecognition\RequestSupporterCardRequest;
use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use App\Models\SeasonSupporterStat;
use App\Services\SeasonalRecognition\SeasonalRecognitionException;
use App\Services\SeasonalRecognition\SeasonalRecognitionVisibility;
use App\Services\SeasonalRecognition\SeasonRecognitionSettingsService;
use App\Services\SeasonalRecognition\SeasonSupporterCardService;
use App\Services\SeasonalRecognition\SeasonSupporterLeaderboardService;
use App\Services\SeasonalRecognition\SeasonSupporterPackageService;
use App\Services\SeasonLifecycleService;
use App\Services\Sponsorship\SponsorshipException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * E44 - صفحة «داعمو الموسم» (لاعبون فقط) وطلب بطاقة الداعم. طبقة عرض رقيقة فوق خدمات تحسم كل شيء على الخادم:
 *  - السعر والعملة والنقاط والنمط من الباقة لا من العميل؛ الدفع الحقيقي بالكامل عبر E32 (الطلب يقود إلى صفحة الدفع القائمة support.payments.show).
 *  - نقاط الدعم للتقدير فقط: ليست مالًا ولا جواهر ولا خبرة، ولا تمنح أي أفضلية لعب. رفع إثبات الدفع لا يمنح شيئًا؛ التحقق وحده يفعّل البطاقة.
 *  - الاسم العام فقط في اللوحة؛ المجهول لا اسم له ولا يدخل الترتيب العلني.
 */
class SeasonalSupportersController extends Controller
{
    public function __construct(
        protected SeasonalRecognitionVisibility $visibility,
        protected SeasonRecognitionSettingsService $settings,
        protected SeasonSupporterLeaderboardService $leaderboard,
        protected SeasonSupporterPackageService $packages,
        protected SeasonSupporterCardService $cards,
        protected SeasonLifecycleService $lifecycle,
    ) {}

    public function show(Request $request, Season $season)
    {
        $user = $request->user();
        abort_unless($this->visibility->supportersAvailable($season, $user), 404);

        $season->loadMissing('campaign:id,title');
        $setting = $this->settings->settingFor($season);
        $finalized = $setting?->supporters_finalized_at !== null;
        $canBuy = $this->settings->supportersActive($season, $setting) && ! $finalized && $this->lifecycle->isLive($season);

        $mine = null;

        if ($user !== null) {
            $stat = SeasonSupporterStat::query()->where('season_id', $season->id)->where('user_id', $user->id)->first();
            $rankMap = $this->leaderboard->rankMap($season);
            $mine = [
                'points' => (int) ($stat?->points_total ?? 0),
                'tier' => $stat?->card_tier_key,
                'rank' => $rankMap[(int) $user->id] ?? null,
                'final_rank' => $stat?->final_rank,
                'cards' => SeasonSupporterCard::query()->where('season_id', $season->id)->where('user_id', $user->id)->orderByDesc('id')->limit(20)->with('payment:id,public_id')->get(),
            ];
        }

        return view('seasonal.supporters', [
            'season' => $season,
            'title' => (string) ($season->campaign?->title ?? $season->slug),
            'finalized' => $finalized,
            'canBuy' => $canBuy,
            'packages' => $canBuy ? $this->packages->packagesFor($season, true) : collect(),
            'board' => $this->leaderboard->publicBoard($season),
            'recent' => $this->leaderboard->recentCards($season, 10),
            'mine' => $mine,
            'requestKey' => (string) Str::ulid(),
            'pollSeconds' => max(5, (int) config('seasonal_recognition.realtime.poll_seconds', 20)),
            'realtime' => (bool) config('seasonal_recognition.realtime.enabled', true),
            'debounceMs' => max(0, (int) config('seasonal_recognition.realtime.client_debounce_ms', 800)),
        ]);
    }

    public function standings(Request $request, Season $season): Response
    {
        abort_unless($this->visibility->supportersAvailable($season, $request->user()), 404);

        $html = view('seasonal._supporters_standings', ['board' => $this->leaderboard->publicBoard($season)])->render();

        return response($html)->header('Cache-Control', 'public, max-age=5')->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function requestCard(RequestSupporterCardRequest $request, Season $season): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->visibility->supportersAvailable($season, $user), 404);

        $input = $request->validated();
        $package = SeasonSupporterPackage::query()->where('season_id', $season->id)->where('tier_key', $input['tier'])->first();

        if ($package === null) {
            return back()->with('error', 'هذه البطاقة غير متاحة.');
        }

        try {
            $card = $this->cards->request(
                $user,
                $package,
                $input['visibility'] === 'anonymous',
                isset($input['display_name']) ? (string) $input['display_name'] : null,
                isset($input['request_key']) ? (string) $input['request_key'] : null,
            );
        } catch (SeasonalRecognitionException|SponsorshipException $e) {
            return back()->withInput($request->except(['request_key']))->with('error', $e->getMessage());
        }

        return redirect()->route('support.payments.show', ['payment' => $card->payment()->value('public_id')])
            ->with('success', 'تم إنشاء طلب البطاقة. أتمم التحويل ثم أرسل الإثبات؛ لن تُفعَّل البطاقة ولن تُحتسب نقاطها قبل تحقق الفريق من الدفع.');
    }
}
