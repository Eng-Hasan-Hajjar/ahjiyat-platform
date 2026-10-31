<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\CreatorProfile;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use Illuminate\Support\Facades\DB;

/** E45 - get_my_creator_status: حالة طلب/ملف المبدع للمستخدم نفسه وعدد أعماله المنشورة ومتابعيه. لا ملاحظات المراجعة ولا أرباح ولا مسودات. */
final class GetMyCreatorStatusTool extends AiTool
{
    private const LABELS = [
        CreatorProfile::STATUS_PENDING => 'قيد المراجعة',
        CreatorProfile::STATUS_APPROVED => 'معتمَد',
        CreatorProfile::STATUS_SUSPENDED => 'موقوف',
        CreatorProfile::STATUS_REJECTED => 'مرفوض',
    ];

    public function name(): string
    {
        return 'get_my_creator_status';
    }

    public function description(): string
    {
        return "Get the signed-in member's own creator status (not applied, pending, approved, suspended, rejected), followers count and number of published puzzles. No review notes, no earnings.";
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'creators';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $profile = CreatorProfile::query()->where('user_id', $context->user->getKey())->first(['id', 'slug', 'status', 'followers_count', 'approved_at']);

        if ($profile === null) {
            return $this->ok(['applied' => false, 'note' => 'You have not applied to become a creator.'], array_values(array_filter([Presenter::link('التقديم كمبدع', Presenter::path('creator.apply'))])));
        }

        $approved = $profile->status === CreatorProfile::STATUS_APPROVED;
        $works = $approved
            ? DB::table('creator_puzzles')->where('creator_profile_id', $profile->id)->where('is_visible', true)->whereNotNull('published_revision_id')->whereNull('archived_at')->whereNull('hidden_at')->count()
            : 0;

        return $this->ok(
            [
                'applied' => true,
                'status' => $profile->status,
                'status_label' => self::LABELS[$profile->status] ?? $profile->status,
                'approved_at' => Presenter::date($profile->approved_at, false),
                'followers_count' => $approved ? (int) $profile->followers_count : null,
                'published_puzzles' => $approved ? (int) $works : null,
            ],
            array_values(array_filter([
                Presenter::link($approved ? 'لوحة المبدع' : 'طلب المبدع', Presenter::path($approved ? 'creator.dashboard' : 'creator.apply')),
                $approved ? Presenter::link('صفحتي العامة', Presenter::path('creators.show', ['creator' => $profile->slug])) : null,
            ])),
        );
    }
}
