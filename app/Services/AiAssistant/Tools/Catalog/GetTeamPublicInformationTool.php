<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\Team;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_team_public_information: فرق الدليل العام فقط (عامة ومفعَّلة). لا أعضاء ولا محادثات ولا دعوات ولا فرق خاصة. */
final class GetTeamPublicInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_team_public_information';
    }

    public function description(): string
    {
        return 'Get public information about teams in the public team directory: name, description, number of members, capacity, how to join. Never returns member lists, chats or private teams.';
    }

    public function properties(): array
    {
        return [
            'slug' => ['type' => 'string', 'description' => 'Optional team slug.', 'maxLength' => 100],
            'name' => ['type' => 'string', 'description' => 'Optional part of the team name.', 'maxLength' => 60],
        ];
    }

    public function area(): string
    {
        return 'teams';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $query = Team::query()->directory();

        if (isset($arguments['slug'])) {
            $query->where('slug', (string) $arguments['slug']);
        } elseif (isset($arguments['name'])) {
            $needle = '%'.addcslashes((string) $arguments['name'], '%_\\').'%';
            $query->where('name', 'like', $needle);
        }

        $teams = $query->orderByDesc('members_count')->orderBy('id')->limit(6)->get(['id', 'name', 'slug', 'description', 'join_policy', 'max_members', 'members_count']);

        if ($teams->isEmpty()) {
            return $this->notFound('لم أجد فريقًا عامًا مطابقًا في دليل الفرق.');
        }

        $labels = [Team::JOIN_OPEN => 'انضمام مفتوح', Team::JOIN_REQUEST => 'الانضمام بطلب', Team::JOIN_INVITE_ONLY => 'بدعوة فقط'];

        $rows = $teams->map(fn (Team $t) => [
            'name' => Presenter::text($t->name, 80),
            'description' => Presenter::text($t->description, 240),
            'members_count' => (int) $t->members_count,
            'max_members' => (int) $t->max_members,
            'join_policy' => $labels[$t->join_policy] ?? 'غير محدد',
            'path' => Presenter::path('teams.show', ['team' => $t->slug]),
        ])->all();

        return $this->ok(['teams' => $rows], array_values(array_filter([Presenter::link('دليل الفرق', Presenter::path('teams.index')), Presenter::link('صفحة الفريق', $rows[0]['path'] ?? null)])), ['name', 'description']);
    }
}
