<?php

namespace App\Services\SeasonalRecognition;

use App\Models\Season;
use App\Models\SeasonSupporterCard;
use App\Models\SeasonSupporterPackage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E44 - طبقة العرض المشتقّة لتقدير الداعم بجانب اسم اللاعب: نمط الاسم الموسمي (فضي/ذهبي/ماسي) وشارة «داعم الموسم #N». **لا تغيّر أي عنصر تجميلي أو ملكية أو لوحة تحميل**؛
 * هي قراءة فقط تُحسب عند العرض، ولا تحمل CSS ولا ألوانًا حرة: مفتاح نمط من قائمة بيضاء مغلقة يترجمه CSS الموقع (data-season-style).
 *
 *  - الموسم المعروض = الموسم الحيّ الوحيد المؤهَّل (SeasonSupportSeasonResolver)؛ غموض أو غياب ⇒ لا تقدير يُعرض (والكل يعمل كما كان).
 *  - الأولوية: ماسي > ذهبي > فضي > عادي. المستوى من أعلى بطاقة **نشطة غير مجهولة**؛ المجهول لا يُكشف أبدًا بنمط أو شارة.
 *  - يُستبعد من كل سطح عام: حساب مجمَّد، أو ملف داعم E30 غير علني/غير نشط/موقوف، أو ملف مؤسسة. الشارة الديناميكية للمرتّبين ضمن أعلى {leaderboard_limit} فقط (خريطة داخلية مخزَّنة).
 *  - أداء: استعلام واحد لكل مجموعة معرّفات (يستدعيها عارض الصفحة بمجموعة المستخدمين الظاهرين لا بنداء لكل صف) + كاش قصير لهوية الموسم.
 */
class SeasonSupporterIdentityResolver
{
    public function __construct(
        protected SeasonSupportSeasonResolver $seasons,
        protected SeasonSupporterLeaderboardService $leaderboard,
    ) {}

    public function displaySeason(): ?Season
    {
        try {
            $ttl = max(1, (int) config('seasonal_recognition.cache.identity_ttl_seconds', 60));
            $id = (int) Cache::remember('seasonal:supporters:display-season', $ttl, fn () => (int) ($this->seasons->currentSeason()?->id ?? 0));

            return $id > 0 ? Season::query()->find($id) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  list<int|string>  $userIds
     * @return array<int, array{tier: string, tier_name: string, style: string, rank: ?int, badge: ?string}>
     */
    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === [] || ! (bool) config('seasonal_recognition.supporters.enabled', false)) {
            return [];
        }

        try {
            $season = $this->displaySeason();

            if ($season === null) {
                return [];
            }

            $cards = DB::table('season_supporter_cards as c')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->leftJoin('supporter_profiles as sp', 'sp.user_id', '=', 'u.id')
                ->where('c.season_id', $season->id)
                ->where('c.status', SeasonSupporterCard::STATUS_ACTIVE)
                ->where('c.is_anonymous', false)
                ->whereIn('c.user_id', $userIds)
                ->where('u.is_frozen', false)
                ->where(function ($q) {
                    $q->whereNull('sp.id')->orWhere(function ($q2) {
                        $q2->where('sp.status', 'active')->where('sp.is_public', true)->where('sp.type', 'individual');
                    });
                })
                ->get(['c.user_id', 'c.tier_key', 'c.name_style_key']);

            $best = [];

            foreach ($cards as $card) {
                $uid = (int) $card->user_id;

                if (! isset($best[$uid]) || SeasonSupporterPackage::rankOf($card->tier_key) > SeasonSupporterPackage::rankOf($best[$uid]->tier_key)) {
                    $best[$uid] = $card;
                }
            }

            $ranks = $best === [] ? [] : $this->leaderboard->rankMap($season);
            $styles = (array) config('seasonal_recognition.supporters.name_styles', []);
            $out = [];

            foreach ($best as $uid => $card) {
                $rank = $ranks[$uid] ?? null;
                $style = in_array($card->name_style_key, $styles, true) ? (string) $card->name_style_key : '';

                if ($style === '') {
                    continue;
                }

                $out[$uid] = [
                    'tier' => (string) $card->tier_key,
                    'tier_name' => (string) config('seasonal_recognition.supporters.card_tiers.'.$card->tier_key.'.name', ''),
                    'style' => $style,
                    'rank' => $rank,
                    'badge' => $rank !== null ? 'داعم الموسم #'.$rank : null,
                ];
            }

            return $out;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return array{tier: string, tier_name: string, style: string, rank: ?int, badge: ?string}|null */
    public function forUser(int $userId): ?array
    {
        return $this->forUsers([$userId])[$userId] ?? null;
    }
}
