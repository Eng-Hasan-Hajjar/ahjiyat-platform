<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Services\Reputation\ReputationRankings;
use App\Services\Reputation\ReputationSettings;
use Illuminate\Http\Request;

/**
 * E37 - صفحة «ترتيبات المجتمع» العامة /community/rankings. قراءة فقط من لقطات السمعة المشتقّة مع **فحص حيّ** للظهور والأهلية والحظر (انظر ReputationRankings).
 * كل قسم معزول الفشل (قسم تعطّل لا يكسر الصفحة). لا درجات خام ولا أي بيانات خاصة/مالية. قسم المساهمين المفيدين يختفي إن عُطّلت النقاشات.
 */
class CommunityRankingsController extends Controller
{
    public function __construct(protected ReputationRankings $rankings, protected ReputationSettings $settings) {}

    public function index(Request $request)
    {
        abort_unless($this->rankings->enabled(), 404);

        $viewer = $request->user();
        $discussions = (bool) config('discussions.enabled', true);

        return view('community.rankings', [
            'featured' => $this->safe(fn () => $this->rankings->featured($viewer)),
            'creators' => $this->safe(fn () => $this->rankings->creators($viewer)),
            'rising' => $this->safe(fn () => $this->rankings->rising($viewer)),
            'curators' => $this->safe(fn () => $this->rankings->curators($viewer)),
            'helpful' => $discussions ? $this->safe(fn () => $this->rankings->helpful($viewer)) : null,
            'windowDays' => $this->settings->windowDays(),
            'minRatings' => $this->settings->int('creator.eligibility.min_ratings', 8),
            'minWorks' => $this->settings->int('creator.eligibility.min_works', 2),
        ]);
    }

    /** @return list<array<string, mixed>> */
    protected function safe(\Closure $read): array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
