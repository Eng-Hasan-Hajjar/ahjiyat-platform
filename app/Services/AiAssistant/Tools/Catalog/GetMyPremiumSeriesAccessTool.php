<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\CreatorPremium\CreatorPremiumAccess;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Support\Str;

/** E45 - get_my_premium_series_access: هل للمستخدم نفسه وصول لسلسلة مميزة (مجانية/مشتراة/ملكه كمبدع/غير مشتراة). لا محتوى السلسلة ولا حلولها ولا مشترين آخرين ولا مراجع دفع. */
final class GetMyPremiumSeriesAccessTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_premium_series_access';
    }

    public function description(): string
    {
        return "Check whether the signed-in member has access to a creator series (free, purchased, own series as creator, or not purchased). Never returns the series puzzles or solutions.";
    }

    public function properties(): array
    {
        return ['series' => ['type' => 'string', 'description' => 'Series public id (ULID) or slug.', 'maxLength' => 110]];
    }

    public function required(): array
    {
        return ['series'];
    }

    public function personal(): bool
    {
        return true;
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
        $query = app(CreatorSeriesVisibilityService::class)->visibleQuery()->with('publishedRevision:id,creator_series_id,title');

        /** @var CreatorSeries|null $series */
        $series = (Str::isUlid($key) ? $query->where('creator_series.public_id', Str::upper($key)) : $query->where('creator_series.slug', $key))->first();

        if ($series === null) {
            return $this->notFound('لم أجد سلسلة ظاهرة بهذا المعرّف.');
        }

        $access = app(CreatorPremiumAccess::class);
        $isPremium = $access->seriesIsPremium($series);
        $isOwnSeries = (int) CreatorProfile::query()->whereKey($series->creator_profile_id)->value('user_id') === (int) $context->user->getKey();

        $state = match (true) {
            ! $isPremium => 'free',
            $isOwnSeries => 'creator',
            $access->ownsSeries($context->user, $series) => 'purchased',
            default => 'not_purchased',
        };

        $path = Presenter::path('series.show', ['series' => $series->public_id]);

        return $this->ok(
            ['series' => Presenter::text($series->publishedRevision?->title, 120), 'is_premium' => $isPremium, 'my_access' => $state, 'path' => $path],
            array_values(array_filter([Presenter::link('صفحة السلسلة', $path)])),
            ['series'],
        );
    }
}
