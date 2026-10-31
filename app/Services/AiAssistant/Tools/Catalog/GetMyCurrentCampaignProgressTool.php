<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\Campaign;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\CampaignSnapshot;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\SeasonPresenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\CampaignProgressService;

/** E45 - get_my_current_campaign_progress: عدد خطوات المستخدم المكتملة في حملة (بمعرّفها، وإلا حملة الموسم المباشر، وإلا أول حملة متاحة). أعداد فقط: لا خطوات ولا أحجيات ولا ردود. */
final class GetMyCurrentCampaignProgressTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_current_campaign_progress';
    }

    public function description(): string
    {
        return "Get the signed-in member's own progress in a campaign (default: the live season's campaign): completed steps out of total and percentage. Counts only, never puzzle content.";
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Optional campaign slug.', 'maxLength' => 100]];
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'campaigns';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $availability = app(CampaignProgressService::class);

        if (isset($arguments['slug'])) {
            $campaign = Campaign::query()->where('slug', (string) $arguments['slug'])->first();
        } else {
            $campaign = app(SeasonPresenter::class)->currentLive($context->now)?->campaign
                ?? Campaign::query()->where('is_active', true)->latest()->limit(8)->get()->first(fn (Campaign $c) => $availability->isCampaignAvailable($c));
        }

        if ($campaign === null || ! $availability->isCampaignAvailable($campaign)) {
            return $this->notFound('لا توجد حملة متاحة مطابقة الآن.');
        }

        $path = Presenter::path('campaigns.show', ['campaign' => $campaign->slug]);

        return $this->ok(
            ['campaign' => Presenter::text($campaign->title, 120)] + CampaignSnapshot::for($context->user, $campaign) + ['path' => $path],
            array_values(array_filter([Presenter::link('صفحة الحملة', $path)])),
            ['campaign'],
        );
    }
}
