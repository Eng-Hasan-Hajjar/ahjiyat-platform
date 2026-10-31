<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CreatorProfile;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use Illuminate\Support\Facades\DB;

/** E45 - get_creator_public_information: صفحة مبدع معتمَد عامة فقط (الاسم والنبذة والمتابعون وعدد الأعمال المنشورة). لا بريد ولا مراجعة ولا أرباح ولا حل أعمال. */
final class GetCreatorPublicInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_creator_public_information';
    }

    public function description(): string
    {
        return 'Get the public profile of an approved creator by slug: display name, short bio, followers count, number of published puzzles, page link.';
    }

    public function properties(): array
    {
        return ['slug' => ['type' => 'string', 'description' => 'Creator slug (as in /creators/{slug}).', 'maxLength' => 100]];
    }

    public function required(): array
    {
        return ['slug'];
    }

    public function area(): string
    {
        return 'creators';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $row = DB::table('creator_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.slug', (string) $arguments['slug'])->where('p.status', CreatorProfile::STATUS_APPROVED)->where('u.is_frozen', false)
            ->first(['p.id', 'p.slug', 'p.bio', 'p.followers_count', 'u.name']);

        if ($row === null) {
            return $this->notFound('لم أجد مبدعًا معتمَدًا بهذا المعرّف.');
        }

        $works = DB::table('creator_puzzles')->where('creator_profile_id', $row->id)->where('is_visible', true)
            ->whereNotNull('published_revision_id')->whereNull('archived_at')->whereNull('hidden_at')->count();

        $path = Presenter::path('creators.show', ['creator' => $row->slug]);

        return $this->ok(
            ['creator' => ['name' => Presenter::text($row->name, 80), 'bio' => Presenter::text($row->bio, 280), 'followers_count' => (int) $row->followers_count, 'published_puzzles' => (int) $works, 'path' => $path]],
            array_values(array_filter([Presenter::link('صفحة المبدع', $path)])),
            ['name', 'bio'],
        );
    }
}
