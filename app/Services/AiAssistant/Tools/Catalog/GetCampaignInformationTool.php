<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\Campaign;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\CampaignProgressService;
use Illuminate\Support\Facades\DB;

/** E45 - get_campaign_information: الحملات المتاحة أو حملة بمعرّفها: العنوان والوصف والنافذة وعدد المراحل والخطوات. لا محتوى خطوات ولا أحجيات. */
final class GetCampaignInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_campaign_information';
    }

    public function description(): string
    {
        return 'List the currently available campaigns, or get one campaign by slug: title, description, window, number of stages and steps, page link. Never returns puzzle content.';
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Optional campaign slug.', 'maxLength' => 100]];
    }

    public function area(): string
    {
        return 'campaigns';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $progress = app(CampaignProgressService::class);

        if (isset($arguments['slug'])) {
            $campaign = Campaign::query()->where('slug', (string) $arguments['slug'])->first();

            if ($campaign === null || ! $progress->isCampaignAvailable($campaign)) {
                return $this->notFound('لم أجد حملة متاحة بهذا المعرّف.');
            }

            $campaigns = collect([$campaign]);
        } else {
            $campaigns = Campaign::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $context->now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $context->now))
                ->latest()->limit(8)->get();
        }

        if ($campaigns->isEmpty()) {
            return $this->ok(['campaigns' => [], 'note' => 'No campaign is available right now.']);
        }

        $ids = $campaigns->pluck('id')->all();
        $stages = DB::table('campaign_stages')->whereIn('campaign_id', $ids)->selectRaw('campaign_id, count(*) as c')->groupBy('campaign_id')->pluck('c', 'campaign_id');
        $steps = DB::table('campaign_steps as s')->join('campaign_gates as g', 'g.id', '=', 's.campaign_gate_id')->join('campaign_stages as st', 'st.id', '=', 'g.campaign_stage_id')
            ->whereIn('st.campaign_id', $ids)->selectRaw('st.campaign_id as campaign_id, count(*) as c')->groupBy('st.campaign_id')->pluck('c', 'campaign_id');

        $rows = $campaigns->map(fn (Campaign $c) => [
            'slug' => $c->slug,
            'title' => Presenter::text($c->title, 120),
            'description' => Presenter::text($c->description, 240),
            'starts_at' => Presenter::date($c->starts_at),
            'ends_at' => Presenter::date($c->ends_at),
            'stages_count' => (int) ($stages[$c->id] ?? 0),
            'steps_count' => (int) ($steps[$c->id] ?? 0),
            'path' => Presenter::path('campaigns.show', ['campaign' => $c->slug]),
        ])->values()->all();

        $sources = array_values(array_filter([Presenter::link('صفحة الحملة', $rows[0]['path'] ?? null)]));

        return $this->ok(['campaigns' => $rows], $sources, ['title', 'description']);
    }
}
