<?php

namespace App\Services\Engagement;

use App\Support\CachedSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * E29 - جودة أحجيات المجتمع: ترتيب «الأعلى تقييمًا» بدرجة انكماش بايزية بسيطة وقابلة للشرح (انظر config/engagement.php).
 *
 *  score = (ratings_sum + prior_mean × prior_weight) / (ratings_count + prior_weight)
 *
 *  - لا ORDER BY AVG وحده أبدًا: تقييم واحد بخمس نجوم لا يتفوق على 4.8 من 500 تقييم.
 *  - حدّ أدنى لعدد التقييمات (min_ratings) قبل الأهلية للترتيب.
 *  - الكاش يحمل **معرّفات ودرجات فقط** (لا أسماء ولا هويات تقييم)؛ الظهور يُفحص حيًّا بكل طلب بمن يستهلكه (CommunityDiscovery)، فالمخفي يختفي فورًا ولو بقي بالكاش.
 *  - TTL قصير ولا إبطال يدوي: أسوأ حالة تأخير = TTL لتغيّر الترتيب فقط (لا لظهور محتوى مخفي).
 *  - لا أثر اقتصادي لأي ترتيب أو وسم هنا (عرض فقط).
 */
class CommunityQualityService
{
    public function minRatings(): int
    {
        return max(1, (int) config('engagement.top_rated.min_ratings', 5));
    }

    public function eligible(int $count): bool
    {
        return $count >= $this->minRatings();
    }

    /** الدرجة المنكمشة. حتمية: نفس المدخلات = نفس الناتج. */
    public function score(int $count, int $sum): float
    {
        $mean = (float) config('engagement.top_rated.prior_mean', 3.5);
        $weight = max(0.0, (float) config('engagement.top_rated.prior_weight', 5));
        $denominator = $count + $weight;

        return $denominator > 0 ? ($sum + $mean * $weight) / $denominator : 0.0;
    }

    /** متوسط خام بخانة عشرية، أو null إن قلّ العدد عن حدّ العرض (عيّنة صغيرة لا تُعرض كمتوسط). */
    public function displayAverage(int $count, int $sum): ?float
    {
        return $count >= max(1, (int) config('engagement.ratings.display_min', 3)) ? round($sum / $count, 1) : null;
    }

    /** ملخّص جودة المبدع على أعماله الظاهرة، أو null إن قلّ مجموع تقييماته عن الحدّ. @return array{average: float, count: int}|null */
    public function creatorSummary(int $count, int $sum): ?array
    {
        if ($count < max(1, (int) config('engagement.creator_quality.min_ratings', 5))) {
            return null;
        }

        return ['average' => round($sum / $count, 1), 'count' => $count];
    }

    /**
     * أفضل الأعمال الظاهرة المؤهلة بالدرجة. معرّفات + درجات فقط.
     *
     * @return array<int, array{score: float, count: int, average: float}> مرتبة تنازليًا بالدرجة ثم عدد التقييمات ثم الأحدث.
     */
    public function topRated(): array
    {
        return CachedSnapshot::remember('discover.community.top_rated', (int) config('engagement.top_rated.cache_ttl', 300), fn () => $this->computeTopRated());
    }

    /** @return array<int, array{score: float, count: int, average: float}> */
    public function computeTopRated(): array
    {
        $mean = (float) config('engagement.top_rated.prior_mean', 3.5);
        $weight = max(0.0, (float) config('engagement.top_rated.prior_weight', 5));
        $rows = DB::table('creator_puzzles')
            ->where('is_visible', true)
            ->where('ratings_count', '>=', $this->minRatings())
            ->selectRaw('id, ratings_count, ratings_sum, (ratings_sum + ?) / (ratings_count + ?) as weighted', [$mean * $weight, $weight])   // قيم الإعداد عبر ربط معاملات لا دمج نصي
            ->orderByRaw('weighted DESC')->orderByDesc('ratings_count')->orderByDesc('id')
            ->limit(max(1, (int) config('engagement.top_rated.pool', 40)))
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->id => [
            'score' => $this->score((int) $r->ratings_count, (int) $r->ratings_sum),
            'count' => (int) $r->ratings_count,
            'average' => round(((int) $r->ratings_sum) / (int) $r->ratings_count, 1),
        ]])->all();
    }
}
