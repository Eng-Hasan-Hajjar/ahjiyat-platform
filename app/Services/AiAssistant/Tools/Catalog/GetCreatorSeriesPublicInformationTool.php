<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** E45 - get_creator_series_public_information: بيانات وصفية لسلسلة ظاهرة علنًا (العنوان/الوصف/عدد العناصر/المبدع/كونها مميزة وسعرها). لا عناصر ولا حلول ولا محتوى محمي. */
final class GetCreatorSeriesPublicInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_creator_series_public_information';
    }

    public function description(): string
    {
        return 'Get public metadata of a visible creator series by its public id or slug: title, description, number of puzzles, creator name, and whether it is premium (paid access) with the price. Never returns puzzles or solutions.';
    }

    public function properties(): array
    {
        return ['series' => ['type' => 'string', 'description' => 'Series public id (ULID) or slug.', 'maxLength' => 110]];
    }

    public function required(): array
    {
        return ['series'];
    }

    public function area(): string
    {
        return 'creator_series';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        if (! (bool) config('creator_series.enabled', true)) {
            return $this->unavailable('سلاسل المبدعين غير مفعّلة حاليًا.');
        }

        $key = (string) $arguments['series'];
        $query = app(CreatorSeriesVisibilityService::class)->visibleQuery()
            ->with(['creator:id,user_id,slug', 'creator.user:id,name', 'publishedRevision:id,creator_series_id,title,description']);

        /** @var CreatorSeries|null $series */
        $series = (Str::isUlid($key) ? $query->where('creator_series.public_id', Str::upper($key)) : $query->where('creator_series.slug', $key))->first();

        if ($series === null || $series->publishedRevision === null) {
            return $this->notFound('لم أجد سلسلة ظاهرة بهذا المعرّف.');
        }

        $offer = DB::table('creator_series_sale_offers')->where('live_series_id', $series->getKey())->first(['status', 'price_minor', 'currency']);
        $premium = $offer !== null && in_array($offer->status, CreatorSeriesSaleOffer::LOCKING_STATUSES, true);
        $path = Presenter::path('series.show', ['series' => $series->public_id]);

        return $this->ok(
            [
                'series' => [
                    'title' => Presenter::text($series->publishedRevision->title, 120),
                    'description' => Presenter::text($series->publishedRevision->description, 280),
                    'puzzles_count' => (int) $series->published_items_count,
                    'creator' => Presenter::text($series->creator?->user?->name, 80),
                    'is_premium' => $premium,
                    'premium_price' => $premium ? SponsorshipMoney::format((int) $offer->price_minor, (string) $offer->currency) : null,
                    'premium_on_sale' => $premium && $offer->status === CreatorSeriesSaleOffer::STATUS_ACTIVE,
                    'path' => $path,
                ],
            ],
            array_values(array_filter([Presenter::link('صفحة السلسلة', $path)])),
            ['title', 'description', 'creator'],
        );
    }
}
