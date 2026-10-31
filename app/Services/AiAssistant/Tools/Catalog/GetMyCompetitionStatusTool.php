<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventParticipant;
use App\Models\CompetitiveEventResult;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_my_competition_status: مشاركات المستخدم نفسه في المنافسات العامة (تسجيل/إكمال) ونتيجته النهائية بعد الاعتماد فقط. لا نتائج الآخرين ولا الأحجية ولا صحة المحاولة. */
final class GetMyCompetitionStatusTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_competition_status';
    }

    public function description(): string
    {
        return "Get the signed-in member's own participation in public competitions (registered or completed) and the final rank after results are approved. Optionally for one competition slug.";
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Optional competition slug.', 'maxLength' => 120]];
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'competitions';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $user = $context->user;
        $query = CompetitiveEventParticipant::query()->where('user_id', $user->getKey())
            ->whereHas('event', function ($q) use ($arguments) {
                $q->public();

                if (isset($arguments['slug'])) {
                    $q->where('slug', (string) $arguments['slug']);
                }
            })
            ->with('event:id,title,slug,status,starts_at,ends_at')->orderByDesc('registered_at')->limit(5);

        $participations = $query->get(['id', 'competitive_event_id', 'user_id', 'status', 'registered_at', 'completed_at']);

        if ($participations->isEmpty()) {
            return $this->ok(['participations' => [], 'note' => 'You have not registered in any public competition yet.'], array_values(array_filter([Presenter::link('المنافسات', Presenter::path('competitions.index'))])));
        }

        $ranks = CompetitiveEventResult::query()->where('user_id', $user->getKey())->whereIn('competitive_event_id', $participations->pluck('competitive_event_id'))
            ->whereNotNull('final_rank')->pluck('final_rank', 'competitive_event_id');

        $rows = $participations->map(function (CompetitiveEventParticipant $p) use ($ranks, $context) {
            /** @var CompetitiveEvent $event */
            $event = $p->event;
            $approved = $event->status === CompetitiveEvent::STATUS_COMPLETED;

            return [
                'competition' => Presenter::text($event->title, 120),
                'phase' => $event->phase($context->now),
                'my_status' => $p->status === CompetitiveEventParticipant::STATUS_COMPLETED ? 'completed' : 'registered',
                'registered_at' => Presenter::date($p->registered_at),
                'completed_at' => Presenter::date($p->completed_at),
                'final_rank' => $approved ? ($ranks[$p->competitive_event_id] ?? null) : null,
                'path' => Presenter::path('competitions.show', ['event' => $event->slug]),
            ];
        })->all();

        return $this->ok(['participations' => $rows], array_values(array_filter([Presenter::link('صفحة المنافسة', $rows[0]['path'] ?? null)])), ['competition']);
    }
}
