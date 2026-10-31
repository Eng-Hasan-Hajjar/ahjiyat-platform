<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CompetitiveEvent;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_competition_information: المنافسات العامة (قادمة/جارية/منتهية حديثًا) أو منافسة بمعرّفها: المرحلة والنوافذ والتسجيل والعدد. لا أحجية ولا إجابات ولا نتائج لاعبين. */
final class GetCompetitionInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_competition_information';
    }

    public function description(): string
    {
        return 'List public competitions, or get one by slug: phase (upcoming/live/ended), dates, registration window and whether registration is open, participants count. Never returns the puzzle or answers.';
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Optional competition slug.', 'maxLength' => 120]];
    }

    public function area(): string
    {
        return 'competitions';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $query = CompetitiveEvent::query()->public();

        if (isset($arguments['slug'])) {
            $events = $query->where('slug', (string) $arguments['slug'])->limit(1)->get();

            if ($events->isEmpty()) {
                return $this->notFound('لم أجد منافسة عامة بهذا المعرّف.');
            }
        } else {
            $events = $query->orderByDesc('starts_at')->limit(12)->get()
                ->sortBy(fn (CompetitiveEvent $e) => [in_array($e->phase($context->now), [CompetitiveEvent::PHASE_ENDED, CompetitiveEvent::PHASE_COMPLETED], true) ? 1 : 0, $e->starts_at?->getTimestamp()])->take(6)->values();
        }

        if ($events->isEmpty()) {
            return $this->ok(['competitions' => [], 'note' => 'No public competition right now.']);
        }

        $rows = $events->map(fn (CompetitiveEvent $e) => [
            'slug' => $e->slug,
            'title' => Presenter::text($e->title, 120),
            'description' => Presenter::text($e->description, 240),
            'phase' => $e->phase($context->now),
            'starts_at' => Presenter::date($e->starts_at),
            'ends_at' => Presenter::date($e->ends_at),
            'registration_open' => $e->registrationOpen($context->now),
            'registration_starts_at' => Presenter::date($e->registration_starts_at),
            'registration_ends_at' => Presenter::date($e->registration_ends_at),
            'participants_count' => (int) $e->participants_count,
            'max_participants' => $e->max_participants,
            'is_full' => $e->isFull(),
            'path' => Presenter::path('competitions.show', ['event' => $e->slug]),
        ])->all();

        return $this->ok(['competitions' => $rows], array_values(array_filter([Presenter::link('صفحة المنافسة', $rows[0]['path'] ?? null)])), ['title', 'description']);
    }
}
